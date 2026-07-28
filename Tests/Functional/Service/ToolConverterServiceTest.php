<?php

declare(strict_types=1);

namespace Hn\Agent\Tests\Functional\Service;

use Hn\Agent\MCP\AgentToolRegistry;
use Hn\Agent\Service\AgentScratchStorage;
use Hn\Agent\Service\ToolConverterService;
use Hn\McpServer\MCP\ToolRegistry;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class ToolConverterServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
        'agent',
    ];

    private ToolConverterService $toolConverterService;
    private AgentToolRegistry $toolRegistry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');

        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['BE_USER'] = $backendUser;
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');

        $scratchStorage = new AgentScratchStorage(
            GeneralUtility::makeInstance(StorageRepository::class),
            GeneralUtility::makeInstance(ResourceFactory::class),
            GeneralUtility::makeInstance(ConnectionPool::class),
        );
        $this->toolConverterService = new ToolConverterService($scratchStorage);
        $this->toolRegistry = GeneralUtility::makeInstance(AgentToolRegistry::class);
    }

    public function testConvertToolsReturnsOpenAiFormat(): void
    {
        $tools = $this->toolConverterService->convertTools($this->toolRegistry);

        self::assertNotEmpty($tools, 'ToolRegistry should contain tools from the MCP extension');

        foreach ($tools as $tool) {
            self::assertSame('function', $tool['type']);
            self::assertArrayHasKey('function', $tool);
            self::assertArrayHasKey('name', $tool['function']);
            self::assertArrayHasKey('description', $tool['function']);
            self::assertArrayHasKey('parameters', $tool['function']);
            self::assertNotEmpty($tool['function']['name']);
            self::assertNotEmpty($tool['function']['description']);
        }
    }

    public function testConvertToolsContainsExpectedTools(): void
    {
        $tools = $this->toolConverterService->convertTools($this->toolRegistry);
        $toolNames = array_map(fn($t) => $t['function']['name'], $tools);

        self::assertContains('GetPage', $toolNames);
        self::assertContains('GetPageTree', $toolNames);
        self::assertContains('Search', $toolNames);
        self::assertContains('ReadTable', $toolNames);
        self::assertContains('ListTables', $toolNames);
        // The agent-only tools are tagged agent.tool and merged into the
        // AgentToolRegistry alongside the MCP core tools.
        self::assertContains('ReadFile', $toolNames);
        self::assertContains('ExtractImages', $toolNames);
        self::assertContains('PromoteScratchFile', $toolNames);
        self::assertContains('GetInstruction', $toolNames);
    }

    public function testAgentToolsAreNotExposedThroughMcpToolRegistry(): void
    {
        // Regression guard for the "tool leak": the agent-only tools are
        // designed around the chat internals (scratch storage, multimodal
        // tool messages) and must not show up in the ToolRegistry that the
        // mcp_server extension exposes to external MCP clients.
        $mcpRegistry = GeneralUtility::makeInstance(ToolRegistry::class);
        $mcpToolNames = array_keys($mcpRegistry->getTools());

        self::assertContains('GetPage', $mcpToolNames);
        self::assertNotContains('ReadFile', $mcpToolNames);
        self::assertNotContains('ExtractImages', $mcpToolNames);
        self::assertNotContains('PromoteScratchFile', $mcpToolNames);
        self::assertNotContains('GetInstruction', $mcpToolNames);
    }

    public function testExecuteToolCallSuccess(): void
    {
        $result = $this->toolConverterService->executeToolCall(
            $this->toolRegistry,
            'GetPageTree',
            ['depth' => 1],
        );

        self::assertNotEmpty($result['text']);
        self::assertStringNotContainsString('Error', $result['text']);
        self::assertSame([], $result['attachments']);
    }

    public function testExecuteToolCallWithStringArguments(): void
    {
        $result = $this->toolConverterService->executeToolCall(
            $this->toolRegistry,
            'GetPageTree',
            '{"depth": 1}',
        );

        self::assertNotEmpty($result['text']);
        self::assertStringNotContainsString('Error', $result['text']);
    }

    public function testExecuteToolCallNotFound(): void
    {
        $result = $this->toolConverterService->executeToolCall(
            $this->toolRegistry,
            'NonExistentTool',
            [],
        );

        self::assertStringContainsString('Error', $result['text']);
        self::assertStringContainsString('not found', $result['text']);
        self::assertSame([], $result['attachments']);
    }

    public function testExecuteToolCallHandlesErrors(): void
    {
        // Pass invalid params to trigger an error inside the tool
        $result = $this->toolConverterService->executeToolCall(
            $this->toolRegistry,
            'GetPage',
            ['uid' => -999],
        );

        // Should return structured array with error text, not throw
        self::assertIsArray($result);
        self::assertIsString($result['text']);
    }
}
