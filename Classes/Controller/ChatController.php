<?php

declare(strict_types=1);

namespace Hn\Agent\Controller;

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
    )
    {
    }

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $pageId = $this->getPageId($request);
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle('AI Chat');

        $userId = (int)($GLOBALS['BE_USER']->user['uid'] ?? 0);
        $chats = $this->repository->findChatsForUser($userId, $pageId);

        $this->addReloadButton($view, $request);

        return $view->assignMultiple([
            'chats' => $chats,
            'pageId' => $pageId,
            'newUri' => (string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat.new', ['id' => $pageId]),
        ])->renderResponse('Chat/Index');
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
        $view->setTitle('AI Chat', $task['title']);

        $this->addBackButton($view, $pageId);

        $messages = $this->agentService->decodeMessages($task['messages'] ?? null) ?? [];
        $isNewTask = $messages === [] && !empty($task['prompt']);

        return $view->assignMultiple([
            'task' => $task,
            'messages' => $messages,
            'isNewTask' => $isNewTask,
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
        $message = trim((string)($request->getParsedBody()['message'] ?? ''));
        if ($message === '') {
            return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat', ['id' => $pageId]));
        }

        $userId = (int)($GLOBALS['BE_USER']->user['uid'] ?? 0);
        $taskUid = $this->repository->insert($pageId, $userId, mb_substr($message, 0, 80), $message);
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

    private function addBackButton($view, int $pageId = 0): void
    {
        $buttonBar = $view->getDocHeaderComponent()->getButtonBar();
        $backButton = $buttonBar->makeLinkButton()
            ->setHref((string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat', ['id' => $pageId]))
            ->setTitle('Back to chat list')
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
