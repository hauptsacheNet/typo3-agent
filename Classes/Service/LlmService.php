<?php

declare(strict_types=1);

namespace Hn\Agent\Service;

use GuzzleHttp\Exception\BadResponseException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

class LlmService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * Send a chat completion request to the OpenAI-compatible LLM API.
     *
     * @param array $messages The messages array (OpenAI format)
     * @param array $tools    The tools array (OpenAI function calling format)
     * @return array The assistant message from choices[0].message
     * @throws \RuntimeException on HTTP or API errors
     */
    public function chatCompletion(array $messages, array $tools = [], ?string $modelOverride = null): array
    {
        $config = $this->getConfig();
        $apiUrl = rtrim($config['apiUrl'], '/') . '/chat/completions';

        $body = [
            'model' => $modelOverride ?? $config['model'],
            'messages' => $messages,
        ];

        if (!empty($tools)) {
            $body['tools'] = $tools;
        }

        $this->applyReasoning($body, $config);

        $response = $this->requestFactory->request($apiUrl, 'POST', [
            'headers' => [
                'Authorization' => 'Bearer ' . $config['apiKey'],
                'Content-Type' => 'application/json',
            ],
            'json' => $body,
            'connect_timeout' => 10,
            'timeout' => 120,
            'http_errors' => false,
        ]);

        $statusCode = $response->getStatusCode();
        $responseBody = (string)$response->getBody();

        if ($statusCode < 200 || $statusCode >= 300) {
            $this->logApiFailure($apiUrl, $statusCode, $response, $body, $responseBody);
            throw new \RuntimeException(
                'LLM API returned HTTP ' . $statusCode . ': ' . $responseBody
            );
        }

        $data = json_decode($responseBody, true);
        if (!is_array($data)) {
            throw new \RuntimeException('LLM API returned invalid JSON: ' . $responseBody);
        }

        $message = $data['choices'][0]['message'] ?? null;
        if (!is_array($message)) {
            throw new \RuntimeException('LLM API response missing choices[0].message: ' . $responseBody);
        }

        return $message;
    }

    /**
     * Streaming variant: sends the request with "stream": true and consumes
     * the SSE response chunk-by-chunk. For each delta, the optional $onDelta
     * callback is invoked. The fully aggregated assistant message is returned
     * with the same shape as chatCompletion().
     *
     * $onDelta signature: (string $deltaType, array $payload): void where
     * $deltaType is one of 'content', 'tool_call', 'finish'.
     *
     * @param callable(string, array): void|null $onDelta
     * @return array Aggregated assistant message (role + content + tool_calls)
     * @throws \RuntimeException on HTTP or API errors
     */
    public function chatCompletionStream(
        array $messages,
        array $tools = [],
        ?callable $onDelta = null,
    ): array {
        $config = $this->getConfig();
        $apiUrl = rtrim($config['apiUrl'], '/') . '/chat/completions';

        $body = [
            'model' => $config['model'],
            'messages' => $messages,
            'stream' => true,
        ];

        if (!empty($tools)) {
            $body['tools'] = $tools;
        }

        $this->applyReasoning($body, $config);

        try {
            $response = $this->requestFactory->request($apiUrl, 'POST', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $config['apiKey'],
                    'Content-Type' => 'application/json',
                    'Accept' => 'text/event-stream',
                ],
                'json' => $body,
                'stream' => true,
                'connect_timeout' => 10,
                'read_timeout' => 60,
                'http_errors' => false,
            ]);
        } catch (BadResponseException $e) {
            $errResp = $e->getResponse();
            $errBody = (string)$errResp->getBody();
            $this->logApiFailure($apiUrl, $errResp->getStatusCode(), $errResp, $body, $errBody);
            throw new \RuntimeException(
                'LLM API returned HTTP ' . $errResp->getStatusCode() . ': ' . $errBody,
                0,
                $e,
            );
        }

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            $errBody = (string)$response->getBody();
            $this->logApiFailure($apiUrl, $statusCode, $response, $body, $errBody);
            throw new \RuntimeException(
                'LLM API returned HTTP ' . $statusCode . ': ' . $errBody
            );
        }

        return $this->parseSseStream(
            $response->getBody(),
            $onDelta ?? static function (string $deltaType, array $payload): void {},
        );
    }

    /**
     * Parse an OpenAI-compatible SSE stream, invoke $onDelta for each delta,
     * and return the aggregated assistant message.
     *
     * Visibility is protected so unit tests can exercise the parser directly
     * via a test-only subclass, without needing live HTTP.
     *
     * @param StreamInterface $body
     * @param callable(string $deltaType, array $payload): void $onDelta
     * @return array{role: string, content: string|null, tool_calls?: array}
     */
    protected function parseSseStream(StreamInterface $body, callable $onDelta): array
    {
        $buffer = '';
        $contentParts = [];
        $reasoningParts = [];
        /** @var array<int, array<string, mixed>> $reasoningDetails Keyed by index — merged block per index */
        $reasoningDetails = [];
        $nextReasoningIndex = 0;
        /** @var array<int, array{id?: string, type: string, function: array{name?: string, arguments: string}}> $toolCalls */
        $toolCalls = [];
        $finishReason = null;
        $done = false;

        while (!$done && !$body->eof()) {
            $chunk = $body->read(8192);
            if ($chunk === '') {
                // read() may return '' on a transient pause; loop again until eof()
                continue;
            }
            $buffer .= $chunk;

            // SSE blocks are separated by blank lines. A block may contain
            // one or more "data: ..." lines (and optionally "event: ...").
            while (($sepPos = $this->findBlockSeparator($buffer)) !== null) {
                $block = substr($buffer, 0, $sepPos);
                $buffer = substr($buffer, $sepPos + ($buffer[$sepPos] === "\r" ? 4 : 2));

                $data = $this->extractDataFromBlock($block);
                if ($data === null) {
                    continue;
                }
                if ($data === '[DONE]') {
                    $done = true;
                    break;
                }

                $decoded = json_decode($data, true);
                if (!is_array($decoded)) {
                    continue;
                }

                $choice = $decoded['choices'][0] ?? null;
                if (!is_array($choice)) {
                    continue;
                }

                $delta = $choice['delta'] ?? [];

                if (isset($delta['content']) && is_string($delta['content']) && $delta['content'] !== '') {
                    $contentParts[] = $delta['content'];
                    $onDelta('content', ['text' => $delta['content']]);
                }

                if (isset($delta['reasoning']) && is_string($delta['reasoning']) && $delta['reasoning'] !== '') {
                    $reasoningParts[] = $delta['reasoning'];
                    $onDelta('reasoning', ['text' => $delta['reasoning']]);
                }

                if (isset($delta['reasoning_details']) && is_array($delta['reasoning_details'])) {
                    foreach ($delta['reasoning_details'] as $detail) {
                        if (!is_array($detail)) {
                            continue;
                        }
                        // Identify the block: explicit `index` when provided, otherwise
                        // assume continuation of the current block (Anthropic typically
                        // streams a single thinking block per turn).
                        if (array_key_exists('index', $detail) && is_int($detail['index'])) {
                            $idx = $detail['index'];
                        } elseif ($reasoningDetails !== []) {
                            $idx = array_key_last($reasoningDetails);
                        } else {
                            $idx = $nextReasoningIndex;
                        }
                        if (!isset($reasoningDetails[$idx])) {
                            $reasoningDetails[$idx] = [];
                            $nextReasoningIndex = $idx + 1;
                        }
                        foreach ($detail as $key => $value) {
                            if ($key === 'index') {
                                continue;
                            }
                            // text/summary are streamed incrementally → concatenate.
                            // signature/id/type/format/data arrive complete → overwrite.
                            if (($key === 'text' || $key === 'summary') && is_string($value)) {
                                $reasoningDetails[$idx][$key] = ($reasoningDetails[$idx][$key] ?? '') . $value;
                            } else {
                                $reasoningDetails[$idx][$key] = $value;
                            }
                        }
                    }
                }

                if (isset($delta['tool_calls']) && is_array($delta['tool_calls'])) {
                    foreach ($delta['tool_calls'] as $tcDelta) {
                        if (!is_array($tcDelta)) {
                            continue;
                        }
                        $index = (int)($tcDelta['index'] ?? 0);

                        if (!isset($toolCalls[$index])) {
                            $toolCalls[$index] = [
                                'type' => 'function',
                                'function' => ['name' => '', 'arguments' => ''],
                            ];
                        }

                        $payload = ['index' => $index];

                        if (isset($tcDelta['id']) && is_string($tcDelta['id']) && $tcDelta['id'] !== '') {
                            $toolCalls[$index]['id'] = $tcDelta['id'];
                            $payload['id'] = $tcDelta['id'];
                        }
                        if (isset($tcDelta['type']) && is_string($tcDelta['type'])) {
                            $toolCalls[$index]['type'] = $tcDelta['type'];
                        }

                        $fnDelta = $tcDelta['function'] ?? [];
                        if (isset($fnDelta['name']) && is_string($fnDelta['name']) && $fnDelta['name'] !== '') {
                            $toolCalls[$index]['function']['name'] = $fnDelta['name'];
                            $payload['name'] = $fnDelta['name'];
                        }
                        if (isset($fnDelta['arguments']) && is_string($fnDelta['arguments']) && $fnDelta['arguments'] !== '') {
                            $toolCalls[$index]['function']['arguments'] .= $fnDelta['arguments'];
                            $payload['arguments'] = $fnDelta['arguments'];
                        }

                        if (count($payload) > 1) {
                            $onDelta('tool_call', $payload);
                        }
                    }
                }

                if (isset($choice['finish_reason']) && is_string($choice['finish_reason']) && $choice['finish_reason'] !== '') {
                    $finishReason = $choice['finish_reason'];
                }
            }
        }

        if ($finishReason !== null) {
            $onDelta('finish', ['reason' => $finishReason]);
        }

        $message = [
            'role' => 'assistant',
            'content' => $contentParts === [] ? null : implode('', $contentParts),
        ];

        if ($reasoningParts !== []) {
            $message['reasoning'] = implode('', $reasoningParts);
        }
        if ($reasoningDetails !== []) {
            ksort($reasoningDetails);
            $message['reasoning_details'] = array_values($reasoningDetails);
        }

        if ($toolCalls !== []) {
            // Re-index numerically and ensure required shape
            ksort($toolCalls);
            $message['tool_calls'] = array_values($toolCalls);
        }

        return $message;
    }

    /**
     * Find the position of the next SSE block separator (\n\n or \r\n\r\n) in
     * $buffer. Returns the offset of the first newline of the separator, or null.
     */
    private function findBlockSeparator(string $buffer): ?int
    {
        $lf = strpos($buffer, "\n\n");
        $crlf = strpos($buffer, "\r\n\r\n");

        if ($lf === false && $crlf === false) {
            return null;
        }
        if ($lf === false) {
            return $crlf;
        }
        if ($crlf === false) {
            return $lf;
        }
        return min($lf, $crlf);
    }

    /**
     * Given an SSE block (one or more lines, no trailing blank line), return
     * the concatenated "data:" payload, or null if the block contains no data.
     *
     * Per SSE spec multiple data: lines in one event are joined with \n.
     */
    private function extractDataFromBlock(string $block): ?string
    {
        $dataLines = [];
        foreach (preg_split('/\r\n|\n|\r/', $block) ?: [] as $line) {
            if ($line === '' || str_starts_with($line, ':')) {
                continue;
            }
            if (str_starts_with($line, 'data:')) {
                // Per spec: strip a single leading space after the colon.
                $payload = substr($line, 5);
                if (str_starts_with($payload, ' ')) {
                    $payload = substr($payload, 1);
                }
                $dataLines[] = $payload;
            }
        }
        if ($dataLines === []) {
            return null;
        }
        return implode("\n", $dataLines);
    }

    /**
     * Inject OpenRouter's `reasoning` parameter into the request body when an
     * effort level is configured. Off / empty leaves the body unchanged so
     * provider defaults apply.
     *
     * @param array<string, mixed> $body
     * @param array{reasoningEffort: string} $config
     */
    private function applyReasoning(array &$body, array $config): void
    {
        $effort = strtolower(trim($config['reasoningEffort'] ?? ''));
        if ($effort === '' || $effort === 'off') {
            return;
        }
        if (!in_array($effort, ['low', 'medium', 'high'], true)) {
            return;
        }
        $body['reasoning'] = ['effort' => $effort];
    }

    /**
     * Resolve and validate extension configuration. Fills defaults.
     *
     * @return array{apiUrl: string, apiKey: string, model: string, reasoningEffort: string}
     */
    private function getConfig(): array
    {
        $config = $this->extensionConfiguration->get('agent');
        $apiKey = $config['apiKey'] ?? '';
        if ($apiKey === '') {
            throw new \RuntimeException('Agent extension: apiKey is not configured. Set it in Settings > Extension Configuration > agent.');
        }
        return [
            'apiUrl' => (string)($config['apiUrl'] ?? ''),
            'apiKey' => (string)$apiKey,
            'model' => (string)($config['model'] ?? 'anthropic/claude-haiku-4-5'),
            'reasoningEffort' => (string)($config['reasoningEffort'] ?? 'off'),
        ];
    }

    /**
     * Log full provider error detail next to a structured, base64-free
     * request summary. OpenRouter returns the provider's real message nested
     * in `error.metadata.raw` and is otherwise lost — the UI only sees the
     * exception message. The response body is capped so file logs stay sane.
     */
    private function logApiFailure(
        string $apiUrl,
        int $statusCode,
        ResponseInterface $response,
        array $requestBody,
        string $responseBody,
    ): void {
        if ($this->logger === null) {
            return;
        }
        $this->logger->error('LLM API request failed', [
            'url' => $apiUrl,
            'status' => $statusCode,
            'response_body' => substr($responseBody, 0, 8192),
            'response_headers' => $this->extractRelevantHeaders($response),
            'request' => $this->summarizeRequest($requestBody),
            'request_body' => $this->encodeRequestBody($requestBody),
        ]);
    }

    /**
     * Full JSON-encoded request body, capped at 4 MiB so the file log doesn't
     * blow up on 30 MiB PDFs (~40 MiB base64). Truncation is marked inline so
     * a downstream reader sees that bytes are missing.
     */
    private function encodeRequestBody(array $body): string
    {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false) {
            return '<json_encode failed: ' . json_last_error_msg() . '>';
        }
        $cap = 4 * 1024 * 1024;
        if (strlen($json) > $cap) {
            return substr($json, 0, $cap) . '...[truncated at ' . $cap . ' bytes; full length ' . strlen($json) . ']';
        }
        return $json;
    }

    /**
     * @return array<string, list<string>>
     */
    private function extractRelevantHeaders(ResponseInterface $response): array
    {
        $out = [];
        foreach ($response->getHeaders() as $name => $values) {
            $lower = strtolower($name);
            $relevant = $lower === 'x-request-id'
                || str_starts_with($lower, 'x-or-')
                || str_starts_with($lower, 'x-ratelimit-')
                || $lower === 'openai-organization'
                || $lower === 'cf-ray';
            if ($relevant) {
                $out[$name] = $values;
            }
        }
        return $out;
    }

    /**
     * Reduce the outgoing request body to a base64-free shape suitable for
     * a file log: per message its role + a compact content descriptor
     * (text length, file/image mime + bytes), plus tool_calls names and
     * the tools array length.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function summarizeRequest(array $body): array
    {
        $messages = [];
        foreach (($body['messages'] ?? []) as $index => $message) {
            if (!is_array($message)) {
                continue;
            }
            $summary = [
                'index' => $index,
                'role' => $message['role'] ?? '',
                'content' => $this->summarizeContent($message['content'] ?? null),
            ];
            if (isset($message['tool_call_id'])) {
                $summary['tool_call_id'] = (string)$message['tool_call_id'];
            }
            if (!empty($message['tool_calls']) && is_array($message['tool_calls'])) {
                $summary['tool_calls'] = array_values(array_map(
                    static fn ($tc): array => [
                        'id' => (string)($tc['id'] ?? ''),
                        'name' => (string)($tc['function']['name'] ?? ''),
                        'arguments_len' => strlen((string)($tc['function']['arguments'] ?? '')),
                    ],
                    array_filter($message['tool_calls'], 'is_array'),
                ));
            }
            $messages[] = $summary;
        }

        return [
            'model' => $body['model'] ?? null,
            'stream' => (bool)($body['stream'] ?? false),
            'tools_count' => is_array($body['tools'] ?? null) ? count($body['tools']) : 0,
            'messages' => $messages,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeContent(mixed $content): array
    {
        if ($content === null) {
            return ['kind' => 'null'];
        }
        if (is_string($content)) {
            return ['kind' => 'string', 'bytes' => strlen($content)];
        }
        if (!is_array($content)) {
            return ['kind' => 'other', 'php_type' => get_debug_type($content)];
        }
        $blocks = [];
        foreach ($content as $block) {
            if (!is_array($block)) {
                $blocks[] = ['type' => '?', 'php_type' => get_debug_type($block)];
                continue;
            }
            $type = (string)($block['type'] ?? '?');
            $entry = ['type' => $type];
            if ($type === 'text') {
                $entry['bytes'] = strlen((string)($block['text'] ?? ''));
            } elseif ($type === 'image_url') {
                $url = (string)($block['image_url']['url'] ?? '');
                $entry += $this->describeDataUri($url);
            } elseif ($type === 'file') {
                $entry['filename'] = (string)($block['file']['filename'] ?? '');
                $entry += $this->describeDataUri((string)($block['file']['file_data'] ?? ''));
            }
            $blocks[] = $entry;
        }
        return ['kind' => 'blocks', 'count' => count($blocks), 'blocks' => $blocks];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeDataUri(string $uri): array
    {
        if ($uri === '') {
            return ['data' => 'empty'];
        }
        if (!str_starts_with($uri, 'data:')) {
            return ['data' => 'url', 'bytes' => strlen($uri)];
        }
        $commaPos = strpos($uri, ',');
        if ($commaPos === false) {
            return ['data' => 'malformed_data_uri'];
        }
        $header = substr($uri, 5, $commaPos - 5);
        $mime = $header;
        $isBase64 = false;
        if (str_ends_with($header, ';base64')) {
            $mime = substr($header, 0, -7);
            $isBase64 = true;
        }
        $payloadLen = strlen($uri) - $commaPos - 1;
        $rawBytes = $isBase64 ? (int)floor($payloadLen * 3 / 4) : $payloadLen;
        return [
            'data' => $isBase64 ? 'base64' : 'inline',
            'mime' => $mime,
            'bytes' => $rawBytes,
        ];
    }
}
