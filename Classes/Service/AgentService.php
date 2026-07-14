<?php

declare(strict_types=1);

namespace Hn\Agent\Service;

use Hn\Agent\Domain\AgentInstructionRepository;
use Hn\Agent\Domain\AgentMessageRepository;
use Hn\Agent\Domain\AgentTaskRepository;
use Hn\Agent\Domain\TaskEvent;
use Hn\Agent\Domain\TaskStateMachine;
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
        private readonly AgentMessageRepository $messageRepository,
        private readonly TaskStateMachine $stateMachine,
        private readonly AttachmentService $attachmentService,
        private readonly MessageLlmSerializer $llmSerializer,
        private readonly AgentInstructionRepository $instructionRepository,
        private readonly InstructionTextFormatter $instructionTextFormatter,
        private readonly ChangeTracker $changeTracker,
    ) {}

    /**
     * Run the agent for an existing task — either to process its initial
     * conversation (no $userMessage) or to append a follow-up message and
     * continue the chat.
     *
     * Initial-processing path ($userMessage === null):
     *   - Claims the task atomically from its current status.
     *   - For fresh (Pending) tasks the persisted messages already contain a
     *     synthetic assistant turn with pre-loaded GetPage/ReadTable context;
     *     those turns are streamed to the UI via $progress so live-stream and
     *     reload-view render identically.
     *
     * Follow-up path ($userMessage !== null):
     *   - Appends a new user message (and optional attachment refs) to
     *     tx_agent_message, creating sys_file_reference rows for the files.
     *   - Then acquires the lease via claim(). The lease CAS
     *     (status != InProgress) guards against concurrent follow-ups.
     *
     * Both paths converge in runLoop() (LLM call → tool execution → repeat).
     *
     * @param callable(string, array): void|null $progress Invoked with (string $event, array $data).
     * @param array<int, array{uid?: int|string, identifier?: string, name?: string}> $attachments
     *        Only consumed on the follow-up path.
     * @return list<array<string, mixed>> The full updated messages array (after runLoop).
     */
    public function run(
        int $taskUid,
        ?string $userMessage = null,
        ?callable $progress = null,
        array $attachments = [],
    ): array {
        $task = $this->repository->findByUid($taskUid);
        if ($task === false) {
            throw new \RuntimeException('Task with UID ' . $taskUid . ' not found.');
        }

        $this->setupBackendUserContext((int)($task['cruser_id'] ?? 0), (int)($task['workspace_id'] ?? 0));

        $pid = (int)($task['pid'] ?? 0);

        if ($userMessage !== null) {
            // Follow-up: resolve attachments under the task's BE_USER (not the request context),
            // append the new user message, then claim the lease.
            $fileUids = $this->attachmentService->resolveClientAttachmentsToFileUids($attachments);
            $this->messageRepository->append(
                $taskUid,
                $pid,
                ['role' => 'user', 'content' => $userMessage],
                $fileUids,
            );

            $claimed = $this->repository->claim($taskUid);
            if (!$claimed) {
                throw new \RuntimeException('Task #' . $taskUid . ' could not be claimed (already in progress by another process?).');
            }
        } else {
            // Initial processing. Pending = newAction just created the task and the agent
            // has never run; the initial conversation (system + synthetic GetPage/ReadTable
            // + user) is already persisted.
            $isFreshTask = (int)$task['status'] === TaskStatus::Pending->value;

            $claimed = $this->repository->claim($taskUid);
            if (!$claimed) {
                throw new \RuntimeException('Task #' . $taskUid . ' could not be claimed (already in progress by another process?).');
            }

            if ($isFreshTask && $progress !== null) {
                $messages = $this->messageRepository->findByTask($taskUid);
                $this->emitInitialContextEvents($messages, $progress);
            }
        }

        return $this->runLoop($taskUid, $pid, $progress);
    }

    /**
     * Core agent loop: call LLM, execute tool calls, repeat until done or max iterations.
     *
     * Caller must ensure: task is claimed (status=1), BE_USER context is set up.
     *
     * @param callable(string, array): void|null $progress
     * @return list<array<string, mixed>>
     */
    private function runLoop(int $taskUid, int $pid, ?callable $progress): array
    {
        try {
            $tools = $this->toolConverterService->convertTools($this->toolRegistry);

            $config = $this->extensionConfiguration->get('agent');
            $maxIterations = (int)($config['maxIterations'] ?? 20);
            $reachedLimit = false;

            for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
                // Cooperative cancellation point. The cancel endpoint flips
                // status from InProgress to Cancelled via TaskStateMachine;
                // we see that on the next iteration and bail.
                $current = $this->repository->findByUid($taskUid);
                if ($current === false || (int)$current['status'] !== TaskStatus::InProgress->value) {
                    return $this->messageRepository->findByTask($taskUid);
                }

                if ($progress !== null) {
                    $progress('llm_start', []);
                }

                $onDelta = $progress === null
                    ? null
                    : static function (string $deltaType, array $payload) use ($progress): void {
                        if ($deltaType === 'content') {
                            $progress('content_delta', ['text' => $payload['text'] ?? '']);
                            return;
                        }
                        if ($deltaType === 'reasoning') {
                            $progress('reasoning_delta', ['text' => $payload['text'] ?? '']);
                            return;
                        }
                        if ($deltaType === 'tool_call') {
                            $progress('tool_call_delta', $payload);
                        }
                    };

                $messages = $this->messageRepository->findByTask($taskUid);
                $llmPayload = $this->llmSerializer->serialize($messages);

                $assistantMessage = $this->llmService->chatCompletionStream(
                    $llmPayload,
                    $tools,
                    $onDelta,
                );

                $this->messageRepository->append($taskUid, $pid, $assistantMessage);

                if ($progress !== null) {
                    $progress('assistant_message', [
                        'message' => $this->attachmentService->hydrateAttachmentsForClient([$assistantMessage])[0],
                    ]);
                }

                $toolCalls = $assistantMessage['tool_calls'] ?? null;
                if (empty($toolCalls)) {
                    break;
                }

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
                        $taskUid,
                    );

                    $change = $this->changeTracker->track($taskUid, $toolResult['text']);
                    if ($change !== null && $progress !== null) {
                        $progress('change_tracked', $change);
                    }

                    $this->messageRepository->append(
                        $taskUid,
                        $pid,
                        [
                            'role' => 'tool',
                            'tool_call_id' => $toolCallId,
                            'tool_name' => $toolName,
                            'content' => $toolResult['text'],
                        ],
                        $toolResult['attachments'],
                    );

                    if ($progress !== null) {
                        $progress('tool_result', [
                            'tool_call_id' => $toolCallId,
                            'tool_name' => $toolName,
                            'content' => $toolResult['text'],
                            'attachments' => $this->attachmentService->hydrateAttachmentsForClient([
                                ['attachments' => $toolResult['attachments']],
                            ])[0]['attachments'] ?? [],
                        ]);
                    }
                }

                if ($iteration === $maxIterations - 1) {
                    $reachedLimit = true;
                }
            }

            $finalMessages = $this->messageRepository->findByTask($taskUid);
            $result = $this->extractResult($finalMessages);

            if ($reachedLimit) {
                $result = '[Agent stopped: reached maximum of ' . $maxIterations . ' iterations]'
                    . ($result !== '' ? "\n\n" . $result : '');
            }
            $this->repository->saveResult($taskUid, $result);
            $this->stateMachine->trigger($taskUid, $reachedLimit ? TaskEvent::Fail : TaskEvent::End);

            return $finalMessages;
        } catch (\Throwable $e) {
            $this->repository->saveResult($taskUid, 'Error: ' . $e->getMessage());
            $this->stateMachine->trigger($taskUid, TaskEvent::Fail);
            throw $e;
        }
    }

    /**
     * Build the initial conversation for a brand-new task and persist it
     * to tx_agent_message. Returns the taskUid → caller inserted the task,
     * we own the message rows.
     *
     * @param array<int, array{uid?: int|string, identifier?: string, name?: string}> $rawAttachments
     */
    public function persistInitialMessages(int $taskUid, int $pid, string $contextTable, int $contextUid, string $prompt, array $rawAttachments = []): void
    {
        $config = $this->extensionConfiguration->get('agent');
        $systemPrompt = $config['systemPrompt'] ?? 'You are a helpful TYPO3 CMS assistant.';
        $systemPrompt .= $this->buildInstructionsSection();

        $this->messageRepository->append($taskUid, $pid, [
            'role' => 'system',
            'content' => $systemPrompt,
        ]);

        // Persist the user prompt right after system, BEFORE the synthetic
        // context turn — the chat UI then renders "user asks" first and the
        // simulated "let me load the working context" bot turn below it,
        // matching the natural conversational order.
        $fileUids = $this->attachmentService->resolveClientAttachmentsToFileUids($rawAttachments);
        $this->messageRepository->append(
            $taskUid,
            $pid,
            ['role' => 'user', 'content' => $prompt],
            $fileUids,
        );

        $toolCalls = [];
        $toolResults = [];
        $loadedParts = [];

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
                    'tool_name' => 'GetPage',
                    'content' => $pageContext,
                ];
                $loadedParts[] = 'Seite #' . $pid;
            }
        }

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
                    'tool_name' => 'ReadTable',
                    'content' => $recordContext,
                ];
                $loadedParts[] = $contextTable . ' #' . $contextUid;
            }
        }

        if ($toolCalls !== []) {
            $this->messageRepository->append($taskUid, $pid, [
                'role' => 'assistant',
                'content' => 'Ich lade zuerst den aktuellen Arbeitskontext: ' . implode(', ', $loadedParts) . '.',
                'tool_calls' => $toolCalls,
            ]);
            foreach ($toolResults as $toolResult) {
                $this->messageRepository->append($taskUid, $pid, $toolResult);
            }
        }
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
     * @param list<array<string, mixed>> $messages
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

        $hydrated = $this->attachmentService->hydrateAttachmentsForClient($messages);

        foreach ($hydrated as $msg) {
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
                    'tool_name' => (string)($msg['tool_name'] ?? $toolNameByCallId[$toolCallId] ?? ''),
                    'content' => (string)($msg['content'] ?? ''),
                    'attachments' => $msg['attachments'] ?? [],
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
     *
     * @param list<array<string, mixed>> $messages
     */
    private function extractResult(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'assistant' && !empty($messages[$i]['content'])) {
                $content = $messages[$i]['content'];
                if (is_string($content)) {
                    return $content;
                }
                if (is_array($content)) {
                    foreach ($content as $block) {
                        if (is_array($block) && ($block['type'] ?? '') === 'text' && isset($block['text'])) {
                            return (string)$block['text'];
                        }
                    }
                }
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
        if (empty($GLOBALS['TCA'])) {
            $tcaFactory = GeneralUtility::getContainer()->get(TcaFactory::class);
            $GLOBALS['TCA'] = $tcaFactory->get();
        }

        $beUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);

        if ($userId > 0) {
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

                $beUser->fetchGroupData();

                $languageServiceFactory = GeneralUtility::makeInstance(LanguageServiceFactory::class);
                $GLOBALS['LANG'] = $languageServiceFactory->createFromUserPreferences($beUser);

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

                $context = GeneralUtility::makeInstance(Context::class);
                $context->setAspect('backend.user', new UserAspect($beUser));
                $context->setAspect('workspace', new WorkspaceAspect($workspaceId));

                return;
            }
        }

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
