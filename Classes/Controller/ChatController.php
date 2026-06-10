<?php

declare(strict_types=1);

namespace Hn\Agent\Controller;

use Doctrine\DBAL\ParameterType;
use Hn\Agent\Domain\AgentSkillRepository;
use Hn\Agent\Domain\AgentTaskRepository;
use Hn\Agent\Domain\TaskStatus;
use Hn\Agent\Http\SseStream;
use Hn\Agent\Renderer\PromptRenderer;
use Hn\Agent\Service\AgentService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;

/**
 * Backend module controller for chatting with the AI agent.
 *
 * Reuses tx_agent_task as the conversation store: each chat is a single
 * task record whose `messages` field grows with every exchange.
 *
 * Actions are wired via sub-routes in Configuration/Backend/Modules.php:
 *  - ai_agent_chat              → indexAction  (chat list)
 *  - ai_agent_chat.show         → showAction   (single chat view)
 *  - ai_agent_chat.new          → newAction    (POST: create chat)
 *  - ai_agent_chat.sendMessage  → sendMessageAction (POST: follow-up)
 *  - ai_agent_chat.switchWorkspace → switchWorkspaceAction (POST: switch BE workspace)
 */
#[AsController]
class ChatController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly UriBuilder            $uriBuilder,
        private readonly IconFactory           $iconFactory,
        private readonly AgentTaskRepository   $repository,
        private readonly AgentService          $agentService,
        protected readonly PageRenderer        $pageRenderer,
        private readonly PageRepository        $pageRepository,
        private readonly ConnectionPool        $connectionPool,
        private readonly PromptRenderer        $promptRenderer,
        private readonly AgentSkillRepository  $skillRepository,
    )
    {
    }

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $pageId = $this->getPageId($request);
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($GLOBALS['LANG']->sL('LLL:EXT:agent/Resources/Private/Language/locallang.xlf:index.heading'));

        $userId = (int)($GLOBALS['BE_USER']->user['uid'] ?? 0);
        $tasks = $this->repository->findTasksForUser($userId, [$pageId]);

        $descendantPageIds = $this->collectDescendantPageIds($pageId);
        $subpageTasks = $this->repository->findTasksForUser($userId, $descendantPageIds);

        $this->addReloadButton($view, $request);

        $languageService = $GLOBALS['LANG'];
        if ($pageId > 0) {
            $pageTitle = BackendUtility::getRecord('pages', $pageId, 'title')['title'] ?? '';
            $placeholder = sprintf($languageService->sL('LLL:EXT:agent/Resources/Private/Language/locallang.xlf:placeholder.page'), $pageTitle);
        } else {
            $placeholder = $languageService->sL('LLL:EXT:agent/Resources/Private/Language/locallang.xlf:placeholder.default');
        }

        $collapsedTables = (array)($GLOBALS['BE_USER']->uc['moduleData']['web_list']['collapsedTables'] ?? []);
        $collapsed = [
            'current' => (bool)($collapsedTables['agent-tasks-current'] ?? false),
            'subpages' => (bool)($collapsedTables['agent-tasks-subpages'] ?? false),
        ];

        $uploadContext = $this->promptRenderer->getUploadContext($pageId, '', 'hn-agent-new-task');

        return $view->assignMultiple([
            'tasks' => $tasks,
            'subpageTasks' => $subpageTasks,
            'pageId' => $pageId,
            'newUri' => (string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat.new', ['id' => $pageId]),
            'placeholder' => $placeholder,
            'workspace' => $this->getActiveWorkspaceInfo(),
            'collapsed' => $collapsed,
            'defaultUploadFolder' => $uploadContext['defaultUploadFolder'],
            'fileBrowserUri' => $uploadContext['fileBrowserUri'],
            'preflightUri' => $uploadContext['preflightUri'],
            'skills' => $this->skillRepository->findActive(),
        ])->renderResponse('Chat/Index');
    }

    /**
     * Returns all page UIDs below the given page, in tree order. When $pageId is 0
     * the entire backend page tree is descended (all root pages and their subpages).
     *
     * @return int[]
     */
    private function collectDescendantPageIds(int $pageId): array
    {
        if ($pageId > 0) {
            return $this->pageRepository->getDescendantPageIdsRecursive($pageId, 999);
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $rootPageIds = $qb
            ->select('uid')
            ->from('pages')
            ->where($qb->expr()->eq('pid', $qb->createNamedParameter(0, ParameterType::INTEGER)))
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchFirstColumn();

        $descendants = [];
        foreach ($rootPageIds as $rootId) {
            $rootId = (int)$rootId;
            $descendants[] = $rootId;
            foreach ($this->pageRepository->getDescendantPageIdsRecursive($rootId, 999) as $childId) {
                $descendants[] = (int)$childId;
            }
        }
        return $descendants;
    }

    public function showAction(ServerRequestInterface $request): ResponseInterface
    {
        $pageId = $this->getPageId($request);
        $taskUid = (int)($request->getQueryParams()['task'] ?? 0);
        if ($taskUid <= 0) {
            return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat', ['id' => $pageId]));
        }

        $task = $this->loadTaskForCurrentUser($taskUid);
        if ($task === null) {
            return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat', ['id' => $pageId]));
        }

        $this->pageRenderer->addInlineSetting('FormEngine', 'moduleUrl', (string)$this->uriBuilder->buildUriFromRoute('record_edit'));
        $this->pageRenderer->addInlineSetting('RecordHistory', 'moduleUrl', (string)$this->uriBuilder->buildUriFromRoute('record_history'));
        $this->pageRenderer->addInlineSetting('Workspaces', 'id', $pageId);
        $this->pageRenderer->addInlineSetting('WebLayout', 'moduleUrl', (string)$this->uriBuilder->buildUriFromRoute('web_layout'));

        $this->pageRenderer->addInlineLanguageLabelFile('EXT:core/Resources/Private/Language/locallang_core.xlf');
        $this->pageRenderer->addInlineLanguageLabelFile('EXT:workspaces/Resources/Private/Language/locallang.xlf');

        // backend.js wird durch workspace-changes.ts per import geladen.
        // Ein separater loadJavaScriptModule-Aufruf würde eine Race Condition
        // erzeugen: backend.js könnte seinen Auto-Fetch vor dem Monkey-Patch
        // in workspace-changes.ts ausführen.
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/multi-record-selection.js');

        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($GLOBALS['LANG']->sL('LLL:EXT:agent/Resources/Private/Language/locallang.xlf:index.heading'), $task['title']);

        $returnUrl = GeneralUtility::sanitizeLocalUrl((string)($task['return_url'] ?? ''));
        $this->addBackButton($view, $pageId, $returnUrl);

        $contextTable = (string)($task['context_table'] ?? '');
        $contextUid = (int)($task['context_uid'] ?? 0);
        $contextLabel = '';
        $contextTableLabel = '';
        if ($contextTable !== '' && $contextUid > 0) {
            $record = BackendUtility::getRecord($contextTable, $contextUid);
            if ($record !== null) {
                $contextLabel = BackendUtility::getRecordTitle($contextTable, $record);
                $contextTableLabel = $GLOBALS['LANG']->sL($GLOBALS['TCA'][$contextTable]['ctrl']['title'] ?? '') ?: $contextTable;
            }
        }

        $messages = $this->agentService->decodeMessages($task['messages'] ?? null) ?? [];
        $isNewTask = (int)($task['status'] ?? 0) === TaskStatus::Pending->value;
        $changes = $this->repository->getChanges($taskUid);

        $uploadContext = $this->promptRenderer->getUploadContext($pageId, $contextTable, 'hn-agent-chat');

        return $view->assignMultiple([
            'task' => $task,
            // Bei einem frischen Task läuft die Initial-Konversation live via
            // emitInitialContextEvents — initial-messages bleibt leer, sonst
            // doppelt sich die User-Bubble (initial-messages + user_message-SSE).
            'messages' => $isNewTask ? [] : $messages,
            'changes' => $changes,
            'isNewTask' => $isNewTask,
            'contextLabel' => $contextLabel,
            'contextTableLabel' => $contextTableLabel,
            'contextUid' => $contextUid,
            'returnUrl' => $returnUrl,
            'taskWorkspace' => $this->getWorkspaceInfoById((int)($task['workspace_id'] ?? 0)),
            'activeWorkspace' => $this->getActiveWorkspaceInfo(),
            'defaultUploadFolder' => $uploadContext['defaultUploadFolder'],
            'fileBrowserUri' => $uploadContext['fileBrowserUri'],
            'sendUri' => (string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat.sendMessage', [
                'task' => $taskUid,
                'id' => $pageId,
            ]),
            'streamUri' => (string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat.streamMessage', [
                'task' => $taskUid,
                'id' => $pageId,
            ]),
            'switchWorkspaceUri' => (string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat.switchWorkspace', [
                'task' => $taskUid,
                'id' => $pageId,
            ]),
            'preflightUri' => $uploadContext['preflightUri'],
            'skills' => $this->skillRepository->findActive(),
        ])->renderResponse('Chat/Show');
    }

    /**
     * Pre-flight check for one attachment from the chat composer: tells the
     * UI whether the file will be embedded as actual content for the LLM,
     * and if not, why. Cheap (FAL metadata only, no getContents()).
     */
    public function attachmentPreflightAction(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $uid = (int)($params['uid'] ?? 0);
        $identifier = trim((string)($params['identifier'] ?? ''));
        if ($uid <= 0 && $identifier === '') {
            return new JsonResponse(['error' => 'uid or identifier required'], 400);
        }
        $ref = [];
        if ($uid > 0) {
            $ref['uid'] = $uid;
        }
        if ($identifier !== '') {
            $ref['identifier'] = $identifier;
        }
        return new JsonResponse($this->agentService->previewAttachment($ref));
    }

    public function newAction(ServerRequestInterface $request): ResponseInterface
    {
        $pageId = $this->getPageId($request);
        $body = (array)$request->getParsedBody();
        $message = trim((string)($body['message'] ?? ''));
        $rawAttachments = $this->parseAttachments($body['attachments'] ?? null);
        if ($message === '' && $rawAttachments === []) {
            return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat', ['id' => $pageId]));
        }

        $workspaceId = (int)($GLOBALS['BE_USER']->workspace ?? 0);
        if ($workspaceId === 0) {
            // Live workspace blocked server-side too — UI normally prevents reaching this branch.
            return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat', ['id' => $pageId]));
        }

        $contextTable = (string)($body['table'] ?? '');
        $contextUid = (int)($body['uid'] ?? 0);
        $returnUrl = GeneralUtility::sanitizeLocalUrl((string)($body['return_url'] ?? ''));

        // FAL permission checks for attachments run against the current
        // BE_USER (= task owner) inside buildInitialMessages.
        $initialMessages = $this->agentService->buildInitialMessages(
            $pageId,
            $contextTable,
            $contextUid,
            $message,
            $rawAttachments,
        );

        $title = $message !== '' ? mb_substr($message, 0, 80) : ($rawAttachments[0]['name'] ?? 'Anhang');
        $userId = (int)($GLOBALS['BE_USER']->user['uid'] ?? 0);
        $taskUid = $this->repository->insert(
            $pageId,
            $userId,
            $title,
            $message,
            $contextTable,
            $contextUid,
            $returnUrl,
            $workspaceId,
            $initialMessages,
        );
        return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat.show', [
            'task' => $taskUid,
            'id' => $pageId,
        ]));
    }

    /**
     * Switch the current backend user's active workspace to the workspace
     * the given task belongs to. Persists the choice in user UC and redirects
     * back to the chat view — must be opened as a top-frame navigation so the
     * backend shell (incl. workspace toolbar) reloads.
     */
    public function switchWorkspaceAction(ServerRequestInterface $request): ResponseInterface
    {
        $pageId = $this->getPageId($request);
        $taskUid = (int)($request->getQueryParams()['task'] ?? $request->getParsedBody()['task'] ?? 0);
        $task = $taskUid > 0 ? $this->loadTaskForCurrentUser($taskUid) : null;
        $targetWorkspace = (int)($task['workspace_id'] ?? 0);
        $beUser = $GLOBALS['BE_USER'] ?? null;

        if ($task !== null && $targetWorkspace > 0 && $beUser !== null && $beUser->checkWorkspace($targetWorkspace)) {
            $beUser->setWorkspace($targetWorkspace);
            $beUser->writeUC();
        }

        $redirectTarget = (string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat.show', [
            'task' => $taskUid,
            'id' => $pageId,
        ]);
        return new RedirectResponse($redirectTarget);
    }

    /**
     * Resolve the currently active workspace of the logged-in backend user.
     * Pure read — no side effects.
     *
     * @return array{id:int,title:string,isLive:bool}
     */
    private function getActiveWorkspaceInfo(): array
    {
        return $this->getWorkspaceInfoById((int)($GLOBALS['BE_USER']->workspace ?? 0));
    }

    /**
     * @return array{id:int,title:string,isLive:bool}
     */
    private function getWorkspaceInfoById(int $workspaceId): array
    {
        if ($workspaceId <= 0) {
            return [
                'id' => 0,
                'title' => $GLOBALS['LANG']->sL('LLL:EXT:agent/Resources/Private/Language/locallang.xlf:workspace.live') ?: 'Live',
                'isLive' => true,
            ];
        }

        $record = BackendUtility::getRecord('sys_workspace', $workspaceId, 'title');
        $title = (string)($record['title'] ?? '');
        if ($title === '') {
            $title = '#' . $workspaceId;
        }
        return [
            'id' => $workspaceId,
            'title' => $title,
            'isLive' => false,
        ];
    }

    public function sendMessageAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $taskUid = (int)($body['task'] ?? $request->getQueryParams()['task'] ?? 0);
        $message = trim((string)($body['message'] ?? ''));
        $attachments = $this->parseAttachments($body['attachments'] ?? null);

        $task = $taskUid > 0 ? $this->loadTaskForCurrentUser($taskUid) : null;
        if ($task === null || ($message === '' && $attachments === [])) {
            if ($this->isAjax($request)) {
                return new JsonResponse(['error' => 'Invalid task or empty message'], 400);
            }
            return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat'));
        }

        $error = null;
        try {
            $messages = $this->agentService->continueChat($taskUid, $message, null, $attachments);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $reloaded = $this->loadTaskForCurrentUser($taskUid);
            $messages = $this->agentService->decodeMessages($reloaded['messages'] ?? null) ?? [];
        }

        if ($this->isAjax($request)) {
            $reloaded = $this->loadTaskForCurrentUser($taskUid);
            return new JsonResponse([
                'messages' => $messages,
                'status' => (int)($reloaded['status'] ?? 3),
                'error' => $error,
            ]);
        }

        return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat.show', [
            'task' => $taskUid,
        ]));
    }

    private function isAjax(ServerRequestInterface $request): bool
    {
        $accept = $request->getHeaderLine('Accept');
        $xhr = $request->getHeaderLine('X-Requested-With');
        return str_contains($accept, 'application/json') || strcasecmp($xhr, 'XMLHttpRequest') === 0;
    }

    private function loadTaskForCurrentUser(int $taskUid): ?array
    {
        $userId = (int)($GLOBALS['BE_USER']->user['uid'] ?? 0);
        $isAdmin = (bool)($GLOBALS['BE_USER']->user['admin'] ?? false);
        return $this->repository->findByUidForUser($taskUid, $userId, $isAdmin);
    }

    private function addReloadButton($view, ServerRequestInterface $request): void
    {
        $buttonBar = $view->getDocHeaderComponent()->getButtonBar();
        $reloadButton = $buttonBar->makeLinkButton()
            ->setHref((string)$request->getAttribute('normalizedParams')->getRequestUri())
            ->setTitle('Reload')
            ->setIcon($this->iconFactory->getIcon('actions-refresh', IconSize::SMALL));
        $buttonBar->addButton($reloadButton);
    }

    private function addBackButton($view, int $pageId = 0, string $returnUrl = ''): void
    {
        $buttonBar = $view->getDocHeaderComponent()->getButtonBar();
        $backButton = $buttonBar->makeLinkButton()
            ->setHref((string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat', ['id' => $pageId]))
            ->setTitle($GLOBALS['LANG']->sL('LLL:EXT:agent/Resources/Private/Language/locallang.xlf:button.backToTasks'))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-view-go-back', IconSize::SMALL));
        $buttonBar->addButton($backButton);
    }

    private function getPageId(ServerRequestInterface $request): int
    {
        return (int)($request->getQueryParams()['id'] ?? $request->getParsedBody()['id'] ?? 0);
    }

    public function streamMessageAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $taskUid = (int)($body['task'] ?? $request->getQueryParams()['task'] ?? 0);
        $message = trim((string)($body['message'] ?? ''));
        $attachments = $this->parseAttachments($body['attachments'] ?? null);

        $task = $taskUid > 0 ? $this->loadTaskForCurrentUser($taskUid) : null;
        if ($task === null) {
            return $this->buildSseResponse(static function (callable $send): void {
                $send('error', [
                    'error' => 'Invalid task',
                    'status' => 3,
                    'messages' => [],
                ]);
            });
        }

        $isInitialProcessing = (int)($task['status'] ?? 0) === TaskStatus::Pending->value
            && $message === '' && $attachments === [];

        if (!$isInitialProcessing && $message === '' && $attachments === []) {
            return $this->buildSseResponse(static function (callable $send): void {
                $send('error', [
                    'error' => 'Empty message',
                    'status' => 3,
                    'messages' => [],
                ]);
            });
        }

        $agentService = $this->agentService;

        if ($isInitialProcessing) {
            return $this->buildSseResponse(static function (callable $send) use ($agentService, $taskUid): void {
                try {
                    $agentService->processTask($taskUid, $send);
                    $send('done', ['status' => 2]);
                } catch (\Throwable $e) {
                    $send('error', ['error' => $e->getMessage(), 'status' => 3]);
                }
            });
        }

        return $this->buildSseResponse(static function (callable $send) use ($agentService, $taskUid, $message, $attachments): void {
            try {
                $messages = $agentService->continueChat($taskUid, $message, $send, $attachments);
                $send('done', ['status' => 2, 'messages' => $messages]);
            } catch (\Throwable $e) {
                $send('error', ['error' => $e->getMessage(), 'status' => 3]);
            }
        });
    }

    /**
     * Decode the attachments payload from the request body. The client always
     * sends a JSON-encoded array via FormData; each item shaped
     * `{uid?: int, identifier?: string, name?: string}`.
     *
     * @return array<int, array{uid?: int, identifier?: string, name?: string}>
     */
    private function parseAttachments(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $entry = [];
            if (isset($item['uid']) && (int)$item['uid'] > 0) {
                $entry['uid'] = (int)$item['uid'];
            }
            if (isset($item['identifier']) && is_string($item['identifier']) && $item['identifier'] !== '') {
                $entry['identifier'] = $item['identifier'];
            }
            if (isset($item['name']) && is_string($item['name'])) {
                $entry['name'] = $item['name'];
            }
            if ($entry !== []) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    private function buildSseResponse(\Closure $emitter): ResponseInterface
    {
        return new Response(new SseStream($emitter), 200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Content-Encoding' => 'none',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

}
