<?php

declare(strict_types=1);

namespace Hn\Agent\Controller;

use Hn\Agent\Service\AttachmentService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * AJAX endpoints around file attachments used by the chat UI.
 *
 * Wired in Configuration/Backend/AjaxRoutes.php:
 *  - typo3_agent_tasks_attachment_preflight → preflightAction
 */
#[AsController]
class AttachmentController
{
    public function __construct(
        private readonly AttachmentService $attachmentService,
    ) {}

    /**
     * Pre-flight check for one attachment from the chat composer: tells the
     * UI whether the file will be embedded as actual content for the LLM,
     * and if not, why. Cheap (FAL metadata only, no getContents()).
     */
    public function preflightAction(ServerRequestInterface $request): ResponseInterface
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
        return new JsonResponse($this->attachmentService->preview($ref));
    }
}
