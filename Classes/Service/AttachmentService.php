<?php

declare(strict_types=1);

namespace Hn\Agent\Service;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Resolves FAL file references for the agent and classifies a file's
 * eligibility (MIME on the allowlist, within size limits) for inline
 * delivery to the LLM. Does NOT read file bytes — that's the sole
 * responsibility of ReadFileTool.
 *
 * Single source of truth for the supported MIME allowlist and size caps.
 * Used by:
 *  - AgentService — to annotate user-attachment markers with notes like
 *    "zu groß" / "Format nicht unterstützt" so the LLM doesn't bother
 *    calling ReadFile on files that wouldn't deliver bytes anyway.
 *  - ChatController::attachmentPreflightAction — for the chat-UI chip.
 *  - ReadFileTool — to gate the actual content read.
 */
class AttachmentService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const SUPPORTED_IMAGE_MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
    public const SUPPORTED_DOCUMENT_MIME_TYPES = ['application/pdf'];
    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
    public const MAX_DOCUMENT_BYTES = 30 * 1024 * 1024;

    public function __construct(
        private readonly ResourceFactory $resourceFactory,
    ) {}

    /**
     * Classify an attachment for LLM-embedding eligibility, *without* reading
     * the file contents. Reads only FAL metadata.
     *
     * @param array<string, mixed> $ref
     * @return array{kind: 'image'|'document'|'unsupported'|'oversize'|'unresolvable', mime: string, size: int, file: ?File, reason: ?string}
     */
    public function classify(array $ref): array
    {
        if (!empty($ref['unresolvable'])) {
            return ['kind' => 'unresolvable', 'mime' => '', 'size' => 0, 'file' => null, 'reason' => 'Datei nicht auflösbar'];
        }

        $file = $this->resolveFile($ref);
        if (!$file instanceof File) {
            return ['kind' => 'unresolvable', 'mime' => '', 'size' => 0, 'file' => null, 'reason' => 'Datei nicht auflösbar'];
        }

        $mime = strtolower((string)$file->getMimeType());
        $size = (int)$file->getSize();
        $isImage = in_array($mime, self::SUPPORTED_IMAGE_MIME_TYPES, true);
        $isDocument = in_array($mime, self::SUPPORTED_DOCUMENT_MIME_TYPES, true);

        if (!$isImage && !$isDocument) {
            return ['kind' => 'unsupported', 'mime' => $mime, 'size' => $size, 'file' => $file, 'reason' => 'Format nicht unterstützt'];
        }

        $limit = $isImage ? self::MAX_IMAGE_BYTES : self::MAX_DOCUMENT_BYTES;
        if ($size > $limit) {
            return [
                'kind' => 'oversize',
                'mime' => $mime,
                'size' => $size,
                'file' => $file,
                'reason' => sprintf('zu groß (%s > %s)', $this->formatBytes($size), $this->formatBytes($limit)),
            ];
        }

        return ['kind' => $isImage ? 'image' : 'document', 'mime' => $mime, 'size' => $size, 'file' => $file, 'reason' => null];
    }

    /**
     * Resolve an attachment ref (uid or combined identifier) to a FAL File.
     *
     * @param array{uid?: int|string, identifier?: string} $ref
     */
    public function resolveFile(array $ref): ?File
    {
        $uid = (int)($ref['uid'] ?? 0);
        $identifier = trim((string)($ref['identifier'] ?? ''));
        try {
            if ($uid > 0) {
                return $this->resourceFactory->getFileObject($uid);
            }
            if ($identifier !== '') {
                $file = $this->resourceFactory->getFileObjectFromCombinedIdentifier($identifier);
                return $file instanceof File ? $file : null;
            }
        } catch (\Throwable $e) {
            $this->logger?->warning('Attachment could not be resolved', [
                'uid' => $uid,
                'identifier' => $identifier,
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
        $this->logger?->warning('Attachment entry has neither uid nor identifier', [
            'entry' => $ref,
        ]);
        return null;
    }

    public function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return sprintf('%.1f MiB', $bytes / (1024 * 1024));
        }
        if ($bytes >= 1024) {
            return sprintf('%.1f KiB', $bytes / 1024);
        }
        return $bytes . ' B';
    }
}
