<?php

declare(strict_types=1);

namespace Hn\Agent\Service;

use Hn\McpServer\MCP\ToolRegistry;
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
     * Execute a tool call via the ToolRegistry and return the result as a string.
     *
     * On error, returns the error message as a string (so the LLM can see it).
     */
    public function executeToolCall(ToolRegistry $toolRegistry, string $name, string|array $arguments): string
    {
        try {
            if (is_string($arguments)) {
                $arguments = json_decode($arguments, true) ?? [];
            }

            $tool = $toolRegistry->getTool($name);
            if ($tool === null) {
                return 'Error: Tool "' . $name . '" not found.';
            }

            $result = $tool->execute($arguments);

            $parts = [];
            foreach ($result->content as $content) {
                if ($content instanceof TextContent) {
                    $parts[] = $content->text;
                } else {
                    $parts[] = json_encode($content->jsonSerialize());
                }
            }

            return implode("\n", $parts);
        } catch (\Throwable $e) {
            return 'Error executing tool "' . $name . '": ' . $e->getMessage();
        }
    }
}
