<?php

declare(strict_types=1);

namespace Hn\Agent\Controller;

use Hn\Agent\Domain\AgentTaskRepository;
use Hn\Agent\Domain\TaskEvent;
use Hn\Agent\Domain\TaskStateMachine;
use Hn\Agent\Domain\TaskStatus;
use Hn\Agent\Http\SseStream;
use Hn\Agent\Service\AgentService;
use Hn\Agent\Service\AttachmentService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;

/**
 * SSE-Lifecycle of the agent task — counterpart to {@see ChatController}
 * (HTML pages) and {@see AttachmentController} (file-attachment AJAX).
 *
 * Endpoints, wired in Configuration/Backend/Modules.php, are called by the
 * chat web component via fetch():
 *
 *  - web_typo3_agent_tasks.streamMessage → SSE: drives the agent loop
 *  - web_typo3_agent_tasks.cancelMessage → atomic cancel
 */
#[AsController]
class ChatStreamController
{
    public function __construct(
        private readonly AgentTaskRepository $repository,
        private readonly AgentService        $agentService,
        private readonly AttachmentService   $attachmentService,
        private readonly TaskStateMachine    $stateMachine,
    ) {}

    /**
     * Route web_typo3_agent_tasks.streamMessage — POSTed via fetch() by the
     * chat web component (Build/Sources/TypeScript/chat-element.ts), which
     * gets the URL as its stream-uri attribute from {@see ChatController}.
     *
     * Runs the agent loop for one turn and streams progress as SSE events:
     * either processes a freshly created (Pending) task whose messages are
     * already persisted, or appends the submitted message/attachments to an
     * existing conversation. Emits incremental events via AgentService,
     * terminated by a final `done` (full hydrated message list) or `error`
     * event. Errors (unknown/foreign task, empty input) are also delivered
     * as SSE `error` events, not HTTP error codes, so the client only needs
     * one code path.
     */
    public function streamMessageAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $taskUid = (int)($body['task'] ?? $request->getQueryParams()['task'] ?? 0);
        $message = trim((string)($body['message'] ?? ''));
        $attachments = $this->attachmentService->parseClientPayload($body['attachments'] ?? null);

        if ($taskUid > 0) {
            $userId = (int)($GLOBALS['BE_USER']->user['uid'] ?? 0);
            $isAdmin = (bool)($GLOBALS['BE_USER']->user['admin'] ?? false);
            $task = $this->repository->findByUidForUser($taskUid, $userId, $isAdmin);
        } else {
            $task = null;
        }
        if ($task === null) {
            return $this->buildSseResponse(static function (callable $send): void {
                $send('error', [
                    'error' => 'Invalid task',
                    'status' => 3,
                    'messages' => [],
                ]);
            });
        }

        // Initial processing: a freshly created (Pending) task with no new user input.
        // The persisted messages already contain the initial conversation; AgentService::run()
        // with $userMessage=null streams those and then drives the agent loop.
        // Otherwise the request must carry a non-empty message or attachments.
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
        $attachmentService = $this->attachmentService;
        $userMessage = $isInitialProcessing ? null : $message;

        return $this->buildSseResponse(static function (callable $send) use ($agentService, $attachmentService, $taskUid, $userMessage, $attachments): void {
            try {
                $messages = $agentService->run($taskUid, $userMessage, $send, $attachments);
                $send('done', [
                    'status' => 2,
                    'messages' => $attachmentService->hydrateAttachmentsForClient($messages),
                ]);
            } catch (\Throwable $e) {
                $send('error', ['error' => $e->getMessage(), 'status' => 3]);
            }
        });
    }

    /**
     * Route web_typo3_agent_tasks.cancelMessage — POSTed fire-and-forget
     * by the chat web component when the user hits the Stop button (with
     * keepalive, so it completes even if the tab closes right after); the
     * URL is passed as its cancel-uri attribute by {@see ChatController}.
     *
     * Atomically transitions an in-progress chat task to Cancelled. The
     * agent loop sees the new status at its next iteration and exits
     * without overwriting it. No-op (still 200) when the task is no
     * longer running.
     */
    public function cancelMessageAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $taskUid = (int)($body['task'] ?? $request->getQueryParams()['task'] ?? 0);
        if ($taskUid <= 0) {
            return new JsonResponse(['ok' => false, 'error' => 'Invalid task'], 400);
        }
        $cancelled = $this->stateMachine->trigger($taskUid, TaskEvent::Cancel);
        return new JsonResponse(['ok' => true, 'cancelled' => $cancelled]);
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
