<?php

declare(strict_types=1);

namespace Hn\Agent\Controller;

use Hn\Agent\Service\AttachmentService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * AJAX endpoints around file attachments used by the chat UI.
 *
 * Wired in Configuration/Backend/AjaxRoutes.php:
 *  - typo3_agent_tasks_attachment_preflight → preflightAction
 *  - typo3_agent_tasks_attachment_preview → previewAction
 */
#[AsController]
class AttachmentController
{
    public function __construct(
        private readonly AttachmentService $attachmentService,
        private readonly ResourceFactory $resourceFactory,
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

    /**
     * Streams a large rendition of an image attachment for the chat lightbox.
     * Core's thumbnail endpoint caps at 96px (ThumbnailSize::LARGE), so this
     * mirrors ResourceController::requestThumbnailAction with a bigger target
     * size. Works for non-public storages (agent scratch) the same way the
     * thumbnail endpoint does: redirect to the processed file's public URL.
     */
    public function previewAction(ServerRequestInterface $request): ResponseInterface
    {
        $identifier = trim((string)($request->getQueryParams()['identifier'] ?? ''));
        $resource = $identifier !== '' ? $this->resourceFactory->retrieveFileOrFolderObject($identifier) : null;
        if (!$resource instanceof File || !($resource->isImage() || $resource->isMediaFile())) {
            return new Response(null, 404);
        }
        if (!$resource->checkActionPermission('read')) {
            return new Response(null, 403);
        }
        $processed = $resource->process(ProcessedFile::CONTEXT_IMAGECROPSCALEMASK, [
            'width' => '1600m',
            'height' => '1600m',
        ]);
        return new RedirectResponse(
            GeneralUtility::locationHeaderUrl($processed->getPublicUrl() ?? '', $request)
        );
    }
}
