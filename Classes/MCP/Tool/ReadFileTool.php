<?php

declare(strict_types=1);

namespace Hn\Agent\MCP\Tool;

use Doctrine\DBAL\ParameterType;
use Hn\Agent\Service\AttachmentService;
use Hn\Agent\Service\SpreadsheetExtractionService;
use Hn\McpServer\MCP\Tool\AbstractTool;
use Mcp\Types\CallToolResult;
use Mcp\Types\ImageContent;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\File;

/**
 * Read a file from FAL by its sys_file UID. For supported binary formats
 * (PNG/JPEG/WEBP/GIF, PDF) the contents are returned as base64-encoded
 * ImageContent so the LLM can see / inspect them directly. Spreadsheets
 * (XLSX/XLS/ODS/CSV) are parsed server-side into text (plus best-effort
 * embedded images) since the LLM cannot read their raw bytes. Other formats
 * return only metadata.
 *
 * As a usability safety net, the tool also accepts sys_file_reference and
 * sys_file_metadata UIDs — those tables wrap a sys_file via `uid_local`
 * (reference) or `file` (metadata). When `GetPage` lists records on a page
 * the bracketed UID is the *reference* row's uid, not the sys_file uid, and
 * LLMs reliably get this wrong. Rather than fail, we resolve transparently
 * and tell the LLM the canonical sys_file UID in the result so it learns
 * the right reference for next time.
 *
 * Auto-registered into the MCP ToolRegistry via the `mcp.tool` Symfony tag
 * declared on Hn\McpServer\MCP\Tool\ToolInterface.
 */
class ReadFileTool extends AbstractTool
{
    public function __construct(
        private readonly AttachmentService $attachmentService,
        private readonly ConnectionPool $connectionPool,
        private readonly SpreadsheetExtractionService $spreadsheetExtractor,
    ) {}

    public function getSchema(): array
    {
        return [
            'description' => 'Read a file from TYPO3\'s File Abstraction Layer (FAL) by its sys_file UID. '
                . 'For images (PNG/JPEG/WEBP/GIF) and PDFs the binary content is returned base64-encoded '
                . 'so it can be displayed / analyzed inline. Spreadsheets (XLSX/XLS/ODS/CSV) are parsed '
                . 'server-side and returned as text (one block per sheet) plus any embedded images. '
                . 'For other file types only metadata '
                . '(name, MIME, size) is returned. Use this whenever you encounter a sys_file:UID '
                . 'reference (e.g. from ReadTable on sys_file / sys_file_reference / sys_file_metadata) '
                . 'and need to actually inspect the file content. '
                . 'If you pass a sys_file_reference or sys_file_metadata UID by mistake the tool '
                . 'transparently resolves it to the underlying sys_file and tells you the canonical '
                . 'sys_file UID in the result.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'uid' => [
                        'type' => 'integer',
                        'description' => 'The sys_file UID to read. Accepts sys_file_reference / sys_file_metadata UIDs as a fallback.',
                    ],
                ],
                'required' => ['uid'],
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'idempotentHint' => true,
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $uid = (int)($params['uid'] ?? 0);
        if ($uid <= 0) {
            return new CallToolResult([new TextContent('Error: parameter "uid" is required and must be a positive integer.')], true);
        }

        [$info, $resolutionNote] = $this->resolveWithFallback($uid);
        $file = $info['file'];

        if ($info['kind'] === 'unresolvable') {
            return new CallToolResult(
                [new TextContent(sprintf(
                    'UID %d could not be resolved as sys_file, sys_file_reference, or sys_file_metadata.',
                    $uid,
                ))],
                true,
            );
        }

        $metadata = $this->describe($file, $info['mime'], $info['size']);
        if ($resolutionNote !== null) {
            $metadata = $resolutionNote . "\n" . $metadata;
        }

        if ($info['kind'] === 'unsupported') {
            return new CallToolResult([
                new TextContent($metadata . "\nContent type not supported for inline retrieval — metadata only."),
            ]);
        }

        if ($info['kind'] === 'oversize') {
            return new CallToolResult([
                new TextContent($metadata . "\n" . $info['reason'] . ' — metadata only.'),
            ]);
        }

        if ($info['kind'] === 'spreadsheet') {
            $extracted = $this->spreadsheetExtractor->extract($file);
            $note = $extracted['truncated'] ? "\n(Hinweis: Inhalt wurde aus Größengründen gekürzt.)" : '';
            $content = [new TextContent($metadata . $note . "\n\n" . $extracted['text'])];
            foreach ($extracted['images'] as $img) {
                $content[] = new ImageContent($img['data'], $img['mime']);
            }
            return new CallToolResult($content);
        }

        // image / document: embed via ImageContent. For application/pdf the
        // serializeForLlm() side rebuilds a proper `file` block; ImageContent
        // here is just the transport for binary data through the MCP layer.
        $base64 = base64_encode($file->getContents());
        return new CallToolResult([
            new TextContent($metadata),
            new ImageContent($base64, $info['mime']),
        ]);
    }

    /**
     * Try to resolve the given UID as a sys_file first; if that fails, look
     * it up in sys_file_reference (via uid_local) and sys_file_metadata
     * (via file). Returns the classify() result plus an optional resolution
     * note explaining the fallback to the LLM.
     *
     * @return array{0: array{kind: string, mime: string, size: int, file: ?File, reason: ?string}, 1: ?string}
     */
    private function resolveWithFallback(int $uid): array
    {
        $direct = $this->attachmentService->classify(['uid' => $uid]);
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
            $info = $this->attachmentService->classify(['uid' => $fileUid]);
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

    private function describe(File $file, string $mime, int $size): string
    {
        return sprintf(
            "File: %s\nMIME: %s\nSize: %s\nUID: sys_file:%d\nIdentifier: %s",
            $file->getName(),
            $mime !== '' ? $mime : 'application/octet-stream',
            $this->attachmentService->formatBytes($size),
            $file->getUid(),
            $file->getCombinedIdentifier(),
        );
    }
}
