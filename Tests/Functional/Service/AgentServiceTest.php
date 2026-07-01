<?php

declare(strict_types=1);

namespace Hn\Agent\Tests\Functional\Service;

use Hn\Agent\Domain\AgentInstructionRepository;
use Hn\Agent\Domain\AgentTaskRepository;
use Hn\Agent\Domain\TaskStateMachine;
use Hn\Agent\Service\AgentService;
use Hn\Agent\Service\AttachmentService;
use Hn\Agent\Service\ChangeTracker;
use Hn\Agent\Service\InstructionTextFormatter;
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
     *
     * Mirrors what ChatController::newAction does in production: when no
     * pre-built $messages array is provided, the initial conversation
     * (system + synthetic GetPage/ReadTable context + user message) is
     * synthesized via AgentService::buildInitialMessages and persisted.
     * Pass a non-null $messages to bypass the synthesis (resume scenarios).
     */
    private function createTask(string $title, string $prompt, int $pid = 0, int $status = 0, ?array $messages = null, string $contextTable = '', int $contextUid = 0): int
    {
        if ($messages === null) {
            $messages = $this->buildAgentServiceWithMock([])
                ->buildInitialMessages($pid, $contextTable, $contextUid, $prompt);
        }

        $connection = $this->connectionPool->getConnectionForTable('tx_agent_task');
        $connection->insert(
            'tx_agent_task',
            [
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
            ],
            ['messages' => \Doctrine\DBAL\Types\Types::JSON],
        );
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
        $llmStub = $this->createStub(LlmService::class);
        $llmStub->method('chatCompletionStream')->willReturnCallback(
            function () use (&$callIndex, $responses) {
                if ($callIndex >= count($responses)) {
                    throw new \RuntimeException('LlmService mock exhausted: no more responses');
                }
                return $responses[$callIndex++];
            }
        );

        return new AgentService(
            $llmStub,
            GeneralUtility::makeInstance(ToolConverterService::class),
            GeneralUtility::makeInstance(ToolRegistry::class),
            GeneralUtility::makeInstance(ExtensionConfiguration::class),
            $this->connectionPool,
            new AgentTaskRepository($this->connectionPool),
            new TaskStateMachine(new AgentTaskRepository($this->connectionPool)),
            new AttachmentService(GeneralUtility::makeInstance(\TYPO3\CMS\Core\Resource\ResourceFactory::class), $this->connectionPool),
            new AgentInstructionRepository($this->connectionPool),
            new InstructionTextFormatter(),
            new ChangeTracker($this->connectionPool, new AgentTaskRepository($this->connectionPool)),
        );
    }

    /**
     * Insert a tx_agent_instruction record and return its UID.
     */
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

    public function testAlwaysInstructionsAreInlinedIntoSystemPrompt(): void
    {
        $this->createInstruction('Tone of voice', 'Always write in a friendly, formal tone.', 'always', '', 0, 10);
        $this->createInstruction('News handling', 'Never delete news records, only hide them.', 'always', '', 0, 20);
        // Hidden instruction must be excluded.
        $this->createInstruction('Draft', 'This guidance is not active yet.', 'always', '', 1, 30);

        $messages = $this->buildAgentServiceWithMock([])
            ->buildInitialMessages(0, '', 0, 'Do something');

        self::assertSame('system', $messages[0]['role']);
        $systemContent = $messages[0]['content'];
        self::assertStringContainsString('Tone of voice', $systemContent);
        self::assertStringContainsString('Always write in a friendly, formal tone.', $systemContent);
        self::assertStringContainsString('News handling', $systemContent);
        self::assertStringContainsString('Never delete news records, only hide them.', $systemContent);
        self::assertStringNotContainsString('This guidance is not active yet.', $systemContent);
        // Ordering follows the sorting field.
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

        $messages = $this->buildAgentServiceWithMock([])
            ->buildInitialMessages(0, '', 0, 'Do something');

        $systemContent = $messages[0]['content'];
        // Index: name + "when to use" + the uid the agent passes to GetInstruction.
        self::assertStringContainsString('News writing', $systemContent);
        self::assertStringContainsString('When writing or revising news articles', $systemContent);
        self::assertStringContainsString('#' . $uid, $systemContent);
        self::assertStringContainsString('GetInstruction', $systemContent);
        // The full body must NOT be inlined for on-demand instructions.
        self::assertStringNotContainsString('use active voice, max 60 chars', $systemContent);
    }

    public function testRteInstructionBodyIsConvertedToPlainTextInPrompt(): void
    {
        $this->createInstruction(
            'Formatting',
            '<p>Use <strong>active</strong> voice.</p><ul><li>Short sentences</li><li>No jargon</li></ul>',
            'always',
        );

        $messages = $this->buildAgentServiceWithMock([])
            ->buildInitialMessages(0, '', 0, 'Do something');

        $systemContent = $messages[0]['content'];
        self::assertStringContainsString('**active**', $systemContent);
        self::assertStringContainsString('- Short sentences', $systemContent);
        // No raw HTML tags should leak into the prompt.
        self::assertStringNotContainsString('<p>', $systemContent);
        self::assertStringNotContainsString('<li>', $systemContent);
    }

    public function testNoInstructionsLeavesSystemPromptUntouched(): void
    {
        $messages = $this->buildAgentServiceWithMock([])
            ->buildInitialMessages(0, '', 0, 'Do something');

        self::assertSame('system', $messages[0]['role']);
        self::assertStringNotContainsString('Editorial guidelines', $messages[0]['content']);
        self::assertStringNotContainsString('On-demand instructions', $messages[0]['content']);
    }

    public function testSimpleResponseWithoutToolCalls(): void
    {
        $taskUid = $this->createTask('Test task', 'List all pages');

        $agentService = $this->buildAgentServiceWithMock([
            ['role' => 'assistant', 'content' => 'Here are the pages: Home, About.'],
        ]);

        $agentService->run($taskUid);

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

        $agentService->run($taskUid);

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

        $agentService->run($taskUid);

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

        $llmStub = $this->createStub(LlmService::class);
        $llmStub->method('chatCompletionStream')->willThrowException(
            new \RuntimeException('API connection failed')
        );

        $agentService = new AgentService(
            $llmStub,
            GeneralUtility::makeInstance(ToolConverterService::class),
            GeneralUtility::makeInstance(ToolRegistry::class),
            GeneralUtility::makeInstance(ExtensionConfiguration::class),
            $this->connectionPool,
            new AgentTaskRepository($this->connectionPool),
            new TaskStateMachine(new AgentTaskRepository($this->connectionPool)),
            new AttachmentService(GeneralUtility::makeInstance(\TYPO3\CMS\Core\Resource\ResourceFactory::class), $this->connectionPool),
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

        $agentService->run($taskUid);

        $task = $this->getTask($taskUid);
        $messages = $this->decodeMessages($task['messages']);

        // Expected shape after buildMessages() — user prompt precedes the
        // synthetic context turn so the chat UI renders them in that order:
        // [0] system, [1] user(prompt), [2] assistant(narration content +
        // GetPage tool_call), [3] tool(GetPage result),
        // [4] assistant(final mock response)
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

    public function testContinueChatPersistsAttachmentsStructuredAndSerializesForLlm(): void
    {
        $taskUid = $this->createTask('Attachment test', 'Initial prompt');

        // Capture what is handed to LlmService so we can prove the markdown
        // block exists in the LLM payload — but NOT in the persisted state.
        $capturedMessages = [];
        $llmStub = $this->createStub(LlmService::class);
        $llmStub->method('chatCompletionStream')->willReturnCallback(
            function (array $messages) use (&$capturedMessages): array {
                $capturedMessages[] = $messages;
                return ['role' => 'assistant', 'content' => 'OK.'];
            }
        );

        $agentService = new AgentService(
            $llmStub,
            GeneralUtility::makeInstance(ToolConverterService::class),
            GeneralUtility::makeInstance(ToolRegistry::class),
            GeneralUtility::makeInstance(ExtensionConfiguration::class),
            $this->connectionPool,
            new AgentTaskRepository($this->connectionPool),
            new TaskStateMachine(new AgentTaskRepository($this->connectionPool)),
            new AttachmentService(GeneralUtility::makeInstance(\TYPO3\CMS\Core\Resource\ResourceFactory::class), $this->connectionPool),
            new AgentInstructionRepository($this->connectionPool),
            new InstructionTextFormatter(),
            new ChangeTracker($this->connectionPool, new AgentTaskRepository($this->connectionPool)),
        );

        // Pass an unresolvable attachment (no such sys_file UID) — keeps the
        // test independent from FAL fixtures while still exercising the full
        // resolveAttachmentRefs → serializeForLlm pipeline.
        $attachments = [
            ['uid' => 999999, 'name' => 'phantom.pdf'],
        ];
        $agentService->run($taskUid, 'Look at this', null, $attachments);

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
        self::assertStringContainsString('Angehängte Dateien', $llmUserMsg['content']);
        self::assertStringContainsString('phantom.pdf', $llmUserMsg['content']);
        self::assertStringContainsString('nicht auflösbar', $llmUserMsg['content']);
    }

    /**
     * @param-out array $capturedMessages
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

        return new AgentService(
            $llmStub,
            GeneralUtility::makeInstance(ToolConverterService::class),
            GeneralUtility::makeInstance(ToolRegistry::class),
            GeneralUtility::makeInstance(ExtensionConfiguration::class),
            $this->connectionPool,
            new AgentTaskRepository($this->connectionPool),
            new TaskStateMachine(new AgentTaskRepository($this->connectionPool)),
            new AttachmentService($resourceFactory, $this->connectionPool),
            new AgentInstructionRepository($this->connectionPool),
            new InstructionTextFormatter(),
            new ChangeTracker($this->connectionPool, new AgentTaskRepository($this->connectionPool)),
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
        $factory->expects(self::atLeastOnce())->method('getFileObject')->with($uid)->willReturn($file);
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

    public function testImageAttachmentStaysMarkerOnlyForViewImage(): void
    {
        // The image is *not* embedded into the user message — the LLM has
        // to call the ViewImage tool to actually see the bytes. content=null
        // on the mock asserts that getContents() is never read during
        // serialization (file bytes only flow through ViewImage).
        $file = $this->buildFileMock(101, 'image/png', 2048, 'pixel.png', '1:/uploads/pixel.png', null);
        $resourceFactory = $this->buildResourceFactoryReturning(101, $file);

        $capturedMessages = [];
        $agentService = $this->buildAgentServiceCapturing($capturedMessages, $resourceFactory);

        $taskUid = $this->createTask('Image test', 'Initial');
        $agentService->run($taskUid, 'Was siehst du?', null, [['uid' => 101]]);

        self::assertNotEmpty($capturedMessages);
        $userMsg = $this->findLlmUserMessage($capturedMessages[0], 'Was siehst du?');
        self::assertNotNull($userMsg, 'User message reached LlmService');

        self::assertIsString($userMsg['content'], 'Content stays plain text — files reach the LLM only via ViewImage');
        self::assertStringContainsString('Was siehst du?', $userMsg['content']);
        self::assertStringContainsString('sys_file:101', $userMsg['content']);
        self::assertStringContainsString('image/png', $userMsg['content']);
        self::assertStringContainsString('ViewImage', $userMsg['content']);
    }

    public function testPdfAttachmentMarkerPointsLlmToReadPdfText(): void
    {
        // PDFs are read via ReadPdfText (text) or ViewPdfPage (page as image).
        // content=null asserts no bytes are ever read at marker time.
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
        self::assertStringContainsString('ReadPdfText', $userMsg['content']);
        self::assertStringContainsString('ViewPdfPage', $userMsg['content']);
    }

    public function testOversizedImageMarkerWarnsLlmNotToCallViewImage(): void
    {
        // 6 MiB > 5 MiB image cap. content=null asserts getContents() is never invoked.
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

    public function testUnsupportedMimeMarkerPointsLlmToGetFileInfo(): void
    {
        // application/zip isn't on any viewer-tool allowlist — even though
        // small, the marker must stay metadata-only via GetFileInfo.
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
        self::assertStringContainsString('GetFileInfo', $userMsg['content']);
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
        // real ResourceFactory — uid 999999 will not resolve, falls into catch
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

        // Fresh tasks emit a `user_message` event up front so the UI can render
        // the prompt in the same slot it occupies in the persisted state.
        self::assertCount(3, $calls);

        self::assertSame('user_message', $calls[0][0]);
        self::assertSame('Hello', $calls[0][1]['message']['content']);

        self::assertSame('llm_start', $calls[1][0]);

        self::assertSame('assistant_message', $calls[2][0]);
        self::assertSame('Done.', $calls[2][1]['message']['content']);
    }

    /**
     * When a tool returns ImageContent (e.g. ViewImage on a sys_file), the
     * resulting tool message stores `content` directly as a block array
     * [text, image_url]. Same shape is sent to the LLM (serializeForLlm
     * is a no-op for tool messages) and persisted in the DB.
     */
    public function testToolMessageWithImageMediaStoresBlockArrayContent(): void
    {
        $existingMessages = [
            ['role' => 'system', 'content' => 'sys'],
            ['role' => 'user', 'content' => 'show me'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'call_img', 'type' => 'function', 'function' => ['name' => 'ViewImage', 'arguments' => '{"uid":42}']],
            ]],
            [
                'role' => 'tool',
                'tool_call_id' => 'call_img',
                'content' => [
                    ['type' => 'text', 'text' => "File: pixel.png\nMIME: image/png\nSize: 70 B\nUID: sys_file:42"],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,AAAA']],
                ],
            ],
        ];

        $taskUid = $this->createTask('Tool media test', 'show me', 0, 0, $existingMessages);

        $capturedMessages = [];
        $llmStub = $this->createStub(LlmService::class);
        $llmStub->method('chatCompletionStream')->willReturnCallback(
            function (array $messages) use (&$capturedMessages): array {
                $capturedMessages[] = $messages;
                return ['role' => 'assistant', 'content' => 'Seen.'];
            }
        );

        $agentService = new AgentService(
            $llmStub,
            GeneralUtility::makeInstance(ToolConverterService::class),
            GeneralUtility::makeInstance(ToolRegistry::class),
            GeneralUtility::makeInstance(ExtensionConfiguration::class),
            $this->connectionPool,
            new AgentTaskRepository($this->connectionPool),
            new TaskStateMachine(new AgentTaskRepository($this->connectionPool)),
            new AttachmentService(GeneralUtility::makeInstance(\TYPO3\CMS\Core\Resource\ResourceFactory::class), $this->connectionPool),
            new AgentInstructionRepository($this->connectionPool),
            new InstructionTextFormatter(),
            new ChangeTracker($this->connectionPool, new AgentTaskRepository($this->connectionPool)),
        );
        $agentService->run($taskUid);

        self::assertNotEmpty($capturedMessages, 'LlmService received a payload');
        $llmMessages = $capturedMessages[0];

        $toolIndex = null;
        foreach ($llmMessages as $i => $m) {
            if (($m['role'] ?? '') === 'tool' && ($m['tool_call_id'] ?? '') === 'call_img') {
                $toolIndex = $i;
                break;
            }
        }
        self::assertNotNull($toolIndex, 'Tool message reached LlmService');

        $toolMsg = $llmMessages[$toolIndex];
        self::assertIsArray($toolMsg['content'], 'tool_result content reaches LLM as block array');
        self::assertSame('text', $toolMsg['content'][0]['type']);
        self::assertStringContainsString('pixel.png', $toolMsg['content'][0]['text']);
        self::assertSame('image_url', $toolMsg['content'][1]['type']);
        self::assertSame('data:image/png;base64,AAAA', $toolMsg['content'][1]['image_url']['url']);
        self::assertArrayNotHasKey('_media', $toolMsg, 'No legacy split — content carries the media directly');

        // Persisted record carries the same block array — no transformation
        // between storage and LLM call.
        $task = $this->getTask($taskUid);
        $persisted = $this->decodeMessages($task['messages']);
        $persistedTool = null;
        foreach ($persisted as $m) {
            if (($m['role'] ?? '') === 'tool' && ($m['tool_call_id'] ?? '') === 'call_img') {
                $persistedTool = $m;
                break;
            }
        }
        self::assertNotNull($persistedTool);
        self::assertIsArray($persistedTool['content'], 'Persisted content is a block array');
        self::assertSame('image_url', $persistedTool['content'][1]['type']);
        self::assertSame('data:image/png;base64,AAAA', $persistedTool['content'][1]['image_url']['url']);
        self::assertArrayNotHasKey('_media', $persistedTool);
    }

    /**
     * PDFs follow the same inline path as images: the tool message content
     * becomes a block array carrying text + a `file` block with the PDF bytes.
     */
    public function testToolMessageWithPdfMediaInlinesFileBlock(): void
    {
        $existingMessages = [
            ['role' => 'system', 'content' => 'sys'],
            ['role' => 'user', 'content' => 'show pdf'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'call_pdf', 'type' => 'function', 'function' => ['name' => 'ViewImage', 'arguments' => '{"uid":48}']],
            ]],
            [
                'role' => 'tool',
                'tool_call_id' => 'call_pdf',
                'content' => [
                    ['type' => 'text', 'text' => "File: doc.pdf\nMIME: application/pdf\nSize: 1.0 KiB\nUID: sys_file:48"],
                    ['type' => 'file', 'file' => ['filename' => 'doc.pdf', 'file_data' => 'data:application/pdf;base64,BBBB']],
                ],
            ],
        ];

        $taskUid = $this->createTask('Tool pdf test', 'show pdf', 0, 0, $existingMessages);

        $capturedMessages = [];
        $llmStub = $this->createStub(LlmService::class);
        $llmStub->method('chatCompletionStream')->willReturnCallback(
            function (array $messages) use (&$capturedMessages): array {
                $capturedMessages[] = $messages;
                return ['role' => 'assistant', 'content' => 'Got it.'];
            }
        );

        $agentService = new AgentService(
            $llmStub,
            GeneralUtility::makeInstance(ToolConverterService::class),
            GeneralUtility::makeInstance(ToolRegistry::class),
            GeneralUtility::makeInstance(ExtensionConfiguration::class),
            $this->connectionPool,
            new AgentTaskRepository($this->connectionPool),
            new TaskStateMachine(new AgentTaskRepository($this->connectionPool)),
            new AttachmentService(GeneralUtility::makeInstance(\TYPO3\CMS\Core\Resource\ResourceFactory::class), $this->connectionPool),
            new AgentInstructionRepository($this->connectionPool),
            new InstructionTextFormatter(),
            new ChangeTracker($this->connectionPool, new AgentTaskRepository($this->connectionPool)),
        );
        $agentService->run($taskUid);

        $llmMessages = $capturedMessages[0];

        $toolIndex = null;
        foreach ($llmMessages as $i => $m) {
            if (($m['role'] ?? '') === 'tool' && ($m['tool_call_id'] ?? '') === 'call_pdf') {
                $toolIndex = $i;
                break;
            }
        }
        self::assertNotNull($toolIndex);

        $toolMsg = $llmMessages[$toolIndex];
        self::assertIsArray($toolMsg['content'], 'tool_result content becomes a block array with text + file');
        self::assertSame('text', $toolMsg['content'][0]['type']);
        self::assertStringContainsString('doc.pdf', $toolMsg['content'][0]['text']);
        self::assertSame('file', $toolMsg['content'][1]['type']);
        self::assertSame('doc.pdf', $toolMsg['content'][1]['file']['filename']);
        self::assertSame('data:application/pdf;base64,BBBB', $toolMsg['content'][1]['file']['file_data']);
        self::assertArrayNotHasKey('_media', $toolMsg);
    }

    /**
     * Two consecutive ViewImage calls in one assistant turn produce two
     * `tool` messages. Each carries its own media inline — the resulting
     * message tail is `assistant(tool_calls), tool(a), tool(b)` with each
     * tool message holding its own `file` block.
     */
    public function testMultiplePdfToolResultsInlineMediaEach(): void
    {
        $existingMessages = [
            ['role' => 'system', 'content' => 'sys'],
            ['role' => 'user', 'content' => 'show both'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'ViewImage', 'arguments' => '{"uid":1}']],
                ['id' => 'call_b', 'type' => 'function', 'function' => ['name' => 'ViewImage', 'arguments' => '{"uid":2}']],
            ]],
            [
                'role' => 'tool', 'tool_call_id' => 'call_a',
                'content' => [
                    ['type' => 'text', 'text' => 'File: a.pdf'],
                    ['type' => 'file', 'file' => ['filename' => 'a.pdf', 'file_data' => 'data:application/pdf;base64,AAAA']],
                ],
            ],
            [
                'role' => 'tool', 'tool_call_id' => 'call_b',
                'content' => [
                    ['type' => 'text', 'text' => 'File: b.pdf'],
                    ['type' => 'file', 'file' => ['filename' => 'b.pdf', 'file_data' => 'data:application/pdf;base64,CCCC']],
                ],
            ],
        ];

        $taskUid = $this->createTask('Multi pdf', 'show both', 0, 0, $existingMessages);

        $capturedMessages = [];
        $llmStub = $this->createStub(LlmService::class);
        $llmStub->method('chatCompletionStream')->willReturnCallback(
            function (array $messages) use (&$capturedMessages): array {
                $capturedMessages[] = $messages;
                return ['role' => 'assistant', 'content' => 'OK.'];
            }
        );

        $agentService = new AgentService(
            $llmStub,
            GeneralUtility::makeInstance(ToolConverterService::class),
            GeneralUtility::makeInstance(ToolRegistry::class),
            GeneralUtility::makeInstance(ExtensionConfiguration::class),
            $this->connectionPool,
            new AgentTaskRepository($this->connectionPool),
            new TaskStateMachine(new AgentTaskRepository($this->connectionPool)),
            new AttachmentService(GeneralUtility::makeInstance(\TYPO3\CMS\Core\Resource\ResourceFactory::class), $this->connectionPool),
            new AgentInstructionRepository($this->connectionPool),
            new InstructionTextFormatter(),
            new ChangeTracker($this->connectionPool, new AgentTaskRepository($this->connectionPool)),
        );
        $agentService->run($taskUid);

        $llmMessages = $capturedMessages[0];

        $roles = array_map(static fn(array $m): string => (string)($m['role'] ?? ''), $llmMessages);
        $tail = array_slice($roles, -3);
        self::assertSame(['assistant', 'tool', 'tool'], $tail, 'Tool messages stay contiguous, each carries its own inline media');

        $toolMsgs = array_values(array_filter(
            $llmMessages,
            static fn(array $m): bool => ($m['role'] ?? '') === 'tool',
        ));
        self::assertCount(2, $toolMsgs);
        self::assertSame('a.pdf', $toolMsgs[0]['content'][1]['file']['filename']);
        self::assertSame('data:application/pdf;base64,AAAA', $toolMsgs[0]['content'][1]['file']['file_data']);
        self::assertSame('b.pdf', $toolMsgs[1]['content'][1]['file']['filename']);
        self::assertSame('data:application/pdf;base64,CCCC', $toolMsgs[1]['content'][1]['file']['file_data']);
    }
}
