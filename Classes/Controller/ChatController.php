<?php

declare(strict_types=1);

namespace Hn\Agent\Controller;

use Hn\Agent\Domain\AgentTaskRepository;
use Hn\Agent\EventListener\AgentEventRelay;
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
        private readonly AgentEventRelay       $agentEventRelay,
    )
    {
    }

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle('AI Chat');

        $userId = (int)($GLOBALS['BE_USER']->user['uid'] ?? 0);
        $chats = $this->repository->findChatsForUser($userId);

        $this->addReloadButton($view, $request);

        return $view->assignMultiple([
            'chats' => $chats,
            'newUri' => (string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat.new'),
        ])->renderResponse('Chat/Index');
    }

    public function showAction(ServerRequestInterface $request): ResponseInterface
    {
        $taskUid = (int)($request->getQueryParams()['task'] ?? 0);
        if ($taskUid <= 0) {
            return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat'));
        }

        $task = $this->loadTaskForCurrentUser($taskUid);
        if ($task === null) {
            return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat'));
        }

        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle('AI Chat', $task['title']);

        $this->addBackButton($view);

        $messages = $this->agentService->decodeMessages($task['messages'] ?? null) ?? [];
        $isNewTask = $messages === [] && !empty($task['prompt']);

        return $view->assignMultiple([
            'task' => $task,
            'messages' => $messages,
            'isNewTask' => $isNewTask,
            'sendUri' => (string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat.sendMessage', [
                'task' => $taskUid,
            ]),
            'streamUri' => (string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat.streamMessage', [
                'task' => $taskUid,
            ]),
        ])->renderResponse('Chat/Show');
    }

    public function newAction(ServerRequestInterface $request): ResponseInterface
    {
        $message = trim((string)($request->getParsedBody()['message'] ?? ''));
        if ($message === '') {
            return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat'));
        }

        $userId = (int)($GLOBALS['BE_USER']->user['uid'] ?? 0);
        $taskUid = $this->repository->insert(0, $userId, mb_substr($message, 0, 80), $message);
        return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat.show', [
            'task' => $taskUid,
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

    private function addBackButton($view): void
    {
        $buttonBar = $view->getDocHeaderComponent()->getButtonBar();
        $backButton = $buttonBar->makeLinkButton()
            ->setHref((string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat'))
            ->setTitle('Back to chat list')
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-view-go-back', IconSize::SMALL));
        $buttonBar->addButton($backButton);
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
        $agentEventRelay = $this->agentEventRelay;

        if ($isInitialProcessing) {
            return $this->buildSseResponse(static function (callable $send) use ($agentService, $agentEventRelay, $taskUid): void {
                $agentEventRelay->setCallback($send);
                try {
                    $agentService->processTask($taskUid);
                    $send('done', ['status' => 2]);
                } catch (\Throwable $e) {
                    $send('error', ['error' => $e->getMessage(), 'status' => 3]);
                } finally {
                    $agentEventRelay->clearCallback();
                }
            });
        }

        return $this->buildSseResponse(static function (callable $send) use ($agentService, $agentEventRelay, $taskUid, $message): void {
            $agentEventRelay->setCallback($send);
            try {
                $messages = $agentService->continueChat($taskUid, $message);
                $send('done', ['status' => 2, 'messages' => $messages]);
            } catch (\Throwable $e) {
                $send('error', ['error' => $e->getMessage(), 'status' => 3]);
            } finally {
                $agentEventRelay->clearCallback();
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
