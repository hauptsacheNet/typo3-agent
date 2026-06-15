<?php

declare(strict_types=1);

namespace Hn\Agent\MCP\Tool;

use Hn\Agent\Service\AttachmentService;
use Hn\Agent\Service\DocumentExtractionException;
use Hn\Agent\Service\DocumentExtractorService;
use Hn\McpServer\MCP\Tool\AbstractTool;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;

/**
 * Read cells from a spreadsheet file (XLSX/ODS/XLS/CSV) by FAL sys_file UID.
 *
 * Without a `sheet` parameter the tool returns a structural outline
 * (sheet names, row/column extents) so the LLM can decide which sheet
 * to drill into. With `sheet` it returns tab-separated cell values for
 * the given `a1_range` (or the entire sheet). Output is capped — when
 * truncated, the response hints at narrowing the range.
 *
 * Auto-registered into the MCP ToolRegistry via the `mcp.tool` Symfony tag
 * declared on Hn\McpServer\MCP\Tool\ToolInterface.
 */
class ReadSpreadsheetTool extends AbstractTool
{
    public function __construct(
        private readonly AttachmentService $attachmentService,
        private readonly DocumentExtractorService $extractor,
    ) {}

    public function getSchema(): array
    {
        return [
            'description' => 'Read cells from a spreadsheet (XLSX/ODS/XLS/CSV) in FAL by sys_file UID. '
                . 'Without "sheet" returns an outline (names + dimensions of every sheet). '
                . 'With "sheet" (name or zero-based index) returns tab-separated cell values for "a1_range" '
                . '(e.g. "A1:Z100") — or the whole sheet if a1_range is omitted. '
                . 'Output is capped at ~50k characters; if truncated, narrow a1_range. '
                . 'Accepts sys_file_reference / sys_file_metadata UIDs as a fallback.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'uid' => [
                        'type' => 'integer',
                        'description' => 'The sys_file UID of the spreadsheet.',
                    ],
                    'sheet' => [
                        'description' => 'Sheet name (string) or zero-based index (integer). Omit to get an outline of all sheets.',
                        'oneOf' => [
                            ['type' => 'string'],
                            ['type' => 'integer'],
                        ],
                    ],
                    'a1_range' => [
                        'type' => 'string',
                        'description' => 'A1-notation range like "A1:Z100". Omit to read the whole sheet.',
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

        [$info, $resolutionNote] = $this->attachmentService->resolveWithFallback($uid);
        $head = $resolutionNote !== null ? $resolutionNote . "\n" : '';

        if ($info['kind'] === 'unresolvable') {
            return new CallToolResult(
                [new TextContent(sprintf('UID %d could not be resolved as sys_file, sys_file_reference, or sys_file_metadata.', $uid))],
                true,
            );
        }
        if ($info['kind'] === 'oversize') {
            return new CallToolResult(
                [new TextContent(sprintf('%ssys_file:%d (%s) is %s — spreadsheet reading is capped at %s.',
                    $head, $info['file']->getUid(), $info['mime'],
                    $this->attachmentService->formatBytes($info['size']),
                    $this->attachmentService->formatBytes(AttachmentService::MAX_OFFICE_BYTES),
                ))],
                true,
            );
        }
        if ($info['kind'] !== 'spreadsheet') {
            return new CallToolResult(
                [new TextContent(sprintf('%ssys_file:%d has MIME %s. ReadSpreadsheet only handles XLSX/ODS/XLS/CSV — pick the right tool (ReadPdfText / ReadDocument / ReadPresentation / ViewImage) or call GetFileInfo.',
                    $head, $info['file']->getUid(), $info['mime'] !== '' ? $info['mime'] : 'application/octet-stream',
                ))],
                true,
            );
        }

        try {
            if (!array_key_exists('sheet', $params)) {
                $outline = $this->extractor->getSpreadsheetOutline($info['file']);
                return new CallToolResult([new TextContent($this->formatOutline($head, $info['file'], $outline))]);
            }

            $sheet = $params['sheet'];
            if (!is_string($sheet) && !is_int($sheet)) {
                return new CallToolResult([new TextContent('Error: parameter "sheet" must be a string (name) or integer (zero-based index).')], true);
            }
            $a1Range = isset($params['a1_range']) ? (string)$params['a1_range'] : null;
            if ($a1Range === '') {
                $a1Range = null;
            }
            $result = $this->extractor->extractSpreadsheetRange($info['file'], $sheet, $a1Range);
        } catch (DocumentExtractionException $e) {
            return new CallToolResult([new TextContent($head . 'Error: ' . $e->getMessage())], true);
        }

        $metadata = sprintf(
            "%sFile: %s\nUID: sys_file:%d\nSheet: %s\nDimensionen: %d Zeilen × %d Spalten\nGelesener Bereich: %s",
            $head,
            $info['file']->getName(),
            $info['file']->getUid(),
            $result['sheetName'],
            $result['totalRows'],
            $result['totalCols'],
            $result['rangeUsed'],
        );

        $body = $this->extractor->capOutput(
            $result['text'],
            'Weiter mit ReadSpreadsheet und engerer a1_range (z. B. nur Spalten A:E oder kleinere Zeilen-Range).',
        );

        return new CallToolResult([new TextContent($metadata . "\n\n" . $body)]);
    }

    /**
     * @param array{sheets: list<array{index: int, name: string, rows: int, cols: int}>, activeSheet: string} $outline
     */
    private function formatOutline(string $head, \TYPO3\CMS\Core\Resource\File $file, array $outline): string
    {
        $lines = [];
        $lines[] = $head . sprintf('File: %s', $file->getName());
        $lines[] = sprintf('UID: sys_file:%d', $file->getUid());
        $lines[] = sprintf('Aktives Sheet: %s', $outline['activeSheet']);
        $lines[] = '';
        $lines[] = 'Sheets:';
        foreach ($outline['sheets'] as $sheet) {
            $lines[] = sprintf('  [%d] "%s" — %d Zeilen × %d Spalten', $sheet['index'], $sheet['name'], $sheet['rows'], $sheet['cols']);
        }
        $lines[] = '';
        $lines[] = 'Erneut aufrufen mit "sheet" (Name oder Index) und optional "a1_range" um Zellen zu lesen.';
        return implode("\n", $lines);
    }
}
