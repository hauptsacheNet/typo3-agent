<?php

declare(strict_types=1);

namespace Hn\Agent\Service;

use Doctrine\DBAL\ParameterType;
use Hn\Agent\Domain\AgentInstructionRepository;
use Hn\Agent\Domain\AgentTaskRepository;
use Hn\Agent\Domain\TaskStatus;
use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Service\WorkspaceContextService;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Configuration\Tca\TcaFactory;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class AgentService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly LlmService $llmService,
        private readonly ToolConverterService $toolConverterService,
        private readonly ToolRegistry $toolRegistry,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly ConnectionPool $connectionPool,
        private readonly AgentTaskRepository $repository,
        private readonly AttachmentService $attachmentService,
        private readonly AgentInstructionRepository $instructionRepository,
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
     * @param callable(string, array): void|null $progress Optional progress callback.
     *        Invoked with (string $event, array $data) where $event is one of
     *        'llm_start', 'assistant_message', 'tool_start', 'tool_result',
     *        'content_delta', 'tool_call_delta'.
     */
    public function processTask(int $taskUid, ?callable $progress = null): void
    {
        $task = $this->repository->findByUid($taskUid);
        if ($task === false) {
            throw new \RuntimeException('Task with UID ' . $taskUid . ' not found.');
        }

        // Evaluate fresh-status BEFORE claim() flips it to InProgress.
        // Pending = newAction just created the task and the agent has never run;
        // the initial conversation (system + synthetic GetPage/ReadTable + user)
        // has been built and persisted by newAction → buildInitialMessages.
        $isFreshTask = (int)$task['status'] === TaskStatus::Pending->value;

        // Atomically claim the task: only set to in_progress if it's still pending/failed
        // This prevents race conditions when multiple agent:run processes run concurrently
        $claimed = $this->repository->claim($taskUid, TaskStatus::from((int)$task['status']));
        if (!$claimed) {
            throw new \RuntimeException('Task #' . $taskUid . ' could not be claimed (already in progress by another process?).');
        }

        // Set up backend user context from task's cruser_id (and workspace, if persisted)
        $this->setupBackendUserContext((int)($task['cruser_id'] ?? 0), (int)($task['workspace_id'] ?? 0));

        $messages = $this->decodeMessages($task['messages'] ?? null) ?? [];

        // For fresh tasks the persisted messages contain a synthetic assistant
        // turn with pre-loaded context tool calls. These never pass through
        // runLoop and would otherwise only become visible to the UI after a
        // page reload — stream them explicitly so the chat renders them live.
        if ($isFreshTask && $progress !== null) {
            $this->emitInitialContextEvents($messages, $progress);
        }

        $this->runLoop($taskUid, $messages, $progress);
    }

    /**
     * Continue an existing chat conversation by appending a new user message
     * and running the agent loop.
     *
     * Used by the backend chat module to send follow-up messages.
     *
     * @param int $taskUid
     * @param string $userMessage The new user message to append
     * @param callable(string, array): void|null $progress Optional progress callback, see processTask().
     * @param array<int, array{uid?: int|string, identifier?: string, name?: string}> $attachments
     *        Raw attachment refs from the client; FAL UIDs are preferred, combined identifiers are used as fallback.
     * @return array The full updated messages array
     */
    public function continueChat(int $taskUid, string $userMessage, ?callable $progress = null, array $attachments = []): array
    {
        $task = $this->repository->findByUid($taskUid);
        if ($task === false) {
            throw new \RuntimeException('Task with UID ' . $taskUid . ' not found.');
        }

        // Set up backend user context from task's cruser_id (and workspace, if persisted)
        $this->setupBackendUserContext((int)($task['cruser_id'] ?? 0), (int)($task['workspace_id'] ?? 0));

        // Resolve attachments to structured refs (uid/identifier/name/mime_type). FAL permission
        // checks run against the task's user, not the request context — so this is done after the
        // BE_USER setup above. The refs are persisted alongside the user message; the markdown
        // block for the LLM is only built transiently in serializeForLlm() at call-site.
        $attachmentRefs = $this->resolveAttachmentRefs($attachments);

        $messages = $this->decodeMessages($task['messages'] ?? null) ?? [];
        $userMessageRecord = ['role' => 'user', 'content' => $userMessage];
        if ($attachmentRefs !== []) {
            $userMessageRecord['attachments'] = $attachmentRefs;
        }
        $messages[] = $userMessageRecord;

        // Persist the user message + reset status to pending so claim succeeds
        $this->repository->saveState($taskUid, $messages, TaskStatus::Pending);

        // Atomically claim with status=0 we just wrote
        $claimed = $this->repository->claim($taskUid, TaskStatus::Pending);
        if (!$claimed) {
            throw new \RuntimeException('Task #' . $taskUid . ' could not be claimed (already in progress by another process?).');
        }

        return $this->runLoop($taskUid, $messages, $progress);
    }

    /**
     * Core agent loop: call LLM, execute tool calls, repeat until done or max iterations.
     *
     * Caller must ensure: task is claimed (status=1), BE_USER context is set up.
     *
     * @param callable(string, array): void|null $progress
     */
    private function runLoop(int $taskUid, array $messages, ?callable $progress): array
    {
        try {
            // Convert MCP tools to OpenAI format
            $tools = $this->toolConverterService->convertTools($this->toolRegistry);

            // Agent loop
            $config = $this->extensionConfiguration->get('agent');
            $maxIterations = (int)($config['maxIterations'] ?? 20);
            $reachedLimit = false;

            for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
                if ($progress !== null) {
                    $progress('llm_start', ['iteration' => $iteration]);
                }

                // Per-delta callback for streaming LLM chunks. Rewrites raw SSE
                // delta types ('content', 'tool_call', 'finish') into the agent-level
                // progress events ('content_delta', 'tool_call_delta'); 'finish' is
                // intentionally dropped since 'assistant_message' below marks the
                // end of an iteration for downstream consumers.
                $onDelta = $progress === null
                    ? null
                    : static function (string $deltaType, array $payload) use ($progress, $iteration): void {
                        if ($deltaType === 'content') {
                            $progress('content_delta', [
                                'iteration' => $iteration,
                                'text' => $payload['text'] ?? '',
                            ]);
                            return;
                        }
                        if ($deltaType === 'tool_call') {
                            $progress('tool_call_delta', ['iteration' => $iteration] + $payload);
                        }
                    };

                // Call LLM (streaming — invokes $onDelta per chunk, returns
                // aggregated message with same shape as chatCompletion()).
                // serializeForLlm() merges structured attachment refs into
                // content as a markdown block at the call site; the persisted
                // messages keep `attachments` as a structured field.
                $assistantMessage = $this->llmService->chatCompletionStream(
                    $this->serializeForLlm($messages),
                    $tools,
                    $onDelta,
                );

                // Append assistant message
                $messages[] = $assistantMessage;

                // Save checkpoint
                $this->repository->saveState($taskUid, $messages, TaskStatus::InProgress);

                if ($progress !== null) {
                    $progress('assistant_message', [
                        'iteration' => $iteration,
                        'message' => $assistantMessage,
                    ]);
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

                    if ($progress !== null) {
                        $progress('tool_start', [
                            'iteration' => $iteration,
                            'tool_call_id' => $toolCallId,
                            'tool_name' => $toolName,
                            'arguments' => $toolArguments,
                        ]);
                    }

                    $toolResult = $this->toolConverterService->executeToolCall(
                        $this->toolRegistry,
                        $toolName,
                        $toolArguments,
                    );

                    // Track workspace changes from write operations
                    $change = $this->trackChange($taskUid, $toolResult['text']);
                    if ($change !== null && $progress !== null) {
                        $progress('change_tracked', $change);
                    }

                    // Append tool result message. `_media` carries inline image
                    // payloads (e.g. from ReadFile on a sys_file image) — kept
                    // separate from the human-readable `content` so the UI can
                    // render text as-is while serializeForLlm() rebuilds the
                    // multimodal block array for the LLM.
                    $toolMessage = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCallId,
                        'content' => $toolResult['text'],
                    ];
                    if (!empty($toolResult['media'])) {
                        $toolMessage['_media'] = $toolResult['media'];
                    }
                    $messages[] = $toolMessage;

                    if ($progress !== null) {
                        $progress('tool_result', [
                            'iteration' => $iteration,
                            'tool_call_id' => $toolCallId,
                            'tool_name' => $toolName,
                            'content' => $toolResult['text'],
                        ]);
                    }
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
     * Build the initial conversation for a brand-new task: system prompt
     * + synthetic GetPage/ReadTable context turn + user message (with
     * resolved attachments, if any).
     *
     * Invoked by ChatController::newAction (and persisted there into
     * tx_agent_task.messages), so subsequent reads (processTask,
     * continueChat) just decode the JSON instead of synthesizing.
     *
     * @param array<int, array{uid?: int|string, identifier?: string, name?: string}> $rawAttachments
     *        Raw attachment refs from the request — resolved against the current BE_USER's FAL permissions.
     */
    public function buildInitialMessages(int $pid, string $contextTable, int $contextUid, string $prompt, array $rawAttachments = []): array
    {
        $config = $this->extensionConfiguration->get('agent');
        $systemPrompt = $config['systemPrompt'] ?? 'You are a helpful TYPO3 CMS assistant.';
        $systemPrompt .= $this->buildEditorInstructionsSection();

        $userMessage = ['role' => 'user', 'content' => $prompt];
        $attachmentRefs = $this->resolveAttachmentRefs($rawAttachments);
        if ($attachmentRefs !== []) {
            $userMessage['attachments'] = $attachmentRefs;
        }
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        // Build context tool calls to inject as a simulated assistant turn
        // BEFORE the user prompt. From the LLM's perspective the agent has
        // already loaded the working context (page/record the user is on) as
        // its first action, and the user prompt then arrives with that context
        // visible. The user message is appended after the context block below.
        $toolCalls = [];
        $toolResults = [];
        $loadedParts = [];

        // Page context via GetPage
        if ($pid > 0) {
            $pageContext = $this->getPageContext($pid);
            if ($pageContext !== '') {
                $callId = 'page_context_' . $pid;
                $toolCalls[] = [
                    'id' => $callId,
                    'type' => 'function',
                    'function' => [
                        'name' => 'GetPage',
                        'arguments' => json_encode(['uid' => $pid]),
                    ],
                ];
                $toolResults[] = [
                    'role' => 'tool',
                    'tool_call_id' => $callId,
                    'content' => $pageContext,
                ];
                $loadedParts[] = 'Seite #' . $pid;
            }
        }

        // Record context via ReadTable — skip when it would duplicate the
        // page context already loaded above (same UID on the pages table).
        $isPageDuplicate = $contextTable === 'pages' && $contextUid === $pid && $pid > 0;
        if ($contextTable !== '' && $contextUid > 0 && !$isPageDuplicate) {
            $recordContext = $this->getRecordContext($contextTable, $contextUid);
            if ($recordContext !== '') {
                $callId = 'record_context_' . $contextTable . '_' . $contextUid;
                $toolCalls[] = [
                    'id' => $callId,
                    'type' => 'function',
                    'function' => [
                        'name' => 'ReadTable',
                        'arguments' => json_encode(['table' => $contextTable, 'uid' => $contextUid]),
                    ],
                ];
                $toolResults[] = [
                    'role' => 'tool',
                    'tool_call_id' => $callId,
                    'content' => $recordContext,
                ];
                $loadedParts[] = $contextTable . ' #' . $contextUid;
            }
        }

        // Append the simulated assistant tool-call turn + results.
        // The narration in `content` frames the pre-fetched tool calls as the
        // working context the user is currently on — without it the LLM has
        // to infer purpose from message position alone.
        if ($toolCalls !== []) {
            $messages[] = [
                'role' => 'assistant',
                'content' => 'Ich lade zuerst den aktuellen Arbeitskontext: ' . implode(', ', $loadedParts) . '.',
                'tool_calls' => $toolCalls,
            ];
            array_push($messages, ...$toolResults);
        }

        $messages[] = $userMessage;

        return $messages;
    }

    /**
     * Build the editorial-instructions block that is appended to the system
     * prompt. Editors maintain these as tx_agent_instruction records (tone of
     * voice, how to handle certain content elements/records, …); every active
     * record is concatenated here.
     *
     * Returns an empty string when there are no active instructions, so the
     * system prompt is left untouched in that case. The block is baked into
     * the stored system message at task-creation time — it therefore applies
     * to newly started chats, not to ones already in progress.
     */
    private function buildEditorInstructionsSection(): string
    {
        $instructions = $this->instructionRepository->findActiveInstructions();
        if ($instructions === []) {
            return '';
        }

        $section = "\n\n# Editorial instructions\n"
            . "The following guidance was maintained by the editorial team and must be "
            . "followed for all texts and changes you produce:\n";
        foreach ($instructions as $instruction) {
            $title = trim($instruction['title']);
            $section .= "\n## " . ($title !== '' ? $title : 'Instruction') . "\n"
                . $instruction['instruction'] . "\n";
        }
        return $section;
    }

    /**
     * Emit progress events for the synthetic turns produced by
     * buildInitialMessages() so the chat UI can render them in the same order
     * they will appear after a reload. For a fresh task the persisted order is
     *   [system, (assistant_narration, tool, ...), user]
     * which means the UI must see the synthetic context block (if any) BEFORE
     * the user prompt — the `user_message` event at the end materializes the
     * user turn appended last.
     *
     * iteration=-1 marks these as pre-loop (not part of any LLM iteration).
     *
     * @param callable(string, array): void $progress
     */
    private function emitInitialContextEvents(array $messages, callable $progress): void
    {
        $toolResultsByCallId = [];
        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'tool' && isset($msg['tool_call_id'])) {
                $toolResultsByCallId[(string)$msg['tool_call_id']] = (string)($msg['content'] ?? '');
            }
        }

        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') !== 'assistant' || empty($msg['tool_calls'])) {
                continue;
            }
            $progress('assistant_message', ['iteration' => -1, 'message' => $msg]);
            foreach ($msg['tool_calls'] as $toolCall) {
                $toolCallId = (string)($toolCall['id'] ?? '');
                $toolName = (string)($toolCall['function']['name'] ?? '');
                $progress('tool_start', [
                    'iteration' => -1,
                    'tool_call_id' => $toolCallId,
                    'tool_name' => $toolName,
                    'arguments' => $toolCall['function']['arguments'] ?? '{}',
                ]);
                if (isset($toolResultsByCallId[$toolCallId])) {
                    $progress('tool_result', [
                        'iteration' => -1,
                        'tool_call_id' => $toolCallId,
                        'tool_name' => $toolName,
                        'content' => $toolResultsByCallId[$toolCallId],
                    ]);
                }
            }
        }

        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'user') {
                $progress('user_message', ['message' => $msg]);
                break;
            }
        }
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
     * Get record context by executing the ReadTable tool.
     */
    private function getRecordContext(string $table, int $uid): string
    {
        $readTableTool = $this->toolRegistry->getTool('ReadTable');
        if ($readTableTool === null) {
            return '';
        }

        try {
            $result = $readTableTool->execute(['table' => $table, 'uid' => $uid]);
            $parts = [];
            foreach ($result->content as $content) {
                if ($content instanceof \Mcp\Types\TextContent) {
                    $parts[] = $content->text;
                }
            }
            return implode("\n", $parts);
        } catch (\Throwable $e) {
            return 'Could not load record context: ' . $e->getMessage();
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
    private function setupBackendUserContext(int $userId, int $persistedWorkspaceId = 0): void
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

                // Set up workspace context: prefer the workspace persisted on the
                // task (so a chat continues in the workspace where it was created).
                // Fall back to optimal-workspace selection only when no workspace
                // was persisted (legacy rows from before this migration).
                $workspaceService = GeneralUtility::makeInstance(WorkspaceContextService::class);
                if ($persistedWorkspaceId > 0) {
                    if (!$beUser->checkWorkspace($persistedWorkspaceId)) {
                        throw new \RuntimeException(sprintf(
                            'Task workspace #%d is not accessible for user #%d.',
                            $persistedWorkspaceId,
                            $userId,
                        ));
                    }
                    $workspaceService->setWorkspaceContext($beUser, $persistedWorkspaceId);
                    $workspaceId = $persistedWorkspaceId;
                } else {
                    $workspaceId = $workspaceService->switchToOptimalWorkspace($beUser);
                }

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

    /**
     * Track a workspace change from a tool result.
     *
     * Parses the JSON result of a WriteTableTool call and stores the
     * relationship between the task and the workspace version record.
     */
    private function trackChange(int $taskUid, string $toolResult): ?array
    {
        $data = json_decode($toolResult, true);
        if (!is_array($data) || !isset($data['action'], $data['table'], $data['uid'])) {
            return null;
        }

        $table = (string)$data['table'];
        $uid = (int)$data['uid'];
        $action = (string)$data['action'];
        $workspaceId = (int)($GLOBALS['BE_USER']->workspace ?? 0);

        if ($workspaceId === 0) {
            // No workspace — changes went directly to live, nothing to track
            return null;
        }

        if ($action === 'create' || $action === 'translate') {
            // For new records, the returned UID is already the workspace record UID
            // (NEW_PLACEHOLDER with t3ver_state=1)
            $workspaceRecordUid = $uid;
        } else {
            // For update/delete, look up the workspace version of the live record
            $wsVersion = BackendUtility::getWorkspaceVersionOfRecord($workspaceId, $table, $uid, 'uid');
            if ($wsVersion === false) {
                return null;
            }
            $workspaceRecordUid = (int)$wsVersion['uid'];
        }

        [$pageId, $workspacePageId] = $this->resolvePageIds($table, $uid, $workspaceRecordUid, $action);

        $this->repository->addChange($taskUid, $table, $uid, $workspaceRecordUid, $pageId, $workspacePageId);

        return [
            'tablename' => $table,
            'page_id' => $pageId,
            'record_uid' => $uid,
            'workspace_record_uid' => $workspaceRecordUid,
            'workspace_page_id' => $workspacePageId,
            'task_uid' => $taskUid,
        ];
    }

    /**
     * Resolve the page IDs for a tracked change.
     *
     * @return array{int, int} [pageId, workspacePageId]
     */
    private function resolvePageIds(string $table, int $recordUid, int $workspaceRecordUid, string $action): array
    {
        if ($table === 'pages') {
            return [$recordUid, $workspaceRecordUid];
        }

        if ($action === 'create' || $action === 'translate') {
            $pid = $this->getRecordPid($table, $recordUid);
            return [$pid, $pid];
        }

        $pageId = $this->getRecordPid($table, $recordUid);
        $workspacePageId = ($workspaceRecordUid !== $recordUid)
            ? $this->getRecordPid($table, $workspaceRecordUid)
            : $pageId;

        return [$pageId, $workspacePageId];
    }

    /**
     * Get the pid of a record by direct database lookup (bypasses workspace overlays).
     */
    private function getRecordPid(string $table, int $uid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('pid')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? (int)$row['pid'] : 0;
    }

    /**
     * Resolve raw attachment refs from the request to structured AttachmentRef
     * arrays. Refs may carry `uid` (preferred) or `identifier` (combined
     * storage:path). Each returned entry has at least `name`; resolvable ones
     * also carry `uid`, `identifier`, `mime_type`. Unresolvable refs come
     * through with `unresolvable => true` so the UI can still render a chip
     * and the LLM still sees that something was attached.
     *
     * @param array<int, array{uid?: int|string, identifier?: string, name?: string}> $raw
     * @return array<int, array{uid?: int, identifier?: string, name: string, mime_type?: string, unresolvable?: bool}>
     */
    private function resolveAttachmentRefs(array $raw): array
    {
        $refs = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $file = $this->attachmentService->resolveFile($entry);
            if ($file instanceof File) {
                $refs[] = [
                    'uid' => $file->getUid(),
                    'identifier' => $file->getCombinedIdentifier(),
                    'name' => $file->getName(),
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                ];
                continue;
            }
            $label = trim((string)($entry['name'] ?? ''));
            if ($label === '') {
                $label = trim((string)($entry['identifier'] ?? ''));
            }
            if ($label === '' && isset($entry['uid'])) {
                $label = 'sys_file:' . (int)$entry['uid'];
            }
            $refs[] = [
                'name' => $label !== '' ? $label : 'Unbenannte Datei',
                'unresolvable' => true,
            ];
        }
        return $refs;
    }

    /**
     * Project the persisted message array into the form the LLM client expects.
     *
     * User attachments are surfaced as a metadata-only marker block
     * (`sys_file:UID — path (mime)`). The LLM never sees the file bytes
     * directly via this path — it has to call the `ReadFile` tool to actually
     * inspect content. This keeps the file-access path uniform (always
     * through a tool) and keeps user messages small.
     *
     * Tool messages may carry a `_media` field produced by the ToolConverter
     * when the tool returned ImageContent (e.g. ReadFile on a sys_file).
     * Those are converted into OpenAI/OpenRouter image_url/file content
     * blocks so the LLM actually sees the bytes — the persisted `content`
     * string stays untouched for the UI.
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    private function serializeForLlm(array $messages): array
    {
        $out = [];
        // Media from tool results (both images and documents) accumulate
        // across a tool-call batch and get emitted as ONE follow-up user
        // message right after the LAST tool message of that batch.
        //
        // OpenAI/OpenRouter strictly require all `tool` messages to follow
        // their owning assistant turn contiguously — inserting any non-tool
        // role between sibling tool results invalidates the sequence and
        // OpenRouter returns 500. Putting blocks INSIDE `tool` content also
        // turns out to be brittle through the OpenAI→Anthropic mapping (the
        // tool_result block expects string-or-image-array on the Anthropic
        // side and providers reject mixed content). The safest pattern that
        // matches what already works for user attachments: text-only tool
        // result, real media in a follow-up user-style message.
        $pendingMediaBlocks = [];
        $flushMedia = static function () use (&$out, &$pendingMediaBlocks): void {
            if ($pendingMediaBlocks === []) {
                return;
            }
            $out[] = [
                'role' => 'user',
                'content' => array_merge(
                    [['type' => 'text', 'text' => 'Inhalt der über ReadFile abgerufenen Datei(en):']],
                    $pendingMediaBlocks,
                ),
            ];
            $pendingMediaBlocks = [];
        };

        foreach ($messages as $message) {
            if (!is_array($message)) {
                $flushMedia();
                $out[] = $message;
                continue;
            }

            $role = $message['role'] ?? '';

            // Any non-tool message ends the current tool batch — flush
            // accumulated media now so blocks land after all tool results
            // but before the next turn.
            if ($role !== 'tool') {
                $flushMedia();
            }

            if ($role === 'tool' && !empty($message['_media']) && is_array($message['_media'])) {
                foreach ($message['_media'] as $media) {
                    if (!is_array($media)) {
                        continue;
                    }
                    $mime = (string)($media['mime'] ?? 'application/octet-stream');
                    $data = (string)($media['data'] ?? '');
                    if ($data === '') {
                        continue;
                    }
                    $dataUri = 'data:' . $mime . ';base64,' . $data;
                    if (str_starts_with($mime, 'image/')) {
                        $pendingMediaBlocks[] = ['type' => 'image_url', 'image_url' => ['url' => $dataUri]];
                    } else {
                        $filename = (string)($media['filename'] ?? 'attachment');
                        $pendingMediaBlocks[] = ['type' => 'file', 'file' => ['filename' => $filename, 'file_data' => $dataUri]];
                    }
                }
                unset($message['_media']);
                // tool_result stays plain text — actual bytes ride in the
                // upcoming follow-up user message instead.
                $message['content'] = (string)($message['content'] ?? '');
                $out[] = $message;
                continue;
            }

            if (empty($message['attachments']) || !is_array($message['attachments'])) {
                if (array_key_exists('attachments', $message)) {
                    unset($message['attachments']);
                }
                $out[] = $message;
                continue;
            }

            $markerLines = [];
            foreach ($message['attachments'] as $ref) {
                if (!is_array($ref)) {
                    continue;
                }
                $markerLines[] = $this->buildAttachmentMarker($ref, $this->attachmentNoteFor($ref));
            }

            $userText = (string)($message['content'] ?? '');
            $markerBlock = "---\nAngehängte Dateien (Inhalt via ReadFile abrufbar):\n" . implode("\n", $markerLines);
            $message['content'] = $userText !== '' ? rtrim($userText) . "\n\n" . $markerBlock : $markerBlock;
            unset($message['attachments']);
            $out[] = $message;
        }

        // Flush any media that came from the last batch in the conversation.
        $flushMedia();
        return $out;
    }

    /**
     * Produce the marker-line note for an attachment. Image/PDF inside the
     * size limit need no note (LLM can call ReadFile to inspect). Oversize
     * and unsupported get a hint so the LLM doesn't waste a tool call.
     *
     * @param array<string, mixed> $ref
     */
    private function attachmentNoteFor(array $ref): ?string
    {
        $info = $this->attachmentService->classify($ref);
        return match ($info['kind']) {
            'unresolvable' => 'Datei nicht auflösbar',
            'unsupported' => 'Format nicht über ReadFile lesbar',
            'oversize' => $info['reason'] . ' — Inhalt nicht abrufbar',
            default => null,
        };
    }

    /**
     * UI pre-flight for one attachment. Cheap (metadata-only, no getContents()
     * call) so the chat frontend can call it eagerly after each add.
     *
     * Runs under the *request* BE_USER's permissions, not the task's — which
     * is what we want: the user only sees pre-flight info for files they can
     * actually access.
     *
     * `readableByLlm` answers: can the LLM retrieve the file's bytes by
     * calling the `ReadFile` tool? True for images / PDFs within size
     * limits. False means the LLM can only see the marker metadata; the
     * `reason` field then explains why (oversize, unsupported MIME, etc.).
     *
     * @param array<string, mixed> $ref
     * @return array{uid: int, identifier: string, name: string, mime: string, size: int, readableByLlm: bool, reason: ?string}
     */
    public function previewAttachment(array $ref): array
    {
        $info = $this->attachmentService->classify($ref);
        $file = $info['file'];
        return [
            'uid' => $file?->getUid() ?? (int)($ref['uid'] ?? 0),
            'identifier' => $file?->getCombinedIdentifier() ?? (string)($ref['identifier'] ?? ''),
            'name' => $file?->getName() ?? (string)($ref['name'] ?? ''),
            'mime' => $info['mime'],
            'size' => $info['size'],
            'readableByLlm' => in_array($info['kind'], ['image', 'document'], true),
            'reason' => $info['reason'],
        ];
    }

    /**
     * Build a single marker line for the text portion of a user message.
     *
     * @param array<string, mixed> $ref
     */
    private function buildAttachmentMarker(array $ref, ?string $note): string
    {
        if (!empty($ref['unresolvable'])) {
            $label = trim((string)($ref['name'] ?? ''));
            return '- ' . ($label !== '' ? $label : 'Unbenannte Datei') . ' (Datei nicht auflösbar)';
        }

        $uid = (int)($ref['uid'] ?? 0);
        $identifier = (string)($ref['identifier'] ?? '');
        $mime = (string)($ref['mime_type'] ?? 'application/octet-stream');
        if ($mime === '') {
            $mime = 'application/octet-stream';
        }
        $parens = $note !== null && $note !== '' ? $mime . ', ' . $note : $mime;
        $head = $uid > 0 ? 'sys_file:' . $uid : ($identifier !== '' ? $identifier : (string)($ref['name'] ?? 'Unbenannte Datei'));
        $path = $identifier !== '' ? ' — ' . $identifier : '';
        if ($uid <= 0) {
            $path = '';
        }
        return '- ' . $head . $path . ' (' . $parens . ')';
    }
}
