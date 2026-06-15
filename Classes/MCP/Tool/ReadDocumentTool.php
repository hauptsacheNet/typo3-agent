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
 * Read plain text from a word-processing or plain-text document
 * (DOCX, ODT, RTF, TXT, MD, HTML) by FAL sys_file UID.
 *
 * These formats have no native pagination, so the tool exposes a
 * character-offset window. The first call returns from `char_offset=0`
 * with the server-side cap (~50k chars); follow-up calls advance the
 * offset to read further.
 *
 * Auto-registered into the MCP ToolRegistry via the `mcp.tool` Symfony tag
 * declared on Hn\McpServer\MCP\Tool\ToolInterface.
 */
class ReadDocumentTool extends AbstractTool
{
    public function __construct(
        private readonly AttachmentService $attachmentService,
        private readonly DocumentExtractorService $extractor,
    ) {}

    public function getSchema(): array
    {
        return [
            'description' => 'Read plain text from a document (DOCX/ODT/RTF/TXT/MD/HTML) by sys_file UID. '
                . 'Documents have no native pages, so reads use a character-offset window. '
                . 'First call: char_offset=0 returns the start (capped at ~50k chars). '
                . 'If the response says "Output gekürzt", call again with char_offset advanced by the returned length. '
                . 'The metadata block reports total character count. '
                . 'Accepts sys_file_reference / sys_file_metadata UIDs as a fallback.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'uid' => [
                        'type' => 'integer',
                        'description' => 'The sys_file UID of the document.',
                    ],
                    'char_offset' => [
                        'type' => 'integer',
                        'description' => 'Start position in characters (0-based). Default: 0.',
                        'default' => 0,
                        'minimum' => 0,
                    ],
                    'char_limit' => [
                        'type' => 'integer',
                        'description' => 'Max characters to return. Server-side hard cap applies (~50k). Default: full cap.',
                        'minimum' => 1,
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
        $charOffset = (int)($params['char_offset'] ?? 0);
        $charLimit = isset($params['char_limit']) ? (int)$params['char_limit'] : DocumentExtractorService::MAX_OUTPUT_CHARS;

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
                [new TextContent(sprintf('%ssys_file:%d (%s) is %s — document reading is capped at %s.',
                    $head, $info['file']->getUid(), $info['mime'],
                    $this->attachmentService->formatBytes($info['size']),
                    $this->attachmentService->formatBytes(AttachmentService::MAX_OFFICE_BYTES),
                ))],
                true,
            );
        }
        if ($info['kind'] !== 'document') {
            return new CallToolResult(
                [new TextContent(sprintf('%ssys_file:%d has MIME %s. ReadDocument only handles DOCX/ODT/RTF/TXT/MD/HTML — pick the right tool (ReadPdfText / ReadSpreadsheet / ReadPresentation / ViewImage) or call GetFileInfo.',
                    $head, $info['file']->getUid(), $info['mime'] !== '' ? $info['mime'] : 'application/octet-stream',
                ))],
                true,
            );
        }

        try {
            $result = $this->extractor->extractDocumentText($info['file'], $charOffset, $charLimit);
        } catch (DocumentExtractionException $e) {
            return new CallToolResult([new TextContent($head . 'Error: ' . $e->getMessage())], true);
        }

        $nextOffset = $result['charOffset'] + $result['returnedChars'];
        $hasMore = $nextOffset < $result['totalChars'];

        $metadata = sprintf(
            "%sFile: %s\nUID: sys_file:%d\nGesamtzeichen: %d\nGelesen: %d Zeichen ab Offset %d%s",
            $head,
            $info['file']->getName(),
            $info['file']->getUid(),
            $result['totalChars'],
            $result['returnedChars'],
            $result['charOffset'],
            $hasMore ? sprintf("\nFortsetzung: char_offset=%d", $nextOffset) : '',
        );

        $continuationHint = $hasMore
            ? sprintf('Fortsetzung: ReadDocument mit char_offset=%d.', $nextOffset)
            : 'Dokument vollständig gelesen.';

        $body = $this->extractor->capOutput($result['text'], $continuationHint);

        return new CallToolResult([new TextContent($metadata . "\n\n" . $body)]);
    }
}
