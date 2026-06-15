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
 * Extract plain text from a PDF file (FAL sys_file) for a given page range.
 *
 * Pages are 1-indexed. Range formats: "3", "1-5", "7-" (page 7 to end),
 * "all". Output is capped — when the cap is hit the response includes a
 * truncation hint telling the LLM which next page to request.
 *
 * For rendering a PDF page as image use ViewPdfPage instead.
 *
 * Auto-registered into the MCP ToolRegistry via the `mcp.tool` Symfony tag
 * declared on Hn\McpServer\MCP\Tool\ToolInterface.
 */
class ReadPdfTextTool extends AbstractTool
{
    public function __construct(
        private readonly AttachmentService $attachmentService,
        private readonly DocumentExtractorService $extractor,
    ) {}

    public function getSchema(): array
    {
        return [
            'description' => 'Extract plain text from a PDF (FAL sys_file) for a page range. '
                . 'Page numbers are 1-indexed. Accepts page_range like "3", "1-5", "7-" or "all". '
                . 'Output is capped at ~50k characters — if truncated, the response tells you the next start page. '
                . 'Use ViewPdfPage to render a page as an image instead. '
                . 'Accepts sys_file_reference / sys_file_metadata UIDs as a fallback.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'uid' => [
                        'type' => 'integer',
                        'description' => 'The sys_file UID of the PDF.',
                    ],
                    'page_range' => [
                        'type' => 'string',
                        'description' => 'Pages to read. Examples: "1" (single page), "1-5" (range), "10-" (from page 10 to end), "all" (entire document). Default: "1-".',
                        'default' => '1-',
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
        $rangeSpec = (string)($params['page_range'] ?? '1-');

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
                [new TextContent(sprintf('%ssys_file:%d (%s) is %s — PDF text extraction is capped at %s.',
                    $head, $info['file']->getUid(), $info['mime'],
                    $this->attachmentService->formatBytes($info['size']),
                    $this->attachmentService->formatBytes(AttachmentService::MAX_PDF_BYTES),
                ))],
                true,
            );
        }
        if ($info['kind'] !== 'pdf') {
            return new CallToolResult(
                [new TextContent(sprintf('%ssys_file:%d has MIME %s. ReadPdfText only handles PDFs — pick the right tool for this MIME (ViewImage / ReadSpreadsheet / ReadDocument / ReadPresentation) or call GetFileInfo.',
                    $head, $info['file']->getUid(), $info['mime'] !== '' ? $info['mime'] : 'application/octet-stream',
                ))],
                true,
            );
        }

        try {
            $pageCount = $this->extractor->getPdfPageCount($info['file']);
            $range = $this->extractor->parseRange($rangeSpec, $pageCount);
            $result = $this->extractor->extractPdfPages($info['file'], $range['from'], $range['to']);
        } catch (DocumentExtractionException $e) {
            return new CallToolResult([new TextContent($head . 'Error: ' . $e->getMessage())], true);
        }

        $metadata = sprintf(
            "%sFile: %s\nUID: sys_file:%d\nGesamtseiten: %d\nGelesener Bereich: Seite %d–%d",
            $head,
            $info['file']->getName(),
            $info['file']->getUid(),
            $result['pageCount'],
            $result['fromPage'],
            $result['toPage'],
        );

        $continuationHint = $result['toPage'] < $result['pageCount']
            ? sprintf('Weiterer Inhalt: ReadPdfText erneut mit page_range="%d-" aufrufen.', $result['toPage'] + 1)
            : 'Weiter mit ReadPdfText und engerer page_range, falls Detail benötigt.';

        $body = $this->extractor->capOutput($result['text'], $continuationHint);

        return new CallToolResult([new TextContent($metadata . "\n\n" . $body)]);
    }
}
