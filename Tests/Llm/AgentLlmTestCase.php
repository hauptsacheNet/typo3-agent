<?php

declare(strict_types=1);

namespace Hn\Agent\Tests\Llm;

use Hn\Agent\Domain\TaskStatus;
use Hn\Agent\Service\AgentService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Base class for tests that run the REAL agent loop against a REAL language
 * model via OpenRouter. Unlike the functional tests (mocked LlmService),
 * these verify that an actual LLM understands our tool schemas and drives
 * the tools the way we intend — the control criterion for shipping new
 * tools and workflows.
 *
 * Requires OPENROUTER_API_KEY (env or .env.local); tests are skipped
 * without it. Model and endpoint can be overridden via LLM_TEST_MODEL and
 * LLM_TEST_API_URL.
 *
 * Mirrors Tests/Llm in hn/typo3-mcp-server, but instead of orchestrating
 * the tool conversation in the test, the extension's own AgentService loop
 * does the driving — so prompt building, streaming, tool execution and
 * task state handling are all exercised end-to-end.
 *
 * @group llm
 */
abstract class AgentLlmTestCase extends FunctionalTestCase
{
    /**
     * Cheapest tool-capable model from the roster used by
     * hn/typo3-mcp-server's LLM tests (~$0.03/M input tokens on OpenRouter,
     * two orders of magnitude below claude-haiku-4.5).
     */
    protected const DEFAULT_MODEL = 'openai/gpt-oss-120b';

    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
        'agent',
    ];

    /** 1 = passed first try, 2/3 = needed retries. */
    protected int $attemptCount = 0;

    /** Guards tearDown(): setUp() skips BEFORE booting when no API key is set. */
    private bool $instanceBooted = false;

    protected function setUp(): void
    {
        $apiKey = (string)getenv('OPENROUTER_API_KEY');
        if ($apiKey === '') {
            self::markTestSkipped('OPENROUTER_API_KEY is not set — skipping LLM tests.');
        }

        // Must be assigned before parent::setUp(): the testing framework
        // writes it into the test instance's system configuration there.
        $this->configurationToUseInTestInstance = [
            'EXTENSIONS' => [
                'agent' => [
                    'apiUrl' => (string)(getenv('LLM_TEST_API_URL') ?: 'https://openrouter.ai/api/v1/'),
                    'apiKey' => $apiKey,
                    'model' => (string)(getenv('LLM_TEST_MODEL') ?: static::DEFAULT_MODEL),
                    'systemPrompt' => 'You are a helpful TYPO3 CMS assistant. You have access to tools that let you read and modify TYPO3 pages, content, and database records. Use these tools to fulfill the user\'s request.',
                    'maxIterations' => 15,
                    'reasoningEffort' => 'low',
                    // Keep the tool set deterministic: no OpenRouter-side web_fetch.
                    'webFetch' => false,
                ],
            ],
        ];

        parent::setUp();
        $this->instanceBooted = true;

        $this->importCSVDataSet(__DIR__ . '/../Functional/Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Functional/Fixtures/pages.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');
    }

    protected function tearDown(): void
    {
        if (!$this->instanceBooted) {
            return;
        }
        parent::tearDown();
    }

    /**
     * Retry flaky LLM tests up to 3 times on assertion failure. LLM output
     * is non-deterministic; a single failure does not prove a broken test.
     * `invokeTestMethod` is the correct hook on PHPUnit 13 (runTest() is
     * private there) — same approach as hn/typo3-mcp-server.
     */
    protected function invokeTestMethod(string $methodName, array $testArguments): mixed
    {
        $maxRetries = 3;
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $this->attemptCount = $attempt;
            try {
                return parent::invokeTestMethod($methodName, $testArguments);
            } catch (\PHPUnit\Framework\SkippedWithMessageException | \PHPUnit\Framework\IncompleteTestError $e) {
                throw $e;
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                $lastException = $e;
                if ($attempt < $maxRetries) {
                    fwrite(STDERR, sprintf(
                        "\n[LLM-RETRY %d/%d] %s::%s — %s\n",
                        $attempt,
                        $maxRetries,
                        static::class,
                        $methodName,
                        preg_replace('/\s+/', ' ', mb_substr($e->getMessage(), 0, 320)),
                    ));
                    try {
                        $this->tearDown();
                    } catch (\Throwable) {
                    }
                    $this->setUp();
                }
            }
        }

        throw $lastException;
    }

    /**
     * Create a task the same way ChatController::newAction does and run the
     * full agent loop against the real LLM.
     *
     * @param array<int, array{uid?: int|string, identifier?: string, name?: string}> $rawAttachments
     *        Attachment refs as posted by the chat composer — resolved and
     *        hung off the initial user message like a real upload.
     * @return array{0: array<string, mixed>, 1: array} Task row after the run, final messages
     */
    protected function runAgentTask(string $prompt, int $pid = 1, array $rawAttachments = []): array
    {
        $agentService = $this->get(AgentService::class);

        $connection = $this->getConnectionPool()->getConnectionForTable('tx_agent_task');
        $connection->insert(
            'tx_agent_task',
            [
                'pid' => $pid,
                'title' => mb_substr($prompt, 0, 100),
                'prompt' => $prompt,
                'status' => TaskStatus::Pending->value,
                'result' => '',
                'cruser_id' => 1,
                'crdate' => time(),
                'tstamp' => time(),
                'deleted' => 0,
                'hidden' => 0,
            ],
        );
        $taskUid = (int)$connection->lastInsertId();
        $agentService->persistInitialMessages($taskUid, $pid, '', 0, $prompt, $rawAttachments);

        $finalMessages = $agentService->run($taskUid);

        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_agent_task');
        $queryBuilder->getRestrictions()->removeAll();
        $task = $queryBuilder
            ->select('*')
            ->from('tx_agent_task')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($taskUid)))
            ->executeQuery()
            ->fetchAssociative();

        return [$task ?: [], $finalMessages];
    }

    /**
     * All tool calls the LLM made during the run, in order.
     *
     * @return list<array{name: string, arguments: array}>
     */
    protected function getToolCalls(array $messages): array
    {
        $calls = [];
        foreach ($messages as $message) {
            foreach (($message['tool_calls'] ?? []) as $toolCall) {
                $arguments = $toolCall['function']['arguments'] ?? '';
                $calls[] = [
                    'name' => (string)($toolCall['function']['name'] ?? ''),
                    'arguments' => is_array($arguments) ? $arguments : (json_decode((string)$arguments, true) ?? []),
                ];
            }
        }
        return $calls;
    }

    /**
     * Assert the LLM called the given tool at least once; returns the calls.
     *
     * @return list<array{name: string, arguments: array}>
     */
    protected function assertToolCalled(array $messages, string $toolName): array
    {
        $matching = array_values(array_filter(
            $this->getToolCalls($messages),
            static fn (array $call): bool => $call['name'] === $toolName,
        ));
        self::assertNotEmpty(
            $matching,
            sprintf('Expected the LLM to call "%s". Calls made: %s', $toolName, $this->describeToolCalls($messages)),
        );
        return $matching;
    }

    protected function assertTaskEnded(array $task, array $messages): void
    {
        self::assertSame(
            TaskStatus::Ended->value,
            (int)($task['status'] ?? -1),
            'Agent task did not end cleanly. Result: ' . ($task['result'] ?? '')
                . ' — tool calls: ' . $this->describeToolCalls($messages),
        );
    }

    protected function getFinalAssistantText(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') !== 'assistant') {
                continue;
            }
            $content = $messages[$i]['content'] ?? '';
            if (is_string($content) && $content !== '') {
                return $content;
            }
        }
        return '';
    }

    protected function describeToolCalls(array $messages): string
    {
        $description = array_map(
            static fn (array $call): string => $call['name'] . '(' . json_encode($call['arguments']) . ')',
            $this->getToolCalls($messages),
        );
        return $description === [] ? '(none)' : implode(', ', $description);
    }
}
