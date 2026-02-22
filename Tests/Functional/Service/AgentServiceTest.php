<?php

declare(strict_types=1);

namespace Hn\Agent\Tests\Functional\Service;

use Hn\Agent\Service\AgentService;
use Hn\Agent\Service\LlmService;
use Hn\Agent\Service\ToolConverterService;
use Hn\McpServer\MCP\ToolRegistry;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
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
    private function createTask(string $title, string $prompt, int $pid = 0, int $status = 0, ?string $messages = null): int
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
        $llmMock->method('chatCompletion')->willReturnCallback(
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
        $existingMessages = json_encode([
            ['role' => 'system', 'content' => 'You are a TYPO3 assistant.'],
            ['role' => 'user', 'content' => 'List all pages'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'call_001', 'type' => 'function', 'function' => ['name' => 'GetPageTree', 'arguments' => '{}']],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'call_001', 'content' => 'Home, About'],
        ]);

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
        $llmMock->method('chatCompletion')->willThrowException(
            new \RuntimeException('API connection failed')
        );

        $agentService = new AgentService(
            $llmMock,
            GeneralUtility::makeInstance(ToolConverterService::class),
            GeneralUtility::makeInstance(ToolRegistry::class),
            GeneralUtility::makeInstance(ExtensionConfiguration::class),
            $this->connectionPool,
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

        // System message should contain page context (either actual page data or error message)
        $systemContent = $messages[0]['content'] ?? '';
        self::assertStringContainsString('page context', strtolower($systemContent));
    }

    public function testProgressCallbackIsCalled(): void
    {
        $taskUid = $this->createTask('Callback test', 'Hello');

        $agentService = $this->buildAgentServiceWithMock([
            ['role' => 'assistant', 'content' => 'Done.'],
        ]);

        $iterations = [];
        $agentService->processTask($taskUid, function (int $iteration, array $message) use (&$iterations) {
            $iterations[] = $iteration;
        });

        self::assertSame([0], $iterations);
    }
}
