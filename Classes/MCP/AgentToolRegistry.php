<?php

declare(strict_types=1);

namespace Hn\Agent\MCP;

use Hn\McpServer\MCP\ToolRegistry;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Tool registry for the internal agent loop: the MCP core tools
 * (GetPage, ReadTable, WriteTable, …) plus the agent-only tools tagged
 * `agent.tool` in Services.yaml (ReadFile, ExtractImages, …).
 *
 * The agent-only tools are deliberately NOT tagged `mcp.tool`: that tag
 * would put them into the shared ToolRegistry that the mcp_server
 * extension exposes to external MCP clients. The agent tools are designed
 * around this extension's chat internals (scratch storage, multimodal
 * tool messages, instruction records) and external clients bring their
 * own file handling — so they only exist here.
 */
class AgentToolRegistry extends ToolRegistry
{
    public function __construct(
        ToolRegistry $mcpToolRegistry,
        #[AutowireIterator('agent.tool')]
        iterable $agentTools,
    ) {
        parent::__construct([...array_values($mcpToolRegistry->getTools()), ...$agentTools]);
    }
}
