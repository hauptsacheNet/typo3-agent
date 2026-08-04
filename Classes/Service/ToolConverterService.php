<?php

declare(strict_types=1);

namespace Hn\Agent\Service;

use Hn\McpServer\MCP\ToolRegistry;
use Mcp\Types\ImageContent;
use Mcp\Types\TextContent;

class ToolConverterService
{
    public function __construct(
        private readonly AgentScratchStorage $scratchStorage,
    ) {}

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
     *  - `text`: Concatenated text portion of the result. Persisted as-is
     *    on the tool-message row.
     *  - `attachments`: sys_file UIDs for any binary content the tool
     *    produced (e.g. ImageContent from ReadFile). The binary bytes
     *    have already been persisted into the agent scratch storage
     *    (var/agent-scratch/) and linked via sys_file; the caller creates
     *    the sys_file_reference rows on the tool message.
     *
     * On error, returns the error message as the `text` (so the LLM can see it)
     * and an empty attachment list.
     *
     * @return array{text: string, attachments: list<int>}
     */
    public function executeToolCall(ToolRegistry $toolRegistry, string $name, string|array $arguments, int $taskUid = 0): array
    {
        try {
            if (is_string($arguments)) {
                $arguments = json_decode($arguments, true) ?? [];
            }

            $tool = $toolRegistry->getTool($name);
            if ($tool === null) {
                return ['text' => 'Error: Tool "' . $name . '" not found.', 'attachments' => []];
            }

            $result = $tool->execute($arguments);

            $parts = [];
            $attachments = [];
            foreach ($result->content as $content) {
                if ($content instanceof TextContent) {
                    $parts[] = $content->text;
                } elseif ($content instanceof ImageContent) {
                    $binary = base64_decode($content->data, true);
                    if ($binary === false) {
                        $parts[] = '[Tool returned an image, but its base64 payload could not be decoded.]';
                        continue;
                    }
                    $file = $this->scratchStorage->store(
                        taskUid: $taskUid,
                        binary: $binary,
                        mimeType: (string)$content->mimeType,
                    );
                    $attachments[] = $file->getUid();
                } else {
                    $parts[] = json_encode($content->jsonSerialize());
                }
            }

            return ['text' => implode("\n", $parts), 'attachments' => $attachments];
        } catch (\Throwable $e) {
            return ['text' => 'Error executing tool "' . $name . '": ' . $e->getMessage(), 'attachments' => []];
        }
    }
}
