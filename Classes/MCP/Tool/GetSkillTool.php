<?php

declare(strict_types=1);

namespace Hn\Agent\MCP\Tool;

use Hn\Agent\Domain\AgentSkillRepository;
use Hn\Agent\Service\SkillTextFormatter;
use Hn\McpServer\MCP\Tool\AbstractTool;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;

/**
 * On-demand retrieval of editor-maintained agent skills (tx_agent_skill).
 *
 * The system prompt lists "on_demand" skills as a short index (id + name +
 * "when to use"); this tool delivers the full body when the agent decides a
 * skill is relevant — the progressive-disclosure half of the SKILL.md model.
 *
 *  - `ids`   → return the full body of those skills.
 *  - `query` → keyword search across name / hint / body, return matches' body.
 *  - neither → return the index of all available on-demand skills, so the
 *              agent can pick the relevant id(s) and call again.
 *
 * Bodies are converted from RTE HTML to plain text via SkillTextFormatter so
 * the LLM never sees raw markup.
 *
 * Auto-registered into the MCP ToolRegistry via the `mcp.tool` Symfony tag
 * declared on Hn\McpServer\MCP\Tool\ToolInterface.
 */
class GetSkillTool extends AbstractTool
{
    public function __construct(
        private readonly AgentSkillRepository $skillRepository,
        private readonly SkillTextFormatter $skillTextFormatter,
    ) {}

    public function getSchema(): array
    {
        return [
            'description' => 'Retrieve detailed editorial skills (guidelines maintained by the '
                . 'editorial team) on demand. Call this BEFORE producing the kind of content a '
                . 'skill applies to — e.g. when writing or revising texts for content elements, '
                . 'news, or other records — to load the full guideline. The available on-demand '
                . 'skills are listed in the system prompt as "[#id] Name — when to use". '
                . 'Pass `ids` to fetch specific skills by their id, or `query` to search by '
                . 'keyword. With no arguments the tool returns the index of all available '
                . 'on-demand skills.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'The skill ids (from the system-prompt index) to load in full.',
                    ],
                    'query' => [
                        'type' => 'string',
                        'description' => 'Keyword to search skill names, "when to use" hints and bodies.',
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
            $skills = $this->skillRepository->findByUids($ids);
            if ($skills === []) {
                return new CallToolResult([new TextContent(
                    'No active skills found for the given ids. Call GetSkill without arguments to list available skills.',
                )]);
            }
            return new CallToolResult([new TextContent($this->renderBodies($skills))]);
        }

        if ($query !== '') {
            $skills = $this->skillRepository->searchActive($query);
            if ($skills === []) {
                return new CallToolResult([new TextContent(
                    sprintf('No skills matched "%s".', $query),
                )]);
            }
            return new CallToolResult([new TextContent($this->renderBodies($skills))]);
        }

        return new CallToolResult([new TextContent($this->renderIndex())]);
    }

    /**
     * @param array<int, array{uid:int, title:string, description:string, instruction:string, mode:string}> $skills
     */
    private function renderBodies(array $skills): string
    {
        $parts = [];
        foreach ($skills as $skill) {
            $name = trim($skill['title']) !== '' ? trim($skill['title']) : 'Skill';
            $parts[] = '## ' . $name . ' (#' . $skill['uid'] . ")\n"
                . $this->skillTextFormatter->toPromptText($skill['instruction']);
        }
        return implode("\n\n", $parts);
    }

    private function renderIndex(): string
    {
        $skills = $this->skillRepository->findOnDemand();
        if ($skills === []) {
            return 'No on-demand skills are available.';
        }
        $lines = ['Available on-demand skills (call GetSkill with the relevant ids to load the full text):'];
        foreach ($skills as $skill) {
            $name = trim($skill['title']) !== '' ? trim($skill['title']) : 'Skill';
            $hint = trim($skill['description']);
            $lines[] = '- [#' . $skill['uid'] . '] ' . $name . ($hint !== '' ? ' — ' . $hint : '');
        }
        return implode("\n", $lines);
    }
}
