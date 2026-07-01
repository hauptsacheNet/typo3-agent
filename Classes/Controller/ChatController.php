<?php

declare(strict_types=1);

namespace Hn\Agent\Controller;

use Doctrine\DBAL\ParameterType;
use Hn\Agent\Domain\AgentInstructionRepository;
use Hn\Agent\Domain\AgentTaskRepository;
use Hn\Agent\Domain\TaskStatus;
use Hn\Agent\Renderer\PromptRenderer;
use Hn\Agent\Service\AgentService;
use Hn\Agent\Service\AttachmentService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Backend module controller for the HTML pages of the chat module.
 *
 * Reuses tx_agent_task as the conversation store: each chat is a single
 * task record whose `messages` field grows with every exchange.
 *
 * Realtime/JSON endpoints driven by the chat web component (streamMessage,
 * cancelMessage, attachmentPreflight) live in {@see ChatStreamController}.
 *
 * Actions here are wired via sub-routes in Configuration/Backend/Modules.php:
 *  - web_typo3_agent_tasks       → indexAction (chat list)
 *  - web_typo3_agent_tasks.show  → showAction  (single chat view)
 *  - web_typo3_agent_tasks.new   → newAction   (POST: create chat)
 */
#[AsController]
class ChatController
{
    public function __construct(
        private readonly ModuleTemplateFactory      $moduleTemplateFactory,
        private readonly UriBuilder                 $uriBuilder,
        private readonly IconFactory                $iconFactory,
        private readonly AgentTaskRepository        $repository,
        private readonly AgentService               $agentService,
        private readonly AttachmentService          $attachmentService,
        protected readonly PageRenderer             $pageRenderer,
        private readonly PageRepository             $pageRepository,
        private readonly ConnectionPool             $connectionPool,
        private readonly PromptRenderer             $promptRenderer,
        private readonly AgentInstructionRepository $instructionRepository,
    )
    {
    }

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $pageId = $this->getPageId($request);
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($GLOBALS['LANG']->sL('LLL:EXT:agent/Resources/Private/Language/locallang.xlf:index.heading'));

        $userId = (int)($GLOBALS['BE_USER']->user['uid'] ?? 0);
        $isAdmin = (bool)($GLOBALS['BE_USER']->user['admin'] ?? false);

        $descendantPageIds = $this->collectDescendantPageIds($pageId);

        // Admins: default to "all users", optional narrowing via ?filterUser=<uid>.
        // Non-admins always see only their own tasks.
        $filterUserId = (int)($request->getQueryParams()['filterUser'] ?? 0);
        if ($isAdmin) {
            $effectiveUserId = $filterUserId > 0 ? $filterUserId : null;
            $taskCreators = $this->repository->findTaskCreatorsOnPages(array_merge([$pageId], $descendantPageIds));
        } else {
            $effectiveUserId = $userId;
            $filterUserId = 0;
            $taskCreators = [];
        }

        $tasks = $this->repository->findTasksForUser($effectiveUserId, [$pageId]);
        $subpageTasks = $this->repository->findTasksForUser($effectiveUserId, $descendantPageIds);

