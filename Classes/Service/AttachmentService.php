<?php

declare(strict_types=1);

namespace Hn\Agent\Service;

use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Resolves FAL file references for the agent and classifies a file's
 * eligibility (MIME on the allowlist, within size limits) for inline
 * delivery to the LLM. Does NOT read file bytes — those are read by
 * the file-content tools (ViewImage today; later e.g. ReadPdfText).
 *
 * Single source of truth for the supported MIME allowlist and size caps,
 * and for resolving sys_file_reference / sys_file_metadata UIDs to their
 * underlying sys_file.
 *
 * Used by:
 *  - AgentService — to annotate user-attachment markers with notes like
 *    "zu groß" / "Format nicht unterstützt" so the LLM doesn't bother
 *    calling a viewer tool on files that wouldn't deliver bytes anyway.
 *  - ChatController::attachmentPreflightAction — for the chat-UI chip.
 *  - GetFileInfoTool / ViewImageTool — share resolveWithFallback() so
 *    the LLM can pass sys_file_reference UIDs and still get the right
 *    file back.
 */
class AttachmentService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const SUPPORTED_IMAGE_MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
    public const SUPPORTED_PDF_MIME_TYPES = ['application/pdf'];
    public const SUPPORTED_SPREADSHEET_MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.ms-excel',
        'text/csv',
    ];
    public const SUPPORTED_DOCUMENT_MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.oasis.opendocument.text',
        'application/rtf',
        'text/rtf',
        'text/plain',
        'text/markdown',
        'text/html',
    ];
    public const SUPPORTED_PRESENTATION_MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.presentation',
    ];

    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
    public const MAX_PDF_BYTES = 50 * 1024 * 1024;
    public const MAX_OFFICE_BYTES = 25 * 1024 * 1024;

    private const TOOL_FOR_KIND = [
        'image' => 'ViewImage',
        'pdf' => 'ReadPdfText (Text) oder ViewPdfPage (Seite als Bild)',
        'spreadsheet' => 'ReadSpreadsheet',
        'document' => 'ReadDocument',
        'presentation' => 'ReadPresentation',
    ];

    public function __construct(
        private readonly ResourceFactory $resourceFactory,
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Classify an attachment for LLM-embedding eligibility, *without* reading
     * the file contents. Reads only FAL metadata.
     *
     * @param array<string, mixed> $ref
     * @return array{kind: 'image'|'pdf'|'spreadsheet'|'document'|'presentation'|'unsupported'|'oversize'|'unresolvable', mime: string, size: int, file: ?File, reason: ?string}
     */
    private function classify(array $ref): array
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

        [$kind, $maxBytes] = $this->familyFor($mime);
        if ($kind === null) {
            return ['kind' => 'unsupported', 'mime' => $mime, 'size' => $size, 'file' => $file, 'reason' => 'Format nicht unterstützt'];
        }

        if ($size > $maxBytes) {
            return [
                'kind' => 'oversize',
                'mime' => $mime,
                'size' => $size,
                'file' => $file,
                'reason' => sprintf('zu groß (%s > %s)', $this->formatBytes($size), $this->formatBytes($maxBytes)),
            ];
        }

        return ['kind' => $kind, 'mime' => $mime, 'size' => $size, 'file' => $file, 'reason' => null];
    }

    /**
     * @return array{0: ?string, 1: int} Tuple of kind (or null when unsupported) and the per-family byte cap.
     */
    private function familyFor(string $mime): array
    {
        if (in_array($mime, self::SUPPORTED_IMAGE_MIME_TYPES, true)) {
            return ['image', self::MAX_IMAGE_BYTES];
        }
        if (in_array($mime, self::SUPPORTED_PDF_MIME_TYPES, true)) {
            return ['pdf', self::MAX_PDF_BYTES];
        }
        if (in_array($mime, self::SUPPORTED_SPREADSHEET_MIME_TYPES, true)) {
            return ['spreadsheet', self::MAX_OFFICE_BYTES];
        }
        if (in_array($mime, self::SUPPORTED_DOCUMENT_MIME_TYPES, true)) {
            return ['document', self::MAX_OFFICE_BYTES];
        }
        if (in_array($mime, self::SUPPORTED_PRESENTATION_MIME_TYPES, true)) {
            return ['presentation', self::MAX_OFFICE_BYTES];
        }
        return [null, 0];
    }

    /**
     * Resolve an attachment ref (uid or combined identifier) to a FAL File.
     *
     * @param array{uid?: int|string, identifier?: string} $ref
     */
    private function resolveFile(array $ref): ?File
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

    /**
     * Decode a JSON-encoded attachments payload as sent by the chat client.
     * The client posts a JSON string via FormData; each item is shaped
     * `{uid?: int, identifier?: string, name?: string}`. Anything else
     * (missing, empty, non-string, non-JSON, non-list) yields an empty array.
     *
     * @return list<array{uid?: int, identifier?: string, name?: string}>
     */
    public function parseClientPayload(mixed $raw): array
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

    /**
     * Turn raw client attachment input into structured FAL refs ready for
     * persistence in a chat message. Unresolvable entries get a fallback
     * label so the UI can still show them as "(Datei nicht auflösbar)".
     *
     * @param array<int, array<string, mixed>> $raw
     * @return list<array{uid?: int, identifier?: string, name: string, mime_type?: string, unresolvable?: bool}>
     */
    public function normalizeRefs(array $raw): array
    {
        $refs = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $file = $this->resolveFile($entry);
            if ($file instanceof File) {
                $refs[] = [
                    'uid' => $file->getUid(),
                    'identifier' => $file->getCombinedIdentifier(),
                    'name' => $file->getName(),
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                ];
                continue;
            }
            $label = trim((string)($entry['name'] ?? ''));
            if ($label === '') {
                $label = trim((string)($entry['identifier'] ?? ''));
            }
            if ($label === '' && isset($entry['uid'])) {
                $label = 'sys_file:' . (int)$entry['uid'];
            }
            $refs[] = [
                'name' => $label !== '' ? $label : 'Unbenannte Datei',
                'unresolvable' => true,
            ];
        }
        return $refs;
    }

    /**
     * UI pre-flight for one attachment. Cheap (metadata-only, no getContents()
     * call) so the chat frontend can call it eagerly after each add.
     *
     * `readableByLlm` answers: can the LLM retrieve the file's bytes via
     * a viewer tool? True for images (ViewImage), PDFs (ReadPdfText /
     * ViewPdfPage), spreadsheets (ReadSpreadsheet), text documents
     * (ReadDocument) and presentations (ReadPresentation) within their
     * respective size caps. Other MIME types are limited to metadata
     * via GetFileInfo and report `readableByLlm: false` with a `reason`.
     *
     * @param array<string, mixed> $ref
     * @return array{uid: int, identifier: string, name: string, mime: string, size: int, readableByLlm: bool, reason: ?string, via: ?string}
     */
    public function preview(array $ref): array
    {
        $info = $this->classify($ref);
        $file = $info['file'];
        $readable = in_array($info['kind'], ['image', 'pdf', 'spreadsheet', 'document', 'presentation'], true);
        return [
            'uid' => $file?->getUid() ?? (int)($ref['uid'] ?? 0),
            'identifier' => $file?->getCombinedIdentifier() ?? (string)($ref['identifier'] ?? ''),
            'name' => $file?->getName() ?? (string)($ref['name'] ?? ''),
            'mime' => $info['mime'],
            'size' => $info['size'],
            'readableByLlm' => $readable,
            'reason' => $info['reason'],
            'via' => self::TOOL_FOR_KIND[$info['kind']] ?? null,
        ];
    }

    /**
     * Append a marker block listing the structured attachments to a user
     * message's text. The LLM uses these markers to decide which tool to
     * call (ViewImage for images, GetFileInfo for metadata) — the bytes
     * are never sent inline via this path.
     *
     * @param array<int, array<string, mixed>> $attachments
     */
    public function mergeMarkersIntoContent(string $userText, array $attachments): string
    {
        if ($attachments === []) {
            return $userText;
        }
        $markerLines = [];
        foreach ($attachments as $ref) {
            if (!is_array($ref)) {
                continue;
            }
            $markerLines[] = $this->buildMarker($ref, $this->noteFor($ref));
        }
        $markerBlock = "---\nAngehängte Dateien — passendes Lese-Tool je Format aufrufen "
            . "(Bilder: ViewImage; PDFs: ReadPdfText für Text bzw. ViewPdfPage für eine Seite als Bild; "
            . "Spreadsheets: ReadSpreadsheet; Word/ODT/RTF/TXT/MD/HTML: ReadDocument; "
            . "Präsentationen: ReadPresentation; Metadaten: GetFileInfo):\n" . implode("\n", $markerLines);
        return $userText !== '' ? rtrim($userText) . "\n\n" . $markerBlock : $markerBlock;
    }

    /**
     * @param array<string, mixed> $ref
     */
    private function noteFor(array $ref): ?string
    {
        $info = $this->classify($ref);
        return match ($info['kind']) {
            'unresolvable' => 'Datei nicht auflösbar',
            'unsupported' => 'Inhalt nicht direkt lesbar — nur Metadaten via GetFileInfo',
            'oversize' => $info['reason'] . ' — Inhalt nicht abrufbar',
            'pdf' => 'abrufbar via ReadPdfText oder ViewPdfPage',
            'spreadsheet' => 'abrufbar via ReadSpreadsheet',
            'document' => 'abrufbar via ReadDocument',
            'presentation' => 'abrufbar via ReadPresentation',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $ref
     */
    private function buildMarker(array $ref, ?string $note): string
    {
        if (!empty($ref['unresolvable'])) {
            $label = trim((string)($ref['name'] ?? ''));
            return '- ' . ($label !== '' ? $label : 'Unbenannte Datei') . ' (Datei nicht auflösbar)';
        }

        $uid = (int)($ref['uid'] ?? 0);
        $identifier = (string)($ref['identifier'] ?? '');
        $mime = (string)($ref['mime_type'] ?? 'application/octet-stream');
        if ($mime === '') {
            $mime = 'application/octet-stream';
        }
        $parens = $note !== null && $note !== '' ? $mime . ', ' . $note : $mime;
        $head = $uid > 0 ? 'sys_file:' . $uid : ($identifier !== '' ? $identifier : (string)($ref['name'] ?? 'Unbenannte Datei'));
        $path = $uid > 0 && $identifier !== '' ? ' — ' . $identifier : '';
        return '- ' . $head . $path . ' (' . $parens . ')';
    }

    /**
     * Resolve a UID as sys_file first; if that fails, look it up in
     * sys_file_reference (via uid_local) and sys_file_metadata (via file).
     * Returns the classify() result for the resolved sys_file plus an
     * optional resolution note explaining the fallback to the LLM.
     *
     * `GetPage` lists records on a page using the *reference* row's uid,
     * which LLMs reliably get wrong when they then call a file tool. The
     * fallback resolves transparently and reports the canonical sys_file
     * UID so the LLM learns the right reference for next time.
     *
     * @return array{0: array{kind: string, mime: string, size: int, file: ?File, reason: ?string}, 1: ?string}
     */
    public function resolveWithFallback(int $uid): array
    {
        $direct = $this->classify(['uid' => $uid]);
        if ($direct['kind'] !== 'unresolvable') {
            return [$direct, null];
        }

        foreach ([
            ['table' => 'sys_file_reference', 'column' => 'uid_local', 'label' => 'sys_file_reference'],
            ['table' => 'sys_file_metadata', 'column' => 'file', 'label' => 'sys_file_metadata'],
        ] as $candidate) {
            $fileUid = $this->lookupFileUid($candidate['table'], $candidate['column'], $uid);
            if ($fileUid === null) {
                continue;
            }
            $info = $this->classify(['uid' => $fileUid]);
            if ($info['kind'] === 'unresolvable') {
                continue;
            }
            return [
                $info,
                sprintf(
                    'Note: UID %d is a %s row; resolved to underlying sys_file:%d. Use sys_file:%d in further calls.',
                    $uid,
                    $candidate['label'],
                    $fileUid,
                    $fileUid,
                ),
            ];
        }

        return [$direct, null];
    }

    private function lookupFileUid(string $table, string $column, int $rowUid): ?int
    {
        try {
            $qb = $this->connectionPool->getQueryBuilderForTable($table);
            $qb->getRestrictions()->removeAll();
            $row = $qb
                ->select($column)
                ->from($table)
                ->where($qb->expr()->eq('uid', $qb->createNamedParameter($rowUid, ParameterType::INTEGER)))
                ->executeQuery()
                ->fetchAssociative();
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($row) || !isset($row[$column])) {
            return null;
        }
        $fileUid = (int)$row[$column];
        return $fileUid > 0 ? $fileUid : null;
    }
}
