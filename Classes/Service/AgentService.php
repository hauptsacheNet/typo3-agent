<?php

declare(strict_types=1);

namespace Hn\Agent\Service;

use Hn\Agent\Domain\AgentInstructionRepository;
use Hn\Agent\Domain\AgentTaskRepository;
use Hn\Agent\Domain\TaskStatus;
use Hn\Agent\Http\ClientDisconnectedException;
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
        private readonly InstructionTextFormatter $instructionTextFormatter,
        private readonly ChangeTracker $changeTracker,
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
            try {
                $this->emitInitialContextEvents($messages, $progress);
            } catch (ClientDisconnectedException $e) {
                // Disconnect during the synthetic-context emit, before runLoop
                // ever started. Mark Cancelled but keep the initial messages so
                // the chat can be resumed from the persisted context.
                $this->repository->saveState($taskUid, $messages, TaskStatus::Cancelled);
                return;
            }
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
        $attachmentRefs = $this->attachmentService->normalizeRefs($attachments);

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
                    $progress('llm_start', []);
                }

                // Per-delta callback for streaming LLM chunks. Rewrites raw SSE
                // delta types ('content', 'tool_call', 'finish') into the agent-level
                // progress events ('content_delta', 'tool_call_delta'); 'finish' is
                // intentionally dropped since 'assistant_message' below marks the
                // end of an iteration for downstream consumers.
                $onDelta = $progress === null
                    ? null
                    : static function (string $deltaType, array $payload) use ($progress): void {
                        if ($deltaType === 'content') {
                            $progress('content_delta', [
                                'text' => $payload['text'] ?? '',
                            ]);
                            return;
                        }
                        if ($deltaType === 'reasoning') {
                            $progress('reasoning_delta', [
                                'text' => $payload['text'] ?? '',
                            ]);
                            return;
                        }
                        if ($deltaType === 'tool_call') {
                            $progress('tool_call_delta', $payload);
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
                    $change = $this->changeTracker->track($taskUid, $toolResult['text']);
                    if ($change !== null && $progress !== null) {
                        $progress('change_tracked', $change);
                    }

                    // Append tool result message. When the tool returned binary
                    // payloads (e.g. ReadFile on a sys_file image), `content`
                    // becomes a block array [text, image_url|file] — the same
                    // shape the LLM consumes. The UI's tool-result renderer
                    // extracts the text portion via a small helper.
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCallId,
                        'content' => $this->buildToolContent($toolResult['text'], $toolResult['media']),
                    ];

                    if ($progress !== null) {
                        $progress('tool_result', [
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
        } catch (ClientDisconnectedException $e) {
            // User aborted the SSE stream. Persist whatever was generated so
            // far so the chat stays resumable, mark Cancelled, and swallow the
            // exception — the connection is gone, propagating would only
            // trigger another doomed $send up the chain.
            $this->repository->saveState($taskUid, $messages, TaskStatus::Cancelled);
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
        $systemPrompt .= $this->buildInstructionsSection();

        $userMessage = ['role' => 'user', 'content' => $prompt];
        $attachmentRefs = $this->attachmentService->normalizeRefs($rawAttachments);
        if ($attachmentRefs !== []) {
            $userMessage['attachments'] = $attachmentRefs;
        }
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        $messages[] = $userMessage;

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



        return $messages;
    }

    /**
     * Build the editor-maintained instructions block appended to the system
     * prompt. Instructions are tx_agent_instruction records (tone of voice,
     * how to handle certain content elements/records, …) following the
     * SKILL.md progressive-disclosure idea:
     *
     *  - "always" instructions are inlined in full (global base rules).
     *  - "on_demand" instructions are only indexed (name + "when to use"); the
     *    agent loads the full body via the GetInstruction tool when relevant.
     *
     * Returns an empty string when there are no active instructions, so the
     * system prompt is left untouched in that case. The block is baked into
     * the stored system message at task-creation time — it therefore applies
     * to newly started chats, not to ones already in progress.
     */
    private function buildInstructionsSection(): string
    {
        $always = $this->instructionRepository->findAlways();
        $onDemand = $this->instructionRepository->findOnDemand();
        if ($always === [] && $onDemand === []) {
            return '';
        }

        $section = '';

        if ($always !== []) {
            $section .= "\n\n# Editorial guidelines\n"
                . "The following guidance was maintained by the editorial team and must be "
                . "followed for all texts and changes you produce:\n";
            foreach ($always as $instruction) {
                $name = trim($instruction['title']) !== '' ? trim($instruction['title']) : 'Guideline';
                $section .= "\n## " . $name . "\n"
                    . $this->instructionTextFormatter->toPromptText($instruction['instruction']) . "\n";
            }
        }

        if ($onDemand !== []) {
            $section .= "\n\n# On-demand instructions\n"
                . "Detailed editorial guidelines are available on demand. Before producing the "
                . "kind of content described below, call the `GetInstruction` tool with the "
                . "relevant id(s) to load the full guideline:\n";
            foreach ($onDemand as $instruction) {
                $name = trim($instruction['title']) !== '' ? trim($instruction['title']) : 'Instruction';
                $hint = trim($instruction['description']);
                $section .= "- [#" . $instruction['uid'] . '] ' . $name
                    . ($hint !== '' ? ' — ' . $hint : '') . "\n";
            }
        }

        return $section;
    }

    /**
     * Emit progress events for the synthetic turns produced by
     * buildInitialMessages() in the same order they sit in the persisted
     * messages array — the chat UI then renders Live-Stream and Reload-View
     * identically. System messages are skipped (hidden in the UI anyway);
     * tool_results are emitted in their natural position and correlated to
     * their tool_call client-side by `tool_call_id`.
     *
     * @param callable(string, array): void $progress
     */
    private function emitInitialContextEvents(array $messages, callable $progress): void
    {
        $toolNameByCallId = [];
        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') !== 'assistant' || empty($msg['tool_calls'])) {
                continue;
            }
            foreach ($msg['tool_calls'] as $toolCall) {
                $toolNameByCallId[(string)($toolCall['id'] ?? '')] = (string)($toolCall['function']['name'] ?? '');
            }
        }

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? '';
            if ($role === 'system') {
                continue;
            }
            if ($role === 'user') {
                $progress('user_message', ['message' => $msg]);
                continue;
            }
            if ($role === 'assistant') {
                $progress('assistant_message', ['message' => $msg]);
                foreach ($msg['tool_calls'] ?? [] as $toolCall) {
                    $progress('tool_start', [
                        'tool_call_id' => (string)($toolCall['id'] ?? ''),
                        'tool_name' => (string)($toolCall['function']['name'] ?? ''),
                        'arguments' => $toolCall['function']['arguments'] ?? '{}',
                    ]);
                }
                continue;
            }
            if ($role === 'tool') {
                $toolCallId = (string)($msg['tool_call_id'] ?? '');
                $progress('tool_result', [
                    'tool_call_id' => $toolCallId,
                    'tool_name' => $toolNameByCallId[$toolCallId] ?? '',
                    'content' => (string)($msg['content'] ?? ''),
                ]);
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
     * Project the persisted message array into the form the LLM client expects.
     *
     * User attachments are surfaced as a metadata-only marker block via
     * AttachmentService::mergeMarkersIntoContent — the LLM has to call
     * `ReadFile` to actually inspect content. Tool messages already carry
     * their media inline in `content` (built by buildToolContent() at write
     * time), so nothing extra is needed for them.
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    private function serializeForLlm(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            if (!is_array($message) || empty($message['attachments']) || !is_array($message['attachments'])) {
                if (is_array($message) && array_key_exists('attachments', $message)) {
                    unset($message['attachments']);
                }
                $out[] = $message;
                continue;
            }

            $message['content'] = $this->attachmentService->mergeMarkersIntoContent(
                (string)($message['content'] ?? ''),
                $message['attachments'],
            );
            unset($message['attachments']);
            $out[] = $message;
        }
        return $out;
    }

    /**
     * Combine a tool's text output and inline media into the persisted/LLM
     * content shape: plain string when there is no media, otherwise a block
     * array `[{type:text,...}, {type:image_url|file,...}, ...]`.
     *
     * @param list<array{mime: string, data: string, filename?: string}> $media
     * @return string|list<array<string, mixed>>
     */
    private function buildToolContent(string $text, array $media): string|array
    {
        if ($media === []) {
            return $text;
        }
        $blocks = [['type' => 'text', 'text' => $text]];
        foreach ($media as $item) {
            $mime = (string)($item['mime'] ?? 'application/octet-stream');
            $data = (string)($item['data'] ?? '');
            if ($data === '') {
                continue;
            }
            $dataUri = 'data:' . $mime . ';base64,' . $data;
            if (str_starts_with($mime, 'image/')) {
                $blocks[] = ['type' => 'image_url', 'image_url' => ['url' => $dataUri]];
            } else {
                $filename = (string)($item['filename'] ?? 'attachment');
                $blocks[] = ['type' => 'file', 'file' => ['filename' => $filename, 'file_data' => $dataUri]];
            }
        }
        return $blocks;
    }

}
