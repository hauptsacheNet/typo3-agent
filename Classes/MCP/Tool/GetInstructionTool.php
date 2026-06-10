<?php

declare(strict_types=1);

namespace Hn\Agent\MCP\Tool;

use Hn\Agent\Domain\AgentInstructionRepository;
use Hn\Agent\Service\InstructionTextFormatter;
use Hn\McpServer\MCP\Tool\AbstractTool;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;

/**
 * On-demand retrieval of editor-maintained agent instructions
 * (tx_agent_instruction).
 *
 * The system prompt lists "on_demand" instructions as a short index (id + name
 * + "when to use"); this tool delivers the full body when the agent decides an
 * instruction is relevant — the progressive-disclosure half of the SKILL.md
 * model.
 *
 *  - `ids`   → return the full body of those instructions.
 *  - `query` → keyword search across name / hint / body, return matches' body.
 *  - neither → return the index of all available on-demand instructions, so
 *              the agent can pick the relevant id(s) and call again.
 *
 * Bodies are converted from RTE HTML to plain text via InstructionTextFormatter
 * so the LLM never sees raw markup.
 *
 * Auto-registered into the MCP ToolRegistry via the `mcp.tool` Symfony tag
 * declared on Hn\McpServer\MCP\Tool\ToolInterface.
 */
class GetInstructionTool extends AbstractTool
{
    public function __construct(
        private readonly AgentInstructionRepository $instructionRepository,
        private readonly InstructionTextFormatter $instructionTextFormatter,
    ) {}

    public function getSchema(): array
    {
        return [
            'description' => 'Retrieve detailed editorial instructions (guidelines maintained by the '
                . 'editorial team) on demand. Call this BEFORE producing the kind of content an '
                . 'instruction applies to — e.g. when writing or revising texts for content '
                . 'elements, news, or other records — to load the full guideline. The available '
                . 'on-demand instructions are listed in the system prompt as "[#id] Name — when to '
                . 'use". Pass `ids` to fetch specific instructions by their id, or `query` to '
                . 'search by keyword. With no arguments the tool returns the index of all '
                . 'available on-demand instructions.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'The instruction ids (from the system-prompt index) to load in full.',
                    ],
                    'query' => [
                        'type' => 'string',
                        'description' => 'Keyword to search instruction names, "when to use" hints and bodies.',
                    ],
                ],
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'idempotentHint' => true,
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $ids = [];
        if (isset($params['ids']) && is_array($params['ids'])) {
            $ids = $params['ids'];
        }
        $query = trim((string)($params['query'] ?? ''));

        if ($ids !== []) {
            $instructions = $this->instructionRepository->findByUids($ids);
            if ($instructions === []) {
                return new CallToolResult([new TextContent(
                    'No active instructions found for the given ids. Call GetInstruction without arguments to list available instructions.',
                )]);
            }
            return new CallToolResult([new TextContent($this->renderBodies($instructions))]);
        }

        if ($query !== '') {
            $instructions = $this->instructionRepository->searchActive($query);
            if ($instructions === []) {
                return new CallToolResult([new TextContent(
                    sprintf('No instructions matched "%s".', $query),
                )]);
            }
            return new CallToolResult([new TextContent($this->renderBodies($instructions))]);
        }

        return new CallToolResult([new TextContent($this->renderIndex())]);
    }

    /**
     * @param array<int, array{uid:int, title:string, description:string, instruction:string, mode:string}> $instructions
     */
    private function renderBodies(array $instructions): string
    {
        $parts = [];
        foreach ($instructions as $instruction) {
            $name = trim($instruction['title']) !== '' ? trim($instruction['title']) : 'Instruction';
            $parts[] = '## ' . $name . ' (#' . $instruction['uid'] . ")\n"
                . $this->instructionTextFormatter->toPromptText($instruction['instruction']);
        }
        return implode("\n\n", $parts);
    }

    private function renderIndex(): string
    {
        $instructions = $this->instructionRepository->findOnDemand();
        if ($instructions === []) {
            return 'No on-demand instructions are available.';
        }
        $lines = ['Available on-demand instructions (call GetInstruction with the relevant ids to load the full text):'];
        foreach ($instructions as $instruction) {
            $name = trim($instruction['title']) !== '' ? trim($instruction['title']) : 'Instruction';
            $hint = trim($instruction['description']);
            $lines[] = '- [#' . $instruction['uid'] . '] ' . $name . ($hint !== '' ? ' — ' . $hint : '');
        }
        return implode("\n", $lines);
    }
}
