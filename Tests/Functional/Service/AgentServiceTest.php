<?php

declare(strict_types=1);

namespace Hn\Agent\Tests\Functional\Service;

use Hn\Agent\Domain\AgentInstructionRepository;
use Hn\Agent\Domain\AgentMessageRepository;
use Hn\Agent\Domain\AgentTaskRepository;
use Hn\Agent\Domain\TaskStateMachine;
use Hn\Agent\Service\AgentScratchStorage;
use Hn\Agent\Service\AgentService;
use Hn\Agent\Service\AttachmentService;
use Hn\Agent\Service\ChangeTracker;
use Hn\Agent\Service\InstructionTextFormatter;
use Hn\Agent\Service\LlmService;
use Hn\Agent\Service\MessageLlmSerializer;
use Hn\Agent\Service\ToolConverterService;
use Hn\Agent\MCP\AgentToolRegistry;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
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
    private AgentMessageRepository $messageRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');

        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['BE_USER'] = $backendUser;
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');

        $this->connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $this->messageRepository = new AgentMessageRepository($this->connectionPool);
    }

    /**
     * Insert a bare task record and persist the initial system+user
     * conversation via AgentService::persistInitialMessages — mirrors
     * ChatController::newAction in production.
     */
    private function createTask(string $title, string $prompt, int $pid = 0, int $status = 0, string $contextTable = '', int $contextUid = 0): int
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_agent_task');
        $connection->insert(
            'tx_agent_task',
            [
                'pid' => $pid,
                'title' => $title,
                'prompt' => $prompt,
                'status' => $status,
                'result' => '',
                'cruser_id' => 1,
                'crdate' => time(),
                'tstamp' => time(),
                'deleted' => 0,
                'hidden' => 0,
            ],
        );
        $taskUid = (int)$connection->lastInsertId();
        $this->buildAgentServiceWithMock([])
            ->persistInitialMessages($taskUid, $pid, $contextTable, $contextUid, $prompt);
        return $taskUid;
    }

    /**
     * Directly insert a message row (bypassing AgentMessageRepository::append),
     * used only for resume scenarios where we simulate pre-existing state.
     *
     * @param array<string, mixed> $message
     */
    private function insertRawMessage(int $taskUid, int $sorting, array $message): void
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_agent_message');
        $now = time();
        $toolCalls = $message['tool_calls'] ?? null;
        $connection->insert(
            'tx_agent_message',
            [
                'pid' => 0,
                'tstamp' => $now,
                'crdate' => $now,
                'sorting' => $sorting,
                'task' => $taskUid,
                'role' => (string)($message['role'] ?? ''),
                'content' => (string)($message['content'] ?? ''),
                'reasoning' => (string)($message['reasoning'] ?? ''),
                'tool_calls' => is_array($toolCalls) ? $toolCalls : null,
                'tool_call_id' => (string)($message['tool_call_id'] ?? ''),
                'tool_name' => (string)($message['tool_name'] ?? ''),
                'attachments' => 0,
            ],
            ['tool_calls' => \Doctrine\DBAL\Types\Types::JSON],
        );
    }

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
     * @return list<array<string, mixed>>
     */
    private function loadMessages(int $taskUid): array
    {
        return $this->messageRepository->findByTask($taskUid);
    }

    private function makeToolConverterService(): ToolConverterService
    {
        $scratchStorage = new AgentScratchStorage(
            GeneralUtility::makeInstance(StorageRepository::class),
            GeneralUtility::makeInstance(ResourceFactory::class),
            $this->connectionPool,
        );
        return new ToolConverterService($scratchStorage);
    }

    private function buildAgentServiceWithMock(array $responses, ?ResourceFactory $resourceFactory = null): AgentService
    {
        $callIndex = 0;
        $llmStub = $this->createStub(LlmService::class);
        $llmStub->method('chatCompletionStream')->willReturnCallback(
            function () use (&$callIndex, $responses) {
                if ($callIndex >= count($responses)) {
                    throw new \RuntimeException('LlmService mock exhausted: no more responses');
                }
                return $responses[$callIndex++];
            }
        );

        $resourceFactory ??= GeneralUtility::makeInstance(ResourceFactory::class);
        $attachmentService = new AttachmentService($resourceFactory, $this->connectionPool);
        $serializer = new MessageLlmSerializer($resourceFactory, $attachmentService);

        return new AgentService(
            $llmStub,
            $this->makeToolConverterService(),
            GeneralUtility::makeInstance(AgentToolRegistry::class),
            GeneralUtility::makeInstance(ExtensionConfiguration::class),
            $this->connectionPool,
            new AgentTaskRepository($this->connectionPool),
            $this->messageRepository,
            new TaskStateMachine(new AgentTaskRepository($this->connectionPool)),
            $attachmentService,
            $serializer,
            new AgentInstructionRepository($this->connectionPool),
            new InstructionTextFormatter(),
            new ChangeTracker($this->connectionPool, new AgentTaskRepository($this->connectionPool)),
        );
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $capturedMessages
     */
    private function buildAgentServiceCapturing(array &$capturedMessages, ResourceFactory $resourceFactory): AgentService
    {
        $llmStub = $this->createStub(LlmService::class);
        $llmStub->method('chatCompletionStream')->willReturnCallback(
            function (array $messages) use (&$capturedMessages): array {
                $capturedMessages[] = $messages;
                return ['role' => 'assistant', 'content' => 'OK.'];
            }
        );

        $attachmentService = new AttachmentService($resourceFactory, $this->connectionPool);
        $serializer = new MessageLlmSerializer($resourceFactory, $attachmentService);

        return new AgentService(
            $llmStub,
            $this->makeToolConverterService(),
            GeneralUtility::makeInstance(AgentToolRegistry::class),
            GeneralUtility::makeInstance(ExtensionConfiguration::class),
            $this->connectionPool,
            new AgentTaskRepository($this->connectionPool),
            $this->messageRepository,
            new TaskStateMachine(new AgentTaskRepository($this->connectionPool)),
            $attachmentService,
            $serializer,
            new AgentInstructionRepository($this->connectionPool),
            new InstructionTextFormatter(),
            new ChangeTracker($this->connectionPool, new AgentTaskRepository($this->connectionPool)),
        );
    }

    private function createInstruction(string $title, string $instruction, string $mode = 'always', string $description = '', int $hidden = 0, int $sorting = 0): int
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_agent_instruction');
        $connection->insert('tx_agent_instruction', [
            'pid' => 0,
            'title' => $title,
            'description' => $description,
            'instruction' => $instruction,
            'mode' => $mode,
            'hidden' => $hidden,
            'sorting' => $sorting,
            'deleted' => 0,
            'crdate' => time(),
            'tstamp' => time(),
        ]);
        return (int)$connection->lastInsertId();
    }

    private function loadSystemMessage(int $taskUid): array
    {
        $messages = $this->loadMessages($taskUid);
        self::assertNotEmpty($messages);
        self::assertSame('system', $messages[0]['role']);
        return $messages[0];
    }

    public function testAlwaysInstructionsAreInlinedIntoSystemPrompt(): void
    {
        $this->createInstruction('Tone of voice', 'Always write in a friendly, formal tone.', 'always', '', 0, 10);
        $this->createInstruction('News handling', 'Never delete news records, only hide them.', 'always', '', 0, 20);
        $this->createInstruction('Draft', 'This guidance is not active yet.', 'always', '', 1, 30);

        $taskUid = $this->createTask('Instructions test', 'Do something');
        $systemMsg = $this->loadSystemMessage($taskUid);
        $systemContent = (string)$systemMsg['content'];

        self::assertStringContainsString('Tone of voice', $systemContent);
        self::assertStringContainsString('Always write in a friendly, formal tone.', $systemContent);
        self::assertStringContainsString('News handling', $systemContent);
        self::assertStringContainsString('Never delete news records, only hide them.', $systemContent);
        self::assertStringNotContainsString('This guidance is not active yet.', $systemContent);
        self::assertLessThan(
            strpos($systemContent, 'News handling'),
            strpos($systemContent, 'Tone of voice'),
        );
    }

    public function testOnDemandInstructionsAreOnlyIndexedNotInlined(): void
    {
        $uid = $this->createInstruction(
            'News writing',
            'The full body: use active voice, max 60 chars in the teaser.',
            'on_demand',
            'When writing or revising news articles',
            0,
            10,
        );

        $taskUid = $this->createTask('On-demand test', 'Do something');
        $systemContent = (string)$this->loadSystemMessage($taskUid)['content'];

        self::assertStringContainsString('News writing', $systemContent);
        self::assertStringContainsString('When writing or revising news articles', $systemContent);
        self::assertStringContainsString('#' . $uid, $systemContent);
        self::assertStringContainsString('GetInstruction', $systemContent);
        self::assertStringNotContainsString('use active voice, max 60 chars', $systemContent);
    }

    public function testRteInstructionBodyIsConvertedToPlainTextInPrompt(): void
    {
        $this->createInstruction(
            'Formatting',
            '<p>Use <strong>active</strong> voice.</p><ul><li>Short sentences</li><li>No jargon</li></ul>',
            'always',
        );

        $taskUid = $this->createTask('RTE test', 'Do something');
        $systemContent = (string)$this->loadSystemMessage($taskUid)['content'];

        self::assertStringContainsString('**active**', $systemContent);
        self::assertStringContainsString('- Short sentences', $systemContent);
        self::assertStringNotContainsString('<p>', $systemContent);
        self::assertStringNotContainsString('<li>', $systemContent);
    }

    public function testNoInstructionsLeavesSystemPromptUntouched(): void
    {
        $taskUid = $this->createTask('No instructions', 'Do something');
        $systemContent = (string)$this->loadSystemMessage($taskUid)['content'];

        self::assertStringNotContainsString('Editorial guidelines', $systemContent);
        self::assertStringNotContainsString('On-demand instructions', $systemContent);
    }

    public function testSimpleResponseWithoutToolCalls(): void
    {
        $taskUid = $this->createTask('Test task', 'List all pages');

        $agentService = $this->buildAgentServiceWithMock([
            ['role' => 'assistant', 'content' => 'Here are the pages: Home, About.'],
        ]);
        $agentService->run($taskUid);

        $task = $this->getTask($taskUid);
        self::assertSame(2, (int)$task['status']);
        self::assertSame('Here are the pages: Home, About.', $task['result']);

        $messages = $this->loadMessages($taskUid);
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
        $agentService->run($taskUid);

        $task = $this->getTask($taskUid);
        self::assertSame(2, (int)$task['status']);
        self::assertSame('The page tree has: Home, About.', $task['result']);

        $messages = $this->loadMessages($taskUid);
        self::assertCount(5, $messages);
        self::assertSame('tool', $messages[3]['role']);
        self::assertSame('call_001', $messages[3]['tool_call_id']);
    }

    public function testResumeFromExistingMessages(): void
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_agent_task');
        $connection->insert('tx_agent_task', [
            'pid' => 0, 'title' => 'Resume test', 'prompt' => 'List all pages',
            'status' => 0, 'result' => '', 'cruser_id' => 1,
            'crdate' => time(), 'tstamp' => time(), 'deleted' => 0, 'hidden' => 0,
        ]);
        $taskUid = (int)$connection->lastInsertId();

        $this->insertRawMessage($taskUid, 100, ['role' => 'system', 'content' => 'You are a TYPO3 assistant.']);
        $this->insertRawMessage($taskUid, 200, ['role' => 'user', 'content' => 'List all pages']);
        $this->insertRawMessage($taskUid, 300, [
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => [
                ['id' => 'call_001', 'type' => 'function', 'function' => ['name' => 'GetPageTree', 'arguments' => '{}']],
            ],
        ]);
        $this->insertRawMessage($taskUid, 400, [
            'role' => 'tool', 'tool_call_id' => 'call_001', 'tool_name' => 'GetPageTree', 'content' => 'Home, About',
        ]);

        $agentService = $this->buildAgentServiceWithMock([
            ['role' => 'assistant', 'content' => 'The pages are: Home and About.'],
        ]);
        $agentService->run($taskUid);

        $task = $this->getTask($taskUid);
        self::assertSame(2, (int)$task['status']);
        self::assertSame('The pages are: Home and About.', $task['result']);

        $messages = $this->loadMessages($taskUid);
        self::assertCount(5, $messages);
    }

    public function testFailedTaskPreservesMessages(): void
    {
        $taskUid = $this->createTask('Fail test', 'Do something');

        $llmStub = $this->createStub(LlmService::class);
        $llmStub->method('chatCompletionStream')->willThrowException(
            new \RuntimeException('API connection failed')
        );

        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
        $attachmentService = new AttachmentService($resourceFactory, $this->connectionPool);
        $serializer = new MessageLlmSerializer($resourceFactory, $attachmentService);

        $agentService = new AgentService(
            $llmStub,
            $this->makeToolConverterService(),
            GeneralUtility::makeInstance(AgentToolRegistry::class),
            GeneralUtility::makeInstance(ExtensionConfiguration::class),
            $this->connectionPool,
            new AgentTaskRepository($this->connectionPool),
            $this->messageRepository,
            new TaskStateMachine(new AgentTaskRepository($this->connectionPool)),
            $attachmentService,
            $serializer,
            new AgentInstructionRepository($this->connectionPool),
            new InstructionTextFormatter(),
            new ChangeTracker($this->connectionPool, new AgentTaskRepository($this->connectionPool)),
        );

        try {
            $agentService->run($taskUid);
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('API connection failed', $e->getMessage());
        }

        $task = $this->getTask($taskUid);
        self::assertSame(3, (int)$task['status']);
        self::assertStringContainsString('Error:', $task['result']);

        $messages = $this->loadMessages($taskUid);
        self::assertCount(2, $messages);
    }

    public function testPageContextIncludedWhenPidSet(): void
    {
        $taskUid = $this->createTask('Page context test', 'Describe this page', 1);

        $agentService = $this->buildAgentServiceWithMock([
            ['role' => 'assistant', 'content' => 'This is the Home page.'],
        ]);
        $agentService->run($taskUid);

        $messages = $this->loadMessages($taskUid);

        // [0] system, [1] user(prompt), [2] assistant(narration + GetPage tool_call),
        // [3] tool(GetPage result), [4] assistant(final mock response)
        self::assertSame('user', $messages[1]['role']);
        self::assertSame('Describe this page', $messages[1]['content']);
        self::assertSame('assistant', $messages[2]['role']);
        self::assertIsString($messages[2]['content']);
        self::assertStringContainsString('Arbeitskontext', $messages[2]['content']);
        self::assertStringContainsString('#1', $messages[2]['content']);
        self::assertNotEmpty($messages[2]['tool_calls']);
        self::assertSame('GetPage', $messages[2]['tool_calls'][0]['function']['name']);
        self::assertSame('tool', $messages[3]['role']);
    }

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

    public function testImageAttachmentInlinesAsImageBlockForLlm(): void
    {
        // Image within size cap → serializer inlines it as an image_url block
        // (LLM sees the bytes directly). content='DEADBEEF' proves getContents()
        // is invoked exactly once during LLM serialization.
        $file = $this->buildFileMock(101, 'image/png', 2048, 'pixel.png', '1:/uploads/pixel.png', 'DEADBEEF');
        $resourceFactory = $this->buildResourceFactoryReturning(101, $file);

        $capturedMessages = [];
        $agentService = $this->buildAgentServiceCapturing($capturedMessages, $resourceFactory);

        $taskUid = $this->createTask('Image test', 'Initial');
        $agentService->run($taskUid, 'Was siehst du?', null, [['uid' => 101]]);

        self::assertNotEmpty($capturedMessages);
        $userMsg = $this->findLlmUserMessage($capturedMessages[0], 'Was siehst du?');
        self::assertNotNull($userMsg, 'User message reached LlmService');

        self::assertIsArray($userMsg['content'], 'Inline image → block-array content');
        self::assertSame('text', $userMsg['content'][0]['type']);
        self::assertStringContainsString('Was siehst du?', $userMsg['content'][0]['text']);
        self::assertSame('image_url', $userMsg['content'][1]['type']);
        self::assertStringStartsWith('data:image/png;base64,', $userMsg['content'][1]['image_url']['url']);

        // Even for an inlined image the text block carries a sys_file marker so
        // the LLM knows the UID; the note tells it no viewer tool is needed.
        self::assertStringContainsString('sys_file:101', $userMsg['content'][0]['text']);
        self::assertStringContainsString('bereits inline eingebettet', $userMsg['content'][0]['text']);
    }

    public function testPdfAttachmentMarkerPointsLlmToReadFile(): void
    {
        // PDFs stay marker-only → the LLM has to call ReadFile.
        $file = $this->buildFileMock(202, 'application/pdf', 4096, 'doc.pdf', '1:/uploads/doc.pdf', null);
        $resourceFactory = $this->buildResourceFactoryReturning(202, $file);

        $capturedMessages = [];
        $agentService = $this->buildAgentServiceCapturing($capturedMessages, $resourceFactory);

        $taskUid = $this->createTask('PDF test', 'Initial');
        $agentService->run($taskUid, 'Fass zusammen.', null, [['uid' => 202]]);

        $userMsg = $this->findLlmUserMessage($capturedMessages[0], 'Fass zusammen.');
        self::assertNotNull($userMsg);
        self::assertIsString($userMsg['content']);
        self::assertStringContainsString('sys_file:202', $userMsg['content']);
        self::assertStringContainsString('application/pdf', $userMsg['content']);
        self::assertStringContainsString('ReadFile', $userMsg['content']);
    }

    public function testOversizedImageMarkerWarnsLlmNotToCallReadFile(): void
    {
        $file = $this->buildFileMock(303, 'image/png', 6 * 1024 * 1024, 'huge.png', '1:/uploads/huge.png', null);
        $resourceFactory = $this->buildResourceFactoryReturning(303, $file);

        $capturedMessages = [];
        $agentService = $this->buildAgentServiceCapturing($capturedMessages, $resourceFactory);

        $taskUid = $this->createTask('Oversize test', 'Initial');
        $agentService->run($taskUid, 'Trotzdem?', null, [['uid' => 303]]);

        $userMsg = $this->findLlmUserMessage($capturedMessages[0], 'Trotzdem?');
        self::assertNotNull($userMsg);
        self::assertIsString($userMsg['content']);
        self::assertStringContainsString('sys_file:303', $userMsg['content']);
        self::assertStringContainsString('zu groß', $userMsg['content']);
        self::assertStringContainsString('nicht abrufbar', $userMsg['content']);
    }

    public function testUnsupportedMimeMarkerPointsLlmToReadFileMetadata(): void
    {
        $file = $this->buildFileMock(404, 'application/zip', 100, 'archive.zip', '1:/uploads/archive.zip', null);
        $resourceFactory = $this->buildResourceFactoryReturning(404, $file);

        $capturedMessages = [];
        $agentService = $this->buildAgentServiceCapturing($capturedMessages, $resourceFactory);

        $taskUid = $this->createTask('Unsupported mime test', 'Initial');
        $agentService->run($taskUid, 'Schau mal.', null, [['uid' => 404]]);

        $userMsg = $this->findLlmUserMessage($capturedMessages[0], 'Schau mal.');
        self::assertNotNull($userMsg);
        self::assertIsString($userMsg['content']);
        self::assertStringContainsString('sys_file:404', $userMsg['content']);
        self::assertStringContainsString('application/zip', $userMsg['content']);
        self::assertStringContainsString('Inhalt nicht direkt lesbar', $userMsg['content']);
        self::assertStringContainsString('ReadFile', $userMsg['content']);
        self::assertStringNotContainsString('zu groß', $userMsg['content']);
    }

    public function testPreviewAttachmentReportsImageEmbeddable(): void
    {
        $file = $this->buildFileMock(101, 'image/png', 2048, 'pixel.png', '1:/uploads/pixel.png', null);
        $resourceFactory = $this->buildResourceFactoryReturning(101, $file);

        $preview = (new AttachmentService($resourceFactory, $this->connectionPool))->preview(['uid' => 101]);

        self::assertSame(101, $preview['uid']);
        self::assertSame('image/png', $preview['mime']);
        self::assertSame(2048, $preview['size']);
        self::assertTrue($preview['readableByLlm']);
        self::assertNull($preview['reason']);
    }

    public function testPreviewAttachmentReportsOversizeReason(): void
    {
        $file = $this->buildFileMock(303, 'image/png', 6 * 1024 * 1024, 'huge.png', '1:/uploads/huge.png', null);
        $resourceFactory = $this->buildResourceFactoryReturning(303, $file);

        $preview = (new AttachmentService($resourceFactory, $this->connectionPool))->preview(['uid' => 303]);

        self::assertFalse($preview['readableByLlm']);
        self::assertNotNull($preview['reason']);
        self::assertStringContainsString('zu groß', $preview['reason']);
        self::assertStringContainsString('MiB', $preview['reason']);
    }

    public function testPreviewAttachmentReportsUnsupportedMime(): void
    {
        $file = $this->buildFileMock(404, 'application/zip', 100, 'archive.zip', '1:/uploads/archive.zip', null);
        $resourceFactory = $this->buildResourceFactoryReturning(404, $file);

        $preview = (new AttachmentService($resourceFactory, $this->connectionPool))->preview(['uid' => 404]);

        self::assertFalse($preview['readableByLlm']);
        self::assertSame('Format nicht unterstützt', $preview['reason']);
    }

    public function testPreviewAttachmentReportsUnresolvable(): void
    {
        $attachmentService = new AttachmentService(
            GeneralUtility::makeInstance(ResourceFactory::class),
            $this->connectionPool,
        );

        $preview = $attachmentService->preview(['uid' => 999999]);

        self::assertFalse($preview['readableByLlm']);
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
        $agentService->run($taskUid, null, $progress);

        // Fresh tasks emit user_message + llm_start + assistant_message.
        self::assertCount(3, $calls);

        self::assertSame('user_message', $calls[0][0]);
        self::assertSame('Hello', $calls[0][1]['message']['content']);

        self::assertSame('llm_start', $calls[1][0]);

        self::assertSame('assistant_message', $calls[2][0]);
        self::assertSame('Done.', $calls[2][1]['message']['content']);
    }
}
