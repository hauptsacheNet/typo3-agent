<?php

declare(strict_types=1);

namespace Hn\Agent\Service;

use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Service\WorkspaceContextService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Configuration\Tca\TcaFactory;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use Doctrine\DBAL\Types\Types;
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
     * @param callable|null $onIteration Optional callback(int $iteration, array $assistantMessage) for progress output
     */
    public function processTask(int $taskUid, ?callable $onIteration = null): void
    {
        $task = $this->loadTask($taskUid);
        if ($task === false) {
            throw new \RuntimeException('Task with UID ' . $taskUid . ' not found.');
        }

        // Atomically claim the task: only set to in_progress if it's still pending/failed
        // This prevents race conditions when multiple agent:run processes run concurrently
        $claimed = $this->claimTask($taskUid, (int)$task['status']);
        if (!$claimed) {
            throw new \RuntimeException('Task #' . $taskUid . ' could not be claimed (already in progress by another process?).');
        }

        $messages = null;

        try {
            // Set up backend user context from task's cruser_id
            $this->setupBackendUserContext((int)($task['cruser_id'] ?? 0));

            // Load or build messages
            $messages = $this->buildMessages($task);

            // Convert MCP tools to OpenAI format
            $tools = $this->toolConverterService->convertTools($this->toolRegistry);

            // Agent loop
            $config = $this->extensionConfiguration->get('agent');
            $maxIterations = (int)($config['maxIterations'] ?? 20);
            $reachedLimit = false;

            for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
                // Call LLM
                $assistantMessage = $this->llmService->chatCompletion($messages, $tools);

                // Append assistant message
                $messages[] = $assistantMessage;

                // Save checkpoint
                $this->saveTaskState($taskUid, $messages, 1);

                // Notify progress
                if ($onIteration !== null) {
                    $onIteration($iteration, $assistantMessage);
                }

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
                }

                // Save checkpoint after tool execution
                $this->saveTaskState($taskUid, $messages, 1);

                if ($iteration === $maxIterations - 1) {
                    $reachedLimit = true;
                }
            }

            // Extract final result from last assistant message
            $result = $this->extractResult($messages);

            if ($reachedLimit) {
                $result = '[Agent stopped: reached maximum of ' . $maxIterations . ' iterations]'
                    . ($result !== '' ? "\n\n" . $result : '');
                $this->saveTaskState($taskUid, $messages, 3, $result);
            } else {
                $this->saveTaskState($taskUid, $messages, 2, $result);
            }
        } catch (\Throwable $e) {
            // Preserve progress on failure
            $this->saveTaskState($taskUid, $messages, 3, 'Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Atomically claim a task by setting status to in_progress only if current status matches.
     * Returns true if the task was successfully claimed.
     */
    private function claimTask(int $taskUid, int $currentStatus): bool
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_agent_task');
        $affectedRows = $connection->update(
            'tx_agent_task',
            ['status' => 1, 'tstamp' => time()],
            ['uid' => $taskUid, 'status' => $currentStatus],
        );
        return $affectedRows > 0;
    }

    /**
     * Load a task record from the database.
     */
    private function loadTask(int $taskUid): array|false
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable('tx_agent_task');

        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('*')
            ->from('tx_agent_task')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($taskUid)))
            ->executeQuery()
            ->fetchAssociative();
    }

    /**
     * Decode messages from a task record.
     *
     * fetchAssociative() returns the raw JSON string from the database;
     * decode it here. Returns null for empty/missing data.
     */
    private function decodeMessages(mixed $raw): ?array
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
     * Save task state (messages, status, result) to the database.
     */
    private function saveTaskState(int $taskUid, ?array $messages, int $status, ?string $result = null): void
    {
        $data = [
            'status' => $status,
            'tstamp' => time(),
        ];

        if ($messages !== null) {
            $data['messages'] = $messages;
        }

        if ($result !== null) {
            $data['result'] = $result;
        }

        $types = [];
        if (array_key_exists('messages', $data)) {
            $types['messages'] = Types::JSON;
        }

        $this->connectionPool
            ->getConnectionForTable('tx_agent_task')
            ->update('tx_agent_task', $data, ['uid' => $taskUid], $types);
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
