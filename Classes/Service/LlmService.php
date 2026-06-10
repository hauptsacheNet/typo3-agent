<?php

declare(strict_types=1);

namespace Hn\Agent\Service;

use Hn\Agent\Service\Llm\MessageBagAdapter;
use Symfony\AI\Platform\Bridge\Generic\Factory as GenericPlatformFactory;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;
use Symfony\Component\HttpClient\HttpClient;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Talks to an OpenAI-compatible LLM API (OpenRouter by default) through the
 * Symfony AI Platform component. The custom HTTP/SSE handling that used to live
 * here is now provided by Symfony AI; this service only adapts between the
 * project's OpenAI-format message arrays and the Platform's object model.
 *
 * The public interface (chatCompletion / chatCompletionStream and their array
 * shapes) is unchanged, so AgentService and the persisted message format are
 * untouched.
 */
class LlmService
{
    private ?PlatformInterface $platform = null;
    private string $model = '';

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly MessageBagAdapter $messageBagAdapter,
    ) {}

    /**
     * Send a chat completion request to the LLM.
     *
     * @param array $messages The messages array (OpenAI format)
     * @param array $tools    The tools array (OpenAI function calling format)
     * @return array The assistant message (role + content + tool_calls)
     * @throws \RuntimeException on configuration or API errors
     */
    public function chatCompletion(array $messages, array $tools = []): array
    {
        $platform = $this->getPlatform();
        $bag = $this->messageBagAdapter->toMessageBag($messages);

        try {
            $result = $platform->invoke($this->model, $bag, $this->buildOptions($tools, false))->getResult();
        } catch (\Throwable $e) {
            throw $this->wrapException($e);
        }

        return $this->messageBagAdapter->resultToAssistantArray($result);
    }

    /**
     * Streaming variant: consumes the platform's delta stream chunk-by-chunk.
     * For each delta the optional $onDelta callback is invoked. The fully
     * aggregated assistant message is returned with the same shape as
     * chatCompletion().
     *
     * $onDelta signature: (string $deltaType, array $payload): void where
     * $deltaType is one of 'content', 'tool_call', 'finish'.
     *
     * @param callable(string, array): void|null $onDelta
     * @return array Aggregated assistant message (role + content + tool_calls)
     * @throws \RuntimeException on configuration or API errors
     */
    public function chatCompletionStream(
        array $messages,
        array $tools = [],
        ?callable $onDelta = null,
    ): array {
        $platform = $this->getPlatform();
        $bag = $this->messageBagAdapter->toMessageBag($messages);
        $onDelta ??= static function (string $deltaType, array $payload): void {};

        try {
            $deferred = $platform->invoke($this->model, $bag, $this->buildOptions($tools, true));
            return $this->aggregateStream($deferred->asStream(), $onDelta);
        } catch (\Throwable $e) {
            throw $this->wrapException($e);
        }
    }

    /**
     * Aggregate a stream of Symfony AI deltas into one assistant message,
     * invoking $onDelta for each meaningful chunk. The OpenAI-compatible bridge
     * emits incremental tool-call deltas (ToolCallStart + ToolInputDelta) plus a
     * final ToolCallComplete, so the per-fragment 'tool_call' events the chat UI
     * relies on are preserved. ToolCallComplete is the authoritative source for
     * the aggregated tool_calls.
     *
     * Visibility is protected so unit tests can exercise the aggregator directly
     * with fabricated deltas, without needing live HTTP.
     *
     * @param iterable<object> $deltas
     * @param callable(string $deltaType, array $payload): void $onDelta
     * @return array{role: 'assistant', content: string|null, tool_calls?: array<int, array<string, mixed>>}
     */
    protected function aggregateStream(iterable $deltas, callable $onDelta): array
    {
        $contentParts = [];
        $finalToolCalls = null;
        /** @var array<string, int> $indexById */
        $indexById = [];

        foreach ($deltas as $delta) {
            if ($delta instanceof TextDelta) {
                $text = $delta->getText();
                if ($text !== '') {
                    $contentParts[] = $text;
                    $onDelta('content', ['text' => $text]);
                }
            } elseif ($delta instanceof ToolCallStart) {
                $id = $delta->getId();
                $index = $indexById[$id] ??= \count($indexById);
                $onDelta('tool_call', ['index' => $index, 'id' => $id, 'name' => $delta->getName()]);
            } elseif ($delta instanceof ToolInputDelta) {
                $id = $delta->getId();
                $index = $indexById[$id] ??= \count($indexById);
                $fragment = $delta->getPartialJson();
                if ($fragment !== '') {
                    $onDelta('tool_call', ['index' => $index, 'arguments' => $fragment]);
                }
            } elseif ($delta instanceof ToolCallComplete) {
                $finalToolCalls = $delta->getToolCalls();
            }
        }

        $message = [
            'role' => 'assistant',
            'content' => $contentParts === [] ? null : implode('', $contentParts),
        ];

        if ($finalToolCalls !== null && $finalToolCalls !== []) {
            $message['tool_calls'] = array_map(
                [$this->messageBagAdapter, 'toolCallToArray'],
                $finalToolCalls,
            );
        }

        $onDelta('finish', ['reason' => isset($message['tool_calls']) ? 'tool_calls' : 'stop']);

        return $message;
    }

    /**
     * @param array<int, array<string, mixed>> $tools
     * @return array<string, mixed>
     */
    private function buildOptions(array $tools, bool $stream): array
    {
        $options = [];
        if ($stream) {
            $options['stream'] = true;
        }
        if ($tools !== []) {
            $options['tools'] = $this->messageBagAdapter->toolsToObjects($tools);
        }
        return $options;
    }

    /**
     * Build (and memoize) the Platform from the extension configuration.
     *
     * The generic OpenAI-compatible bridge is used for every endpoint so the
     * same code path serves OpenRouter, OpenAI and any compatible API; the
     * configured apiUrl becomes the base URL and "/chat/completions" the path
     * (matching the previous direct-HTTP behaviour exactly).
     */
    private function getPlatform(): PlatformInterface
    {
        if ($this->platform !== null) {
            return $this->platform;
        }

        $config = $this->getConfig();
        $this->model = $config['model'];

        return $this->platform = GenericPlatformFactory::createPlatform(
            baseUrl: rtrim($config['apiUrl'], '/'),
            apiKey: $config['apiKey'],
            httpClient: HttpClient::create(['timeout' => 600]),
            supportsCompletions: true,
            supportsEmbeddings: false,
            completionsPath: '/chat/completions',
        );
    }

    private function wrapException(\Throwable $e): \RuntimeException
    {
        if ($e instanceof \RuntimeException) {
            return $e;
        }
        return new \RuntimeException('LLM API error: ' . $e->getMessage(), 0, $e);
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
