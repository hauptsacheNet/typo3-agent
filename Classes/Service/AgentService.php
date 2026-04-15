<?php

declare(strict_types=1);

namespace Hn\Agent\Service;

use Hn\Agent\Domain\AgentTaskRepository;
use Hn\Agent\Domain\TaskStatus;
use Hn\Agent\Event\AfterAssistantMessageEvent;
use Hn\Agent\Event\AfterToolExecutionEvent;
use Hn\Agent\Event\BeforeLlmCallEvent;
use Hn\Agent\Event\BeforeToolExecutionEvent;
use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Service\WorkspaceContextService;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Configuration\Tca\TcaFactory;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class AgentService
{
    public function __construct(
        private readonly LlmService $llmService,
        private readonly ToolConverterService $toolConverterService,
        private readonly ToolRegistry $toolRegistry,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly ConnectionPool $connectionPool,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly AgentTaskRepository $repository,
    ) {}

    /**
     * Process a single agent task by UID.
     *
     * This is the main entry point for the agent loop. It:
     * 1. Loads the task record
     * 2. Sets up BE_USER context from cruser_id
     * 3. Builds or resumes conversation messages
     * 4. Runs the agent loop (LLM call → tool execution → repeat)
     * 5. Saves result and status
     *
     * Messages are saved after every iteration for resumability.
     *
     * @param int $taskUid The UID of the tx_agent_task record
     */
    public function processTask(int $taskUid): void
    {
        $task = $this->repository->findByUid($taskUid);
        if ($task === false) {
            throw new \RuntimeException('Task with UID ' . $taskUid . ' not found.');
        }

        // Atomically claim the task: only set to in_progress if it's still pending/failed
        // This prevents race conditions when multiple agent:run processes run concurrently
        $claimed = $this->repository->claim($taskUid, TaskStatus::from((int)$task['status']));
        if (!$claimed) {
            throw new \RuntimeException('Task #' . $taskUid . ' could not be claimed (already in progress by another process?).');
        }

        // Set up backend user context from task's cruser_id
        $this->setupBackendUserContext((int)($task['cruser_id'] ?? 0));

        // Load or build messages
        $messages = $this->buildMessages($task);

        $this->runLoop($taskUid, $messages);
    }

    /**
     * Continue an existing chat conversation by appending a new user message
     * and running the agent loop.
     *
     * Used by the backend chat module to send follow-up messages.
     *
     * @param int $taskUid
     * @param string $userMessage The new user message to append
     * @return array The full updated messages array
     */
    public function continueChat(int $taskUid, string $userMessage): array
    {
        $task = $this->repository->findByUid($taskUid);
        if ($task === false) {
            throw new \RuntimeException('Task with UID ' . $taskUid . ' not found.');
        }

        // Set up backend user context from task's cruser_id
        $this->setupBackendUserContext((int)($task['cruser_id'] ?? 0));

        // Load or build existing messages, then append the new user message
        $messages = $this->buildMessages($task);
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        // Persist the user message + reset status to pending so claim succeeds
        $this->repository->saveState($taskUid, $messages, TaskStatus::Pending);

        // Atomically claim with status=0 we just wrote
        $claimed = $this->repository->claim($taskUid, TaskStatus::Pending);
        if (!$claimed) {
            throw new \RuntimeException('Task #' . $taskUid . ' could not be claimed (already in progress by another process?).');
        }

        return $this->runLoop($taskUid, $messages);
    }

    /**
     * Core agent loop: call LLM, execute tool calls, repeat until done or max iterations.
     *
     * Caller must ensure: task is claimed (status=1), BE_USER context is set up.
     */
    private function runLoop(int $taskUid, array $messages): array
    {
        try {
            // Convert MCP tools to OpenAI format
            $tools = $this->toolConverterService->convertTools($this->toolRegistry);

            // Agent loop
            $config = $this->extensionConfiguration->get('agent');
            $maxIterations = (int)($config['maxIterations'] ?? 20);
            $reachedLimit = false;

            for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
                $this->eventDispatcher->dispatch(new BeforeLlmCallEvent($taskUid, $iteration));

                // Call LLM
                $assistantMessage = $this->llmService->chatCompletion($messages, $tools);

                // Append assistant message
                $messages[] = $assistantMessage;

                // Save checkpoint
                $this->repository->saveState($taskUid, $messages, TaskStatus::InProgress);

                $this->eventDispatcher->dispatch(new AfterAssistantMessageEvent($taskUid, $iteration, $assistantMessage));

                // Check for tool calls
                $toolCalls = $assistantMessage['tool_calls'] ?? null;
                if (empty($toolCalls)) {
                    // No tool calls — final answer reached
                    break;
                }

                // Execute each tool call
                foreach ($toolCalls as $toolCall) {
                    $toolName = $toolCall['function']['name'] ?? '';
                    $toolArguments = $toolCall['function']['arguments'] ?? '{}';
                    $toolCallId = $toolCall['id'] ?? '';

                    $this->eventDispatcher->dispatch(new BeforeToolExecutionEvent($taskUid, $iteration, $toolCallId, $toolName, $toolArguments));

                    $toolResult = $this->toolConverterService->executeToolCall(
                        $this->toolRegistry,
                        $toolName,
                        $toolArguments,
                    );

                    // Append tool result message
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCallId,
                        'content' => $toolResult,
                    ];

                    $this->eventDispatcher->dispatch(new AfterToolExecutionEvent($taskUid, $iteration, $toolCallId, $toolName, $toolResult));
                }

                // Save checkpoint after tool execution
                $this->repository->saveState($taskUid, $messages, TaskStatus::InProgress);

                if ($iteration === $maxIterations - 1) {
                    $reachedLimit = true;
                }
            }

            // Extract final result from last assistant message
            $result = $this->extractResult($messages);

            if ($reachedLimit) {
                $result = '[Agent stopped: reached maximum of ' . $maxIterations . ' iterations]'
                    . ($result !== '' ? "\n\n" . $result : '');
                $this->repository->saveState($taskUid, $messages, TaskStatus::Failed, $result);
            } else {
                $this->repository->saveState($taskUid, $messages, TaskStatus::Ended, $result);
            }

            return $messages;
        } catch (\Throwable $e) {
            // Preserve progress on failure
            $this->repository->saveState($taskUid, $messages, TaskStatus::Failed, 'Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Decode messages from a task record.
     *
     * fetchAssociative() returns the raw JSON string from the database;
     * decode it here. Returns null for empty/missing data.
     */
    public function decodeMessages(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw !== [] ? $raw : null;
        }
        if (is_string($raw) && $raw !== '' && $raw !== 'null') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && $decoded !== []) {
                return $decoded;
            }
        }
        return null;
    }

    /**
     * Build the messages array for a task.
     *
     * If the task already has messages (resuming), decode and return them.
     * Otherwise, build initial messages from system prompt + page context + user prompt.
     */
    private function buildMessages(array $task): array
    {
        // Resume from existing messages
        $existingMessages = $this->decodeMessages($task['messages'] ?? null);
        if ($existingMessages !== null) {
            return $existingMessages;
        }

        $config = $this->extensionConfiguration->get('agent');
        $systemPrompt = $config['systemPrompt'] ?? 'You are a helpful TYPO3 CMS assistant.';

        // Add page context if task is on a specific page
        $pid = (int)($task['pid'] ?? 0);
        if ($pid > 0) {
            $pageContext = $this->getPageContext($pid);
            if ($pageContext !== '') {
                $systemPrompt .= "\n\n## Current page context\n" . $pageContext;
            }
        }

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $task['prompt'] ?? ''],
        ];

        return $messages;
    }

    /**
     * Get page context by executing the GetPage tool.
     */
    private function getPageContext(int $pid): string
    {
        $getPageTool = $this->toolRegistry->getTool('GetPage');
        if ($getPageTool === null) {
            return '';
        }

        try {
            $result = $getPageTool->execute(['uid' => $pid]);
            $parts = [];
            foreach ($result->content as $content) {
                if ($content instanceof \Mcp\Types\TextContent) {
                    $parts[] = $content->text;
                }
            }
            return implode("\n", $parts);
        } catch (\Throwable $e) {
            return 'Could not load page context: ' . $e->getMessage();
        }
    }

    /**
     * Extract the final text result from the messages array.
     */
    private function extractResult(array $messages): string
    {
        // Walk backwards to find the last assistant message with content
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'assistant' && !empty($messages[$i]['content'])) {
                return $messages[$i]['content'];
            }
        }
        return '';
    }

    /**
     * Set up backend user context for tool execution.
     *
     * Follows the pattern from McpEndpoint::setupBackendUserContext().
     */
    private function setupBackendUserContext(int $userId): void
    {
        // Ensure TCA is loaded (required for tools, may not be loaded in all contexts)
        if (empty($GLOBALS['TCA'])) {
            $tcaFactory = GeneralUtility::getContainer()->get(TcaFactory::class);
            $GLOBALS['TCA'] = $tcaFactory->get();
        }

        $beUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);

        if ($userId > 0) {
            // Load user data from database
            $queryBuilder = $this->connectionPool
                ->getConnectionForTable('be_users')
                ->createQueryBuilder();

            $userData = $queryBuilder
                ->select('*')
                ->from('be_users')
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($userId)))
                ->executeQuery()
                ->fetchAssociative();

            if ($userData) {
                $beUser->user = $userData;
                $GLOBALS['BE_USER'] = $beUser;

                // Populate permissions from user groups
                $beUser->fetchGroupData();

                // Initialize language service
                $languageServiceFactory = GeneralUtility::makeInstance(LanguageServiceFactory::class);
                $GLOBALS['LANG'] = $languageServiceFactory->createFromUserPreferences($beUser);

                // Set up workspace context
                $workspaceService = GeneralUtility::makeInstance(WorkspaceContextService::class);
                $workspaceId = $workspaceService->switchToOptimalWorkspace($beUser);

                // Set up TYPO3 Context API
                $context = GeneralUtility::makeInstance(Context::class);
                $context->setAspect('backend.user', new UserAspect($beUser));
                $context->setAspect('workspace', new WorkspaceAspect($workspaceId));

                return;
            }
        }

        // Fall back to admin context if cruser_id is 0 or user not found
        $beUser->user = [
            'uid' => 1,
            'pid' => 0,
            'admin' => 1,
            'username' => '_agent_',
            'usergroup' => '',
            'lang' => 'default',
            'workspace_id' => 0,
            'realName' => 'AI Agent',
            'TSconfig' => '',
        ];
        $beUser->workspace = 0;
        $GLOBALS['BE_USER'] = $beUser;

        $languageServiceFactory = GeneralUtility::makeInstance(LanguageServiceFactory::class);
        $GLOBALS['LANG'] = $languageServiceFactory->create('default');
    }
}
