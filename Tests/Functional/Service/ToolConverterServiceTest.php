<?php

declare(strict_types=1);

namespace Hn\Agent\Tests\Functional\Service;

use Hn\Agent\Service\ToolConverterService;
use Hn\McpServer\MCP\Tool\AbstractTool;
use Hn\McpServer\MCP\ToolRegistry;
use Mcp\Types\Annotations;
use Mcp\Types\CallToolResult;
use Mcp\Types\ImageContent;
use Mcp\Types\Role;
use Mcp\Types\TextContent;
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
        // GetFileInfo + ViewImage live in the agent package and register
        // themselves via the mcp.tool autoconfigure tag — both must show
        // up alongside vendor tools.
        self::assertContains('GetFileInfo', $toolNames);
        self::assertContains('ViewImage', $toolNames);
        // The document-image feature tools register the same way.
        self::assertContains('ExtractDocumentImages', $toolNames);
        self::assertContains('ViewExtractedImage', $toolNames);
        self::assertContains('StoreImageInFileadmin', $toolNames);
    }

    public function testExecuteToolCallSplitsUserAudienceIntoUiMedia(): void
    {
        // A user-audience image must land in uiMedia (UI-only), while a normal
        // image stays in media (sent to the model) — this is the split that
        // keeps extracted thumbnails out of the conversation context.
        $registry = new ToolRegistry([new AudienceStubTool()]);

        $result = $this->toolConverterService->executeToolCall($registry, 'AudienceStub', []);

        self::assertStringContainsString('hello', $result['text']);
        self::assertSame([['mime' => 'image/jpeg', 'data' => 'YmJiYg==']], $result['media']);
        self::assertCount(1, $result['uiMedia']);
        self::assertSame('image/png', $result['uiMedia'][0]['mime']);
        self::assertSame('dGVzdA==', $result['uiMedia'][0]['data']);
        self::assertSame('#0 — logo.png', $result['uiMedia'][0]['label']);
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
        self::assertSame([], $result['media']);
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
        self::assertSame([], $result['media']);
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

/**
 * Minimal tool emitting one text block, one user-audience image (UI-only) and
 * one normal image (model-facing), to exercise the audience split.
 */
class AudienceStubTool extends AbstractTool
{
    public function getSchema(): array
    {
        return [
            'description' => 'stub',
            'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $userAnnotations = new Annotations(audience: [Role::USER]);
        $userAnnotations->label = '#0 — logo.png';

        return new CallToolResult([
            new TextContent('hello'),
            new ImageContent('dGVzdA==', 'image/png', $userAnnotations),
            new ImageContent('YmJiYg==', 'image/jpeg'),
        ]);
    }
}
