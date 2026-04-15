<?php

declare(strict_types=1);

namespace Hn\Agent\Service;

use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

class LlmService
{
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
    public function chatCompletion(array $messages, array $tools = []): array
    {
        $config = $this->getConfig();
        $apiUrl = rtrim($config['apiUrl'], '/') . '/chat/completions';

        $body = [
            'model' => $config['model'],
            'messages' => $messages,
        ];

        if (!empty($tools)) {
            $body['tools'] = $tools;
        }

        $response = $this->requestFactory->request($apiUrl, 'POST', [
            'headers' => [
                'Authorization' => 'Bearer ' . $config['apiKey'],
                'Content-Type' => 'application/json',
            ],
            'json' => $body,
            'connect_timeout' => 10,
            'timeout' => 120,
        ]);

        $statusCode = $response->getStatusCode();
        $responseBody = (string)$response->getBody();

        if ($statusCode < 200 || $statusCode >= 300) {
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
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(
                'LLM API returned HTTP ' . $statusCode . ': ' . (string)$response->getBody()
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
     * Resolve and validate extension configuration. Fills defaults.
     *
     * @return array{apiUrl: string, apiKey: string, model: string}
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
        ];
    }
}
