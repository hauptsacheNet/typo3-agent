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
    public const SUPPORTED_SPREADSHEET_MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',    // xlsx
        'application/vnd.ms-excel.sheet.macroEnabled.12',                       // xlsm
        'application/vnd.openxmlformats-officedocument.spreadsheetml.template', // xltx
        'application/vnd.ms-excel.template.macroEnabled.12',                    // xltm
        'application/vnd.ms-excel',                                             // xls
        'application/vnd.oasis.opendocument.spreadsheet',                       // ods
        'text/csv',
        'text/tab-separated-values',                                           // tsv
    ];
    public const SUPPORTED_SPREADSHEET_EXTENSIONS = ['xlsx', 'xlsm', 'xltx', 'xltm', 'xls', 'ods', 'csv', 'tsv'];
    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
    public const MAX_DOCUMENT_BYTES = 30 * 1024 * 1024;
    public const MAX_SPREADSHEET_BYTES = 30 * 1024 * 1024;

    public function __construct(
        private readonly ResourceFactory $resourceFactory,
    ) {}

    /**
     * Classify an attachment for LLM-embedding eligibility, *without* reading
     * the file contents. Reads only FAL metadata.
     *
     * @param array<string, mixed> $ref
     * @return array{kind: 'image'|'document'|'spreadsheet'|'unsupported'|'oversize'|'unresolvable', mime: string, size: int, file: ?File, reason: ?string}
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
        $extension = strtolower((string)$file->getExtension());
        $size = (int)$file->getSize();
        $isImage = in_array($mime, self::SUPPORTED_IMAGE_MIME_TYPES, true);
        $isDocument = in_array($mime, self::SUPPORTED_DOCUMENT_MIME_TYPES, true);
        // Spreadsheet detection is intentionally lenient: TYPO3 FAL frequently
        // mislabels XLSX/ODS as application/zip, XLS as application/octet-stream
        // and CSV as text/plain, so we also accept by file extension.
        $isSpreadsheet = in_array($mime, self::SUPPORTED_SPREADSHEET_MIME_TYPES, true)
            || in_array($extension, self::SUPPORTED_SPREADSHEET_EXTENSIONS, true);

        if (!$isImage && !$isSpreadsheet && !$isDocument) {
            return ['kind' => 'unsupported', 'mime' => $mime, 'size' => $size, 'file' => $file, 'reason' => 'Format nicht unterstützt'];
        }

        if ($isImage) {
            $kind = 'image';
            $limit = self::MAX_IMAGE_BYTES;
        } elseif ($isSpreadsheet) {
            $kind = 'spreadsheet';
            $limit = self::MAX_SPREADSHEET_BYTES;
        } else {
            $kind = 'document';
            $limit = self::MAX_DOCUMENT_BYTES;
        }

        if ($size > $limit) {
            return [
                'kind' => 'oversize',
                'mime' => $mime,
                'size' => $size,
                'file' => $file,
                'reason' => sprintf('zu groß (%s > %s)', $this->formatBytes($size), $this->formatBytes($limit)),
            ];
        }

        return ['kind' => $kind, 'mime' => $mime, 'size' => $size, 'file' => $file, 'reason' => null];
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
