<?php

declare(strict_types=1);

namespace Hn\Agent\Controller;

use Doctrine\DBAL\ParameterType;
use Hn\Agent\Domain\AgentTaskRepository;
use Hn\Agent\Http\SseStream;
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
        private readonly PageRepository        $pageRepository,
        private readonly ConnectionPool        $connectionPool,
    )
    {
    }

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $pageId = $this->getPageId($request);
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($GLOBALS['LANG']->sL('LLL:EXT:agent/Resources/Private/Language/locallang.xlf:index.heading'));

        $userId = (int)($GLOBALS['BE_USER']->user['uid'] ?? 0);
        $tasks = $this->repository->findTasksForUser($userId, $pageId);

        $descendantPageIds = $this->collectDescendantPageIds($pageId);
        $subpageTasks = $this->repository->findTasksForUserOnPages($userId, $descendantPageIds);
        $subpageGroups = $this->buildSubpageGroups($subpageTasks, $descendantPageIds);

        $this->addReloadButton($view, $request);

        $languageService = $GLOBALS['LANG'];
        if ($pageId > 0) {
            $pageTitle = BackendUtility::getRecord('pages', $pageId, 'title')['title'] ?? '';
            $placeholder = sprintf($languageService->sL('LLL:EXT:agent/Resources/Private/Language/locallang.xlf:placeholder.page'), $pageTitle);
        } else {
            $placeholder = $languageService->sL('LLL:EXT:agent/Resources/Private/Language/locallang.xlf:placeholder.default');
        }

        return $view->assignMultiple([
            'tasks' => $tasks,
            'subpageGroups' => $subpageGroups,
            'pageId' => $pageId,
            'newUri' => (string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat.new', ['id' => $pageId]),
            'placeholder' => $placeholder,
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

    /**
     * Groups subpage tasks by pid, resolves the page title and a module URI for each group.
     * Group order follows $orderedPageIds (tree order); pages without tasks are skipped.
     *
     * @param array<int, array<string, mixed>> $subpageTasks
     * @param int[] $orderedPageIds
     * @return array<int, array{pid: int, pageTitle: string, pageUri: ?string, tasks: array<int, array<string, mixed>>}>
     */
    private function buildSubpageGroups(array $subpageTasks, array $orderedPageIds): array
    {
        if ($subpageTasks === []) {
            return [];
        }

        $tasksByPid = [];
        foreach ($subpageTasks as $task) {
            $tasksByPid[(int)$task['pid']][] = $task;
        }

        $groups = [];
        foreach ($orderedPageIds as $pid) {
            $pid = (int)$pid;
            if (!isset($tasksByPid[$pid])) {
                continue;
            }
            $pageRecord = BackendUtility::getRecord('pages', $pid);
            if ($pageRecord !== null) {
                $title = BackendUtility::getRecordTitle('pages', $pageRecord);
                $uri = (string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat', ['id' => $pid]);
            } else {
                $title = 'Seite #' . $pid;
                $uri = null;
            }
            $groups[] = [
                'pid' => $pid,
                'pageTitle' => $title,
                'pageUri' => $uri,
                'tasks' => $tasksByPid[$pid],
            ];
            unset($tasksByPid[$pid]);
        }

        foreach ($tasksByPid as $pid => $tasks) {
            $groups[] = [
                'pid' => $pid,
                'pageTitle' => 'Seite #' . $pid,
                'pageUri' => null,
                'tasks' => $tasks,
            ];
        }

        return $groups;
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
        $isNewTask = $messages === [] && !empty($task['prompt']);

        return $view->assignMultiple([
            'task' => $task,
            'messages' => $messages,
            'isNewTask' => $isNewTask,
            'contextLabel' => $contextLabel,
            'contextTableLabel' => $contextTableLabel,
            'contextUid' => $contextUid,
            'returnUrl' => $returnUrl,
            'sendUri' => (string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat.sendMessage', [
                'task' => $taskUid,
                'id' => $pageId,
            ]),
            'streamUri' => (string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat.streamMessage', [
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
        if ($message === '') {
            return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat', ['id' => $pageId]));
        }

        $contextTable = (string)($body['table'] ?? '');
        $contextUid = (int)($body['uid'] ?? 0);
        $returnUrl = GeneralUtility::sanitizeLocalUrl((string)($body['return_url'] ?? ''));

        $userId = (int)($GLOBALS['BE_USER']->user['uid'] ?? 0);
        $taskUid = $this->repository->insert($pageId, $userId, mb_substr($message, 0, 80), $message, $contextTable, $contextUid, $returnUrl);
        return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat.show', [
            'task' => $taskUid,
            'id' => $pageId,
        ]));
    }

    public function sendMessageAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $taskUid = (int)($body['task'] ?? $request->getQueryParams()['task'] ?? 0);
        $message = trim((string)($body['message'] ?? ''));

        $task = $taskUid > 0 ? $this->loadTaskForCurrentUser($taskUid) : null;
        if ($task === null || $message === '') {
            if ($this->isAjax($request)) {
                return new JsonResponse(['error' => 'Invalid task or empty message'], 400);
            }
            return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat'));
        }

        $error = null;
        try {
            $messages = $this->agentService->continueChat($taskUid, $message);
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

        $existingMessages = $this->agentService->decodeMessages($task['messages'] ?? null);
        $isInitialProcessing = $existingMessages === null && $message === '';

        if (!$isInitialProcessing && $message === '') {
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

        return $this->buildSseResponse(static function (callable $send) use ($agentService, $taskUid, $message): void {
            try {
                $messages = $agentService->continueChat($taskUid, $message, $send);
                $send('done', ['status' => 2, 'messages' => $messages]);
            } catch (\Throwable $e) {
                $send('error', ['error' => $e->getMessage(), 'status' => 3]);
            }
        });
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
