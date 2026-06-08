<?php

declare(strict_types=1);

namespace Hn\Agent\Service;

use Hn\McpServer\MCP\ToolRegistry;
use Mcp\Types\ImageContent;
use Mcp\Types\TextContent;

class ToolConverterService
{
    /**
     * Convert MCP tools from the ToolRegistry to OpenAI function calling format.
     *
     * @return array<int, array{type: string, function: array{name: string, description: string, parameters: array}}>
     */
    public function convertTools(ToolRegistry $toolRegistry): array
    {
        $tools = [];
        foreach ($toolRegistry->getTools() as $tool) {
            $schema = $tool->getSchema();
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->getName(),
                    'description' => $schema['description'] ?? '',
                    'parameters' => $schema['inputSchema'] ?? ['type' => 'object', 'properties' => new \stdClass()],
                ],
            ];
        }
        return $tools;
    }

    /**
     * Execute a tool call via the ToolRegistry and return a structured result.
     *
     * Returns:
     *  - `text`: Concatenated text portion of the result. The persistent
     *    tool-message `content` for the LLM/UI.
     *  - `media`: List of inline media blocks for tools that returned
     *    `ImageContent` (or other binary-bearing content) — empty for most
     *    tools. Each entry is `['mime' => string, 'data' => base64string,
     *    'filename' => ?string]`. Consumed by AgentService and serializeForLlm
     *    to build OpenAI/OpenRouter-compatible content blocks.
     *
     * On error, returns the error message as the `text` (so the LLM can see it)
     * and an empty media list.
     *
     * @return array{text: string, media: list<array{mime: string, data: string, filename?: string}>}
     */
    public function executeToolCall(ToolRegistry $toolRegistry, string $name, string|array $arguments): array
    {
        try {
            if (is_string($arguments)) {
                $arguments = json_decode($arguments, true) ?? [];
            }

            $tool = $toolRegistry->getTool($name);
            if ($tool === null) {
                return ['text' => 'Error: Tool "' . $name . '" not found.', 'media' => []];
            }

            $result = $tool->execute($arguments);

            $parts = [];
            $media = [];
            foreach ($result->content as $content) {
                if ($content instanceof TextContent) {
                    $parts[] = $content->text;
                } elseif ($content instanceof ImageContent) {
                    $media[] = [
                        'mime' => $content->mimeType,
                        'data' => $content->data,
                    ];
                } else {
                    $parts[] = json_encode($content->jsonSerialize());
                }
            }

            return ['text' => implode("\n", $parts), 'media' => $media];
        } catch (\Throwable $e) {
            return ['text' => 'Error executing tool "' . $name . '": ' . $e->getMessage(), 'media' => []];
        }
    }
}
