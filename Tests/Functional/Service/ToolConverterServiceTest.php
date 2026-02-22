<?php

declare(strict_types=1);

namespace Hn\Agent\Tests\Functional\Service;

use Hn\Agent\Service\ToolConverterService;
use Hn\McpServer\MCP\ToolRegistry;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
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
    private ToolRegistry $toolRegistry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');

        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['BE_USER'] = $backendUser;
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');

        $this->toolConverterService = GeneralUtility::makeInstance(ToolConverterService::class);
        $this->toolRegistry = GeneralUtility::makeInstance(ToolRegistry::class);
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
    }

    public function testExecuteToolCallSuccess(): void
    {
        $result = $this->toolConverterService->executeToolCall(
            $this->toolRegistry,
            'GetPageTree',
            ['depth' => 1],
        );

        self::assertNotEmpty($result);
        self::assertStringNotContainsString('Error', $result);
    }

    public function testExecuteToolCallWithStringArguments(): void
    {
        $result = $this->toolConverterService->executeToolCall(
            $this->toolRegistry,
            'GetPageTree',
            '{"depth": 1}',
        );

        self::assertNotEmpty($result);
        self::assertStringNotContainsString('Error', $result);
    }

    public function testExecuteToolCallNotFound(): void
    {
        $result = $this->toolConverterService->executeToolCall(
            $this->toolRegistry,
            'NonExistentTool',
            [],
        );

        self::assertStringContainsString('Error', $result);
        self::assertStringContainsString('not found', $result);
    }

    public function testExecuteToolCallHandlesErrors(): void
    {
        // Pass invalid params to trigger an error inside the tool
        $result = $this->toolConverterService->executeToolCall(
            $this->toolRegistry,
            'GetPage',
            ['uid' => -999],
        );

        // Should return error string, not throw
        self::assertIsString($result);
    }
}