        // Enrich tasks with a display label for the creator so the admin
        // view can show a user column. Non-admins never see the column, so
        // the enrichment is skipped for them.
        if ($isAdmin && $taskCreators !== []) {
            $userLabels = [];
            foreach ($taskCreators as $creator) {
                $realName = trim((string)($creator['realName'] ?? ''));
                $username = (string)($creator['username'] ?? '');
                $userLabels[(int)$creator['uid']] = $realName !== ''
                    ? $realName . ' (' . $username . ')'
                    : $username;
            }
            $applyLabel = static function (array &$row) use ($userLabels): void {
                $uid = (int)($row['cruser_id'] ?? 0);
                $row['userLabel'] = $userLabels[$uid] ?? ('User #' . $uid);
            };
            foreach ($tasks as &$task) {
                $applyLabel($task);
            }
            unset($task);
            foreach ($subpageTasks as &$task) {
                $applyLabel($task);
            }
            unset($task);
        }

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
            'instructions' => (bool)($collapsedTables['agent-instructions'] ?? false),
        ];

        $this->promptRenderer->registerUploadContext($pageId, '');

        $instructions = $this->instructionRepository->findActive();
        $canEditInstructions = (bool)$GLOBALS['BE_USER']->check('tables_modify', 'tx_agent_instruction');
        if ($canEditInstructions) {
            $returnUrl = (string)$this->uriBuilder->buildUriFromRoute('web_typo3_agent_tasks', ['id' => $pageId]);
            foreach ($instructions as &$instruction) {
                $instruction['editUri'] = (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                    'edit' => ['tx_agent_instruction' => [$instruction['uid'] => 'edit']],
                    'returnUrl' => $returnUrl,
                ]);
            }
            unset($instruction);
            // Place new records next to existing ones; fall back to the current page.
            $newPid = $instructions[0]['pid'] ?? $pageId;
            $newInstructionUri = (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                'edit' => ['tx_agent_instruction' => [$newPid => 'new']],
                'returnUrl' => $returnUrl,
            ]);
        } else {
            $newInstructionUri = '';
        }

        $workspace = $this->getActiveWorkspaceInfo();

        return $view->assignMultiple([
            'tasks' => $tasks,
            'subpageTasks' => $subpageTasks,
            'pageId' => $pageId,
            'newUri' => (string)$this->uriBuilder->buildUriFromRoute('web_typo3_agent_tasks.new', ['id' => $pageId]),
            'placeholder' => $placeholder,
            'workspace' => $workspace,
            'collapsed' => $collapsed,
            'instructions' => $instructions,
            'canEditInstructions' => $canEditInstructions,
            'newInstructionUri' => $newInstructionUri,
            'isLiveWorkspace' => (bool)$workspace['isLive'],
            'isAdmin' => $isAdmin,
            'taskCreators' => $taskCreators,
            'filterUserId' => $filterUserId,
        ])->renderResponse('Chat/Index');
    }

    public function showAction(ServerRequestInterface $request): ResponseInterface
    {
        $pageId = $this->getPageId($request);
        $taskUid = (int)($request->getQueryParams()['task'] ?? 0);
        if ($taskUid <= 0) {
            return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute('web_typo3_agent_tasks', ['id' => $pageId]));
        }

        $userId = (int)($GLOBALS['BE_USER']->user['uid'] ?? 0);
        $isAdmin = (bool)($GLOBALS['BE_USER']->user['admin'] ?? false);
        $task = $this->repository->findByUidForUser($taskUid, $userId, $isAdmin);
        if ($task === null) {
            return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute('web_typo3_agent_tasks', ['id' => $pageId]));
        }

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

                $permsClause = $GLOBALS['BE_USER']->getPagePermsClause(1);
                $pageUidForHeader = $contextTable === 'pages'
                    ? $contextUid
                    : (int)($record['pid'] ?? 0);
                $pageInfo = $pageUidForHeader > 0
                    ? BackendUtility::readPageAccess($pageUidForHeader, $permsClause)
                    : false;
                if (is_array($pageInfo)) {
                    if ($contextTable !== 'pages') {
                        $pageInfo['_additional_info'] = sprintf('· %s: %s [%d]', $contextTableLabel, $contextLabel, $contextUid);
                    }
                    $view->getDocHeaderComponent()->setMetaInformation($pageInfo);
                }
            }
        }

        $messages = $this->agentService->decodeMessages($task['messages'] ?? null) ?? [];
        $isNewTask = (int)($task['status'] ?? 0) === TaskStatus::Pending->value;
        $changes = $this->repository->getChanges($taskUid);

        $this->promptRenderer->registerUploadContext($pageId, $contextTable);

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
            'streamUri' => (string)$this->uriBuilder->buildUriFromRoute('web_typo3_agent_tasks.streamMessage', [
                'task' => $taskUid,
                'id' => $pageId,
            ]),
            'cancelUri' => (string)$this->uriBuilder->buildUriFromRoute('web_typo3_agent_tasks.cancelMessage', [
                'task' => $taskUid,
                'id' => $pageId,
            ]),
        ])->renderResponse('Chat/Show');
    }

    public function newAction(ServerRequestInterface $request): ResponseInterface
    {
        $pageId = $this->getPageId($request);
        $body = (array)$request->getParsedBody();
        $message = trim((string)($body['message'] ?? ''));
        $rawAttachments = $this->attachmentService->parseClientPayload($body['attachments'] ?? null);
        if ($message === '' && $rawAttachments === []) {
            return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute('web_typo3_agent_tasks', ['id' => $pageId]));
        }

        $workspaceId = (int)($GLOBALS['BE_USER']->workspace ?? 0);
        if ($workspaceId === 0) {
            // Live workspace blocked server-side too — UI normally prevents reaching this branch.
            return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute('web_typo3_agent_tasks', ['id' => $pageId]));
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
        return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute('web_typo3_agent_tasks.show', [
            'task' => $taskUid,
            'id' => $pageId,
        ]));
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

    private function addReloadButton($view, ServerRequestInterface $request): void
    {
        $docHeader = $view->getDocHeaderComponent();
        if (method_exists($docHeader, 'disableAutomaticReloadButton')) {
            return;
        }
        $buttonBar = $docHeader->getButtonBar();
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
            ->setHref((string)$this->uriBuilder->buildUriFromRoute('web_typo3_agent_tasks', ['id' => $pageId]))
            ->setTitle($GLOBALS['LANG']->sL('LLL:EXT:agent/Resources/Private/Language/locallang.xlf:button.backToTasks'))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-view-go-back', IconSize::SMALL));
        $buttonBar->addButton($backButton);
    }

    private function getPageId(ServerRequestInterface $request): int
    {
        return (int)($request->getQueryParams()['id'] ?? $request->getParsedBody()['id'] ?? 0);
    }

}
