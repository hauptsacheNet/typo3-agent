<?php

declare(strict_types=1);

namespace Hn\Agent\Tests\Functional\Service;

use Hn\Agent\Domain\AgentTaskRepository;
use Hn\Agent\Service\AgentService;
use Hn\Agent\Service\LlmService;
use Hn\Agent\Service\ToolConverterService;
use Hn\McpServer\MCP\ToolRegistry;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class AgentServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
        'agent',
    ];

    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');

        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['BE_USER'] = $backendUser;
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');

        $this->connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
    }

    /**
     * Create a task record directly in the database and return its UID.
     */
    private function createTask(string $title, string $prompt, int $pid = 0, int $status = 0, ?array $messages = null): int
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_agent_task');
        $connection->insert('tx_agent_task', [
            'pid' => $pid,
            'title' => $title,
            'prompt' => $prompt,
            'status' => $status,
            'messages' => $messages,
            'result' => '',
            'cruser_id' => 1,
            'crdate' => time(),
            'tstamp' => time(),
            'deleted' => 0,
            'hidden' => 0,
        ]);
        return (int)$connection->lastInsertId();
    }

    /**
     * Load a task record from DB by UID.
     */
    private function getTask(int $uid): array|false
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_agent_task');
        $queryBuilder->getRestrictions()->removeAll();
        return $queryBuilder
            ->select('*')
            ->from('tx_agent_task')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid)))
            ->executeQuery()
            ->fetchAssociative();
    }

    /**
     * Decode messages from a task record, handling potential double-encoding.
     */
    private function decodeMessages(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_string($decoded)) {
                $decoded = json_decode($decoded, true);
            }
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        self::fail('Could not decode messages: ' . var_export($raw, true));
    }

    /**
     * Build an AgentService with a mocked LlmService that returns canned responses.
     */
    private function buildAgentServiceWithMock(array $responses): AgentService
    {
        $callIndex = 0;
        $llmMock = $this->createMock(LlmService::class);
        $llmMock->method('chatCompletionStream')->willReturnCallback(
            function () use (&$callIndex, $responses) {
                if ($callIndex >= count($responses)) {
                    throw new \RuntimeException('LlmService mock exhausted: no more responses');
                }
                return $responses[$callIndex++];
            }
        );

        return new AgentService(
            $llmMock,
            GeneralUtility::makeInstance(ToolConverterService::class),
            GeneralUtility::makeInstance(ToolRegistry::class),
            GeneralUtility::makeInstance(ExtensionConfiguration::class),
            $this->connectionPool,
            new AgentTaskRepository($this->connectionPool),
            GeneralUtility::makeInstance(\TYPO3\CMS\Core\Resource\ResourceFactory::class),
        );
    }

    public function testSimpleResponseWithoutToolCalls(): void
    {
        $taskUid = $this->createTask('Test task', 'List all pages');

        $agentService = $this->buildAgentServiceWithMock([
            ['role' => 'assistant', 'content' => 'Here are the pages: Home, About.'],
        ]);

        $agentService->processTask($taskUid);

        $task = $this->getTask($taskUid);
        self::assertSame(2, (int)$task['status'], 'Task should be ended (status=2)');
        self::assertSame('Here are the pages: Home, About.', $task['result']);

        $messages = $this->decodeMessages($task['messages']);
        // system + user + assistant = 3 messages
        self::assertCount(3, $messages);
        self::assertSame('system', $messages[0]['role']);
        self::assertSame('user', $messages[1]['role']);
        self::assertSame('assistant', $messages[2]['role']);
    }

    public function testResponseWithToolCalls(): void
    {
        $taskUid = $this->createTask('Test task', 'Show the page tree');

        $agentService = $this->buildAgentServiceWithMock([
            [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [
                    [
                        'id' => 'call_001',
                        'type' => 'function',
                        'function' => [
                            'name' => 'GetPageTree',
                            'arguments' => '{"depth": 1}',
                        ],
                    ],
                ],
            ],
            ['role' => 'assistant', 'content' => 'The page tree has: Home, About.'],
        ]);

        $agentService->processTask($taskUid);

        $task = $this->getTask($taskUid);
        self::assertSame(2, (int)$task['status']);
        self::assertSame('The page tree has: Home, About.', $task['result']);

        $messages = $this->decodeMessages($task['messages']);
        // system + user + assistant(tool_calls) + tool(result) + assistant(final) = 5
        self::assertCount(5, $messages);
        self::assertSame('tool', $messages[3]['role']);
        self::assertSame('call_001', $messages[3]['tool_call_id']);
    }

    public function testResumeFromExistingMessages(): void
    {
        // Pre-fill messages as if the task was interrupted mid-conversation
        $existingMessages = [
            ['role' => 'system', 'content' => 'You are a TYPO3 assistant.'],
            ['role' => 'user', 'content' => 'List all pages'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'call_001', 'type' => 'function', 'function' => ['name' => 'GetPageTree', 'arguments' => '{}']],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'call_001', 'content' => 'Home, About'],
        ];

        $taskUid = $this->createTask('Resume test', 'List all pages', 0, 0, $existingMessages);

        $agentService = $this->buildAgentServiceWithMock([
            ['role' => 'assistant', 'content' => 'The pages are: Home and About.'],
        ]);

        $agentService->processTask($taskUid);

        $task = $this->getTask($taskUid);
        self::assertSame(2, (int)$task['status']);
        self::assertSame('The pages are: Home and About.', $task['result']);

        $messages = $this->decodeMessages($task['messages']);
        // Original 4 messages + 1 new assistant = 5
        self::assertCount(5, $messages);
    }

    public function testFailedTaskPreservesMessages(): void
    {
        $taskUid = $this->createTask('Fail test', 'Do something');

        $llmMock = $this->createMock(LlmService::class);
        $llmMock->method('chatCompletionStream')->willThrowException(
            new \RuntimeException('API connection failed')
        );

        $agentService = new AgentService(
            $llmMock,
            GeneralUtility::makeInstance(ToolConverterService::class),
            GeneralUtility::makeInstance(ToolRegistry::class),
            GeneralUtility::makeInstance(ExtensionConfiguration::class),
            $this->connectionPool,
            new AgentTaskRepository($this->connectionPool),
            GeneralUtility::makeInstance(\TYPO3\CMS\Core\Resource\ResourceFactory::class),
        );

        try {
            $agentService->processTask($taskUid);
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('API connection failed', $e->getMessage());
        }

        $task = $this->getTask($taskUid);
        self::assertSame(3, (int)$task['status'], 'Task should be failed (status=3)');
        self::assertStringContainsString('Error:', $task['result']);

        // Messages should be preserved (initial system + user)
        $messages = $this->decodeMessages($task['messages']);
        self::assertCount(2, $messages);
    }

    public function testPageContextIncludedWhenPidSet(): void
    {
        // Create task on page 1 (Home)
        $taskUid = $this->createTask('Page context test', 'Describe this page', 1);

        $agentService = $this->buildAgentServiceWithMock([
            ['role' => 'assistant', 'content' => 'This is the Home page.'],
        ]);

        $agentService->processTask($taskUid);

        $task = $this->getTask($taskUid);
        $messages = $this->decodeMessages($task['messages']);

        // Expected shape after buildMessages() — context turn precedes user prompt:
        // [0] system, [1] assistant(narration content + GetPage tool_call),
        // [2] tool(GetPage result), [3] user(prompt), [4] assistant(final mock response)
        self::assertSame('assistant', $messages[1]['role']);
        self::assertIsString($messages[1]['content']);
        self::assertStringContainsString('Arbeitskontext', $messages[1]['content']);
        self::assertStringContainsString('#1', $messages[1]['content']);
        self::assertNotEmpty($messages[1]['tool_calls']);
        self::assertSame('GetPage', $messages[1]['tool_calls'][0]['function']['name']);
        self::assertSame('tool', $messages[2]['role']);
        self::assertSame('user', $messages[3]['role']);
        self::assertSame('Describe this page', $messages[3]['content']);
    }

    public function testContinueChatPersistsAttachmentsStructuredAndSerializesForLlm(): void
    {
        $taskUid = $this->createTask('Attachment test', 'Initial prompt');

        // Capture what is handed to LlmService so we can prove the markdown
        // block exists in the LLM payload — but NOT in the persisted state.
        $capturedMessages = [];
        $llmMock = $this->createMock(LlmService::class);
        $llmMock->method('chatCompletionStream')->willReturnCallback(
            function (array $messages) use (&$capturedMessages): array {
                $capturedMessages[] = $messages;
                return ['role' => 'assistant', 'content' => 'OK.'];
            }
        );

        $agentService = new AgentService(
            $llmMock,
            GeneralUtility::makeInstance(ToolConverterService::class),
            GeneralUtility::makeInstance(ToolRegistry::class),
            GeneralUtility::makeInstance(ExtensionConfiguration::class),
            $this->connectionPool,
            new AgentTaskRepository($this->connectionPool),
            GeneralUtility::makeInstance(\TYPO3\CMS\Core\Resource\ResourceFactory::class),
        );

        // Pass an unresolvable attachment (no such sys_file UID) — keeps the
        // test independent from FAL fixtures while still exercising the full
        // resolveAttachmentRefs → serializeForLlm pipeline.
        $attachments = [
            ['uid' => 999999, 'name' => 'phantom.pdf'],
        ];
        $agentService->continueChat($taskUid, 'Look at this', null, $attachments);

        // --- Persisted state: attachments structured, no markdown block in content
        $task = $this->getTask($taskUid);
        $messages = $this->decodeMessages($task['messages']);

        $userMsg = null;
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'user' && ($m['content'] ?? '') === 'Look at this') {
                $userMsg = $m;
                break;
            }
        }
        self::assertNotNull($userMsg, 'User message with plain content was persisted');
        self::assertSame('Look at this', $userMsg['content']);
        self::assertArrayHasKey('attachments', $userMsg);
        self::assertCount(1, $userMsg['attachments']);
        self::assertSame('phantom.pdf', $userMsg['attachments'][0]['name']);
        self::assertTrue($userMsg['attachments'][0]['unresolvable'] ?? false);
        self::assertStringNotContainsString('Angehängte Dateien', $userMsg['content']);

        // --- LLM view: markdown block in content, no structured attachments field
        self::assertNotEmpty($capturedMessages, 'LlmService was called at least once');
        $llmMessages = $capturedMessages[0];

        $llmUserMsg = null;
        foreach ($llmMessages as $m) {
            $content = (string)($m['content'] ?? '');
            if (($m['role'] ?? '') === 'user' && str_contains($content, 'Look at this')) {
                $llmUserMsg = $m;
                break;
            }
        }
        self::assertNotNull($llmUserMsg, 'User message reached LlmService');
        self::assertArrayNotHasKey('attachments', $llmUserMsg, 'LLM payload has no structured attachments field');
        self::assertStringContainsString('Angehängte Dateien:', $llmUserMsg['content']);
        self::assertStringContainsString('phantom.pdf', $llmUserMsg['content']);
        self::assertStringContainsString('nicht auflösbar', $llmUserMsg['content']);
    }

    /**
     * @param-out array $capturedMessages
     */
    private function buildAgentServiceCapturing(array &$capturedMessages, ResourceFactory $resourceFactory): AgentService
    {
        $llmMock = $this->createMock(LlmService::class);
        $llmMock->method('chatCompletionStream')->willReturnCallback(
            function (array $messages) use (&$capturedMessages): array {
                $capturedMessages[] = $messages;
                return ['role' => 'assistant', 'content' => 'OK.'];
            }
        );

        return new AgentService(
            $llmMock,
            GeneralUtility::makeInstance(ToolConverterService::class),
            GeneralUtility::makeInstance(ToolRegistry::class),
            GeneralUtility::makeInstance(ExtensionConfiguration::class),
            $this->connectionPool,
            new AgentTaskRepository($this->connectionPool),
            $resourceFactory,
        );
    }

    /**
     * Build a File mock with the FAL methods serializeForLlm() touches.
     * `getContents` defaults to throwing if the caller didn't expect to read
     * the file (used to assert oversize / unsupported MIME short-circuits).
     */
    private function buildFileMock(int $uid, string $mime, int $size, string $name, string $identifier, ?string $content = null): File
    {
        $file = $this->getMockBuilder(File::class)->disableOriginalConstructor()->getMock();
        $file->method('getUid')->willReturn($uid);
        $file->method('getMimeType')->willReturn($mime);
        $file->method('getSize')->willReturn($size);
        $file->method('getName')->willReturn($name);
        $file->method('getCombinedIdentifier')->willReturn($identifier);
        if ($content === null) {
            $file->expects(self::never())->method('getContents');
        } else {
            $file->method('getContents')->willReturn($content);
        }
        return $file;
    }

    private function buildResourceFactoryReturning(int $uid, File $file): ResourceFactory
    {
        $factory = $this->getMockBuilder(ResourceFactory::class)->disableOriginalConstructor()->getMock();
        $factory->method('getFileObject')->with($uid)->willReturn($file);
        return $factory;
    }

    /**
     * Locate the user message in the LLM-payload that carries our test text.
     * Returns null on mismatch so assertions stay readable in the calling test.
     */
    private function findLlmUserMessage(array $llmMessages, string $textNeedle): ?array
    {
        foreach ($llmMessages as $m) {
            if (($m['role'] ?? '') !== 'user') {
                continue;
            }
            $content = $m['content'] ?? null;
            if (is_string($content) && str_contains($content, $textNeedle)) {
                return $m;
            }
            if (is_array($content)) {
                foreach ($content as $block) {
                    if (is_array($block) && ($block['type'] ?? '') === 'text' && str_contains((string)($block['text'] ?? ''), $textNeedle)) {
                        return $m;
                    }
                }
            }
        }
        return null;
    }

    public function testImageAttachmentBecomesImageUrlBlock(): void
    {
        // Smallest valid PNG payload (1x1 transparent) — only the byte content
        // matters for round-tripping through base64; the mock owns size/MIME.
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=');
        $file = $this->buildFileMock(101, 'image/png', strlen($pngBytes), 'pixel.png', '1:/uploads/pixel.png', $pngBytes);
        $resourceFactory = $this->buildResourceFactoryReturning(101, $file);

        $capturedMessages = [];
        $agentService = $this->buildAgentServiceCapturing($capturedMessages, $resourceFactory);

        $taskUid = $this->createTask('Image test', 'Initial');
        $agentService->continueChat($taskUid, 'Was siehst du?', null, [['uid' => 101]]);

        self::assertNotEmpty($capturedMessages);
        $userMsg = $this->findLlmUserMessage($capturedMessages[0], 'Was siehst du?');
        self::assertNotNull($userMsg, 'User message reached LlmService');

        self::assertIsArray($userMsg['content'], 'Content is a block array when media is embedded');
        self::assertCount(2, $userMsg['content'], 'Exactly one text + one media block');
        self::assertSame('text', $userMsg['content'][0]['type']);
        self::assertStringContainsString('Was siehst du?', $userMsg['content'][0]['text']);
        self::assertStringContainsString('sys_file:101', $userMsg['content'][0]['text']);

        self::assertSame('image_url', $userMsg['content'][1]['type']);
        self::assertStringStartsWith('data:image/png;base64,', $userMsg['content'][1]['image_url']['url']);
        self::assertStringContainsString(base64_encode($pngBytes), $userMsg['content'][1]['image_url']['url']);
    }

    public function testPdfAttachmentBecomesFileBlock(): void
    {
        $pdfBytes = "%PDF-1.4\n% minimal\n";
        $file = $this->buildFileMock(202, 'application/pdf', strlen($pdfBytes), 'doc.pdf', '1:/uploads/doc.pdf', $pdfBytes);
        $resourceFactory = $this->buildResourceFactoryReturning(202, $file);

        $capturedMessages = [];
        $agentService = $this->buildAgentServiceCapturing($capturedMessages, $resourceFactory);

        $taskUid = $this->createTask('PDF test', 'Initial');
        $agentService->continueChat($taskUid, 'Fass zusammen.', null, [['uid' => 202]]);

        $userMsg = $this->findLlmUserMessage($capturedMessages[0], 'Fass zusammen.');
        self::assertNotNull($userMsg);
        self::assertIsArray($userMsg['content']);
        self::assertSame('file', $userMsg['content'][1]['type']);
        self::assertSame('doc.pdf', $userMsg['content'][1]['file']['filename']);
        self::assertStringStartsWith('data:application/pdf;base64,', $userMsg['content'][1]['file']['file_data']);
        self::assertStringContainsString(base64_encode($pdfBytes), $userMsg['content'][1]['file']['file_data']);
    }

    public function testOversizedImageFallsBackToMarkerOnly(): void
    {
        // 6 MiB > 5 MiB image cap. content=null asserts getContents() is never invoked.
        $file = $this->buildFileMock(303, 'image/png', 6 * 1024 * 1024, 'huge.png', '1:/uploads/huge.png', null);
        $resourceFactory = $this->buildResourceFactoryReturning(303, $file);

        $capturedMessages = [];
        $agentService = $this->buildAgentServiceCapturing($capturedMessages, $resourceFactory);

        $taskUid = $this->createTask('Oversize test', 'Initial');
        $agentService->continueChat($taskUid, 'Trotzdem?', null, [['uid' => 303]]);

        $userMsg = $this->findLlmUserMessage($capturedMessages[0], 'Trotzdem?');
        self::assertNotNull($userMsg);
        self::assertIsString($userMsg['content'], 'Content stays a plain string when no media is embedded');
        self::assertStringContainsString('sys_file:303', $userMsg['content']);
        self::assertStringContainsString('zu groß', $userMsg['content']);
        self::assertStringContainsString('nur Metadaten', $userMsg['content']);
    }

    public function testUnsupportedMimeFallsBackToMarkerOnly(): void
    {
        // text/plain isn't on our allowlist — even though small, must stay marker-only.
        $file = $this->buildFileMock(404, 'text/plain', 100, 'notes.txt', '1:/uploads/notes.txt', null);
        $resourceFactory = $this->buildResourceFactoryReturning(404, $file);

        $capturedMessages = [];
        $agentService = $this->buildAgentServiceCapturing($capturedMessages, $resourceFactory);

        $taskUid = $this->createTask('Unsupported mime test', 'Initial');
        $agentService->continueChat($taskUid, 'Schau mal.', null, [['uid' => 404]]);

        $userMsg = $this->findLlmUserMessage($capturedMessages[0], 'Schau mal.');
        self::assertNotNull($userMsg);
        self::assertIsString($userMsg['content']);
        self::assertStringContainsString('sys_file:404', $userMsg['content']);
        self::assertStringContainsString('text/plain', $userMsg['content']);
        self::assertStringContainsString('nur Metadaten', $userMsg['content']);
        self::assertStringNotContainsString('zu groß', $userMsg['content']);
    }

    public function testPreviewAttachmentReportsImageEmbeddable(): void
    {
        $file = $this->buildFileMock(101, 'image/png', 2048, 'pixel.png', '1:/uploads/pixel.png', null);
        $resourceFactory = $this->buildResourceFactoryReturning(101, $file);

        $capturedMessages = [];
        $agentService = $this->buildAgentServiceCapturing($capturedMessages, $resourceFactory);

        $preview = $agentService->previewAttachment(['uid' => 101]);

        self::assertSame(101, $preview['uid']);
        self::assertSame('image/png', $preview['mime']);
        self::assertSame(2048, $preview['size']);
        self::assertTrue($preview['embedAsContent']);
        self::assertNull($preview['reason']);
    }

    public function testPreviewAttachmentReportsOversizeReason(): void
    {
        $file = $this->buildFileMock(303, 'image/png', 6 * 1024 * 1024, 'huge.png', '1:/uploads/huge.png', null);
        $resourceFactory = $this->buildResourceFactoryReturning(303, $file);

        $capturedMessages = [];
        $agentService = $this->buildAgentServiceCapturing($capturedMessages, $resourceFactory);

        $preview = $agentService->previewAttachment(['uid' => 303]);

        self::assertFalse($preview['embedAsContent']);
        self::assertNotNull($preview['reason']);
        self::assertStringContainsString('zu groß', $preview['reason']);
        self::assertStringContainsString('MiB', $preview['reason']);
    }

    public function testPreviewAttachmentReportsUnsupportedMime(): void
    {
        $file = $this->buildFileMock(404, 'text/plain', 100, 'notes.txt', '1:/uploads/notes.txt', null);
        $resourceFactory = $this->buildResourceFactoryReturning(404, $file);

        $capturedMessages = [];
        $agentService = $this->buildAgentServiceCapturing($capturedMessages, $resourceFactory);

        $preview = $agentService->previewAttachment(['uid' => 404]);

        self::assertFalse($preview['embedAsContent']);
        self::assertSame('Format nicht unterstützt', $preview['reason']);
    }

    public function testPreviewAttachmentReportsUnresolvable(): void
    {
        $capturedMessages = [];
        // real ResourceFactory — uid 999999 will not resolve, falls into catch
        $agentService = $this->buildAgentServiceCapturing(
            $capturedMessages,
            GeneralUtility::makeInstance(ResourceFactory::class),
        );

        $preview = $agentService->previewAttachment(['uid' => 999999]);

        self::assertFalse($preview['embedAsContent']);
        self::assertSame('Datei nicht auflösbar', $preview['reason']);
    }

    public function testCallbackReceivesProgressUpdates(): void
    {
        $taskUid = $this->createTask('Event test', 'Hello');

        $calls = [];
        $progress = function (string $event, array $data) use (&$calls): void {
            $calls[] = [$event, $data];
        };

        $agentService = $this->buildAgentServiceWithMock(
            [['role' => 'assistant', 'content' => 'Done.']],
        );

        $agentService->processTask($taskUid, $progress);

        // Fresh tasks emit a `user_message` event up front so the UI can render
        // the prompt in the same slot it occupies in the persisted state.
        self::assertCount(3, $calls);

        self::assertSame('user_message', $calls[0][0]);
        self::assertSame('Hello', $calls[0][1]['message']['content']);

        self::assertSame('llm_start', $calls[1][0]);
        self::assertSame(0, $calls[1][1]['iteration']);

        self::assertSame('assistant_message', $calls[2][0]);
        self::assertSame(0, $calls[2][1]['iteration']);
        self::assertSame('Done.', $calls[2][1]['message']['content']);
    }
}
