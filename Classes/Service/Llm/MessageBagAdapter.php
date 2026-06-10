<?php

declare(strict_types=1);

namespace Hn\Agent\Service\Llm;

use Hn\Agent\Service\Llm\Content\DocumentContent;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Translates between the project's persisted/serialized OpenAI-format message
 * array and Symfony AI's typed Message/MessageBag objects (and back from a
 * platform Result into the aggregated assistant-message array the agent loop
 * expects).
 *
 * The OpenAI-format array stays the canonical wire/persistence shape — this
 * adapter is the only place that knows about Symfony AI's object model, so
 * AgentService, serializeForLlm() and the persisted tx_agent_task.messages
 * format remain unchanged.
 */
final class MessageBagAdapter
{
    /**
     * @param array<int, array<string, mixed>> $messages OpenAI-format messages (output of serializeForLlm)
     */
    public function toMessageBag(array $messages): MessageBag
    {
        $bag = new MessageBag();
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            switch ((string)($message['role'] ?? '')) {
                case 'system':
                    $bag->add(Message::forSystem((string)($message['content'] ?? '')));
                    break;
                case 'user':
                    $bag->add($this->buildUserMessage($message));
                    break;
                case 'assistant':
                    $bag->add($this->buildAssistantMessage($message));
                    break;
                case 'tool':
                    // Tool-result messages are string-only by API contract; only the
                    // id matters for the ToolCallMessage normalizer (-> tool_call_id).
                    $bag->add(Message::ofToolCall(
                        new ToolCall((string)($message['tool_call_id'] ?? ''), '', []),
                        (string)($message['content'] ?? ''),
                    ));
                    break;
            }
        }
        return $bag;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function buildUserMessage(array $message): UserMessage
    {
        $content = $message['content'] ?? '';

        if (is_string($content)) {
            return Message::ofUser($content);
        }
        if (!is_array($content)) {
            return Message::ofUser((string)$content);
        }

        $parts = [];
        foreach ($content as $block) {
            if (is_string($block)) {
                $parts[] = $block;
                continue;
            }
            if (!is_array($block)) {
                continue;
            }
            switch ((string)($block['type'] ?? '')) {
                case 'text':
                    $parts[] = (string)($block['text'] ?? '');
                    break;
                case 'image_url':
                    $url = (string)($block['image_url']['url'] ?? '');
                    if ($url !== '') {
                        $parts[] = Image::fromDataUrl($url);
                    }
                    break;
                case 'file':
                    $dataUrl = (string)($block['file']['file_data'] ?? '');
                    if ($dataUrl !== '') {
                        $parts[] = new DocumentContent(
                            (string)($block['file']['filename'] ?? 'attachment'),
                            $dataUrl,
                        );
                    }
                    break;
            }
        }

        if ($parts === []) {
            $parts = [''];
        }
        return Message::ofUser(...$parts);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function buildAssistantMessage(array $message): AssistantMessage
    {
        $parts = [];

        $content = $message['content'] ?? null;
        if (is_string($content) && $content !== '') {
            $parts[] = $content;
        }

        foreach (($message['tool_calls'] ?? []) as $toolCall) {
            if (!is_array($toolCall)) {
                continue;
            }
            $parts[] = new ToolCall(
                (string)($toolCall['id'] ?? ''),
                (string)($toolCall['function']['name'] ?? ''),
                $this->decodeArguments($toolCall['function']['arguments'] ?? '{}'),
            );
        }

        if ($parts === []) {
            $parts = [''];
        }
        return Message::ofAssistant(...$parts);
    }

    /**
     * Convert a platform Result into the aggregated assistant-message array the
     * agent loop appends to the conversation (same shape as the streaming path).
     *
     * @return array{role: 'assistant', content: string|null, tool_calls?: array<int, array<string, mixed>>}
     */
    public function resultToAssistantArray(ResultInterface $result): array
    {
        if ($result instanceof ToolCallResult) {
            return [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => array_map([$this, 'toolCallToArray'], $result->getContent()),
            ];
        }

        $content = $result->getContent();
        return [
            'role' => 'assistant',
            'content' => is_string($content) ? $content : (is_scalar($content) ? (string)$content : null),
        ];
    }

    /**
     * Render a Symfony AI ToolCall back into the OpenAI wire shape, with
     * arguments re-encoded as a JSON string (the persisted format).
     *
     * @return array{id: string, type: 'function', function: array{name: string, arguments: string}}
     */
    public function toolCallToArray(ToolCall $toolCall): array
    {
        $arguments = $toolCall->getArguments();
        return [
            'id' => $toolCall->getId(),
            'type' => 'function',
            'function' => [
                'name' => $toolCall->getName(),
                // Empty arguments must serialize to "{}" (object), not "[]".
                'arguments' => json_encode($arguments === [] ? new \stdClass() : $arguments, JSON_THROW_ON_ERROR),
            ],
        ];
    }

    /**
     * Convert the project's OpenAI-format tool definitions into Symfony AI Tool
     * objects so the registered ToolNormalizer emits them correctly (it passes
     * the JSON-schema parameters through verbatim, so an empty-properties
     * stdClass still becomes "{}").
     *
     * The ExecutionReference is required by the Tool constructor but never used
     * here — tools are executed by the agent loop via the MCP ToolRegistry, not
     * by Symfony AI's Toolbox.
     *
     * @param array<int, array<string, mixed>> $tools
     * @return array<int, Tool>
     */
    public function toolsToObjects(array $tools): array
    {
        $objects = [];
        foreach ($tools as $tool) {
            if (!is_array($tool)) {
                continue;
            }
            $function = $tool['function'] ?? [];
            $parameters = $function['parameters'] ?? null;
            $objects[] = new Tool(
                new ExecutionReference(self::class),
                (string)($function['name'] ?? ''),
                (string)($function['description'] ?? ''),
                is_array($parameters) ? $parameters : null,
            );
        }
        return $objects;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeArguments(mixed $arguments): array
    {
        if (is_array($arguments)) {
            return $arguments;
        }
        if (is_string($arguments) && $arguments !== '') {
            $decoded = json_decode($arguments, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }
}
