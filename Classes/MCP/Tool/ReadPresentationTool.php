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
 * Read text from slides of a presentation file (PPTX / ODP) by FAL
 * sys_file UID.
 *
 * Slides are 1-indexed. With `outline_only=true` the tool returns just
 * the slide titles (cheap, ideal for scoping). Otherwise it returns the
 * text content of slides in `slide_range` (notes included) capped at
 * ~50k characters.
 *
 * Auto-registered into the MCP ToolRegistry via the `mcp.tool` Symfony tag
 * declared on Hn\McpServer\MCP\Tool\ToolInterface.
 */
class ReadPresentationTool extends AbstractTool
{
    public function __construct(
        private readonly AttachmentService $attachmentService,
        private readonly DocumentExtractorService $extractor,
    ) {}

    public function getSchema(): array
    {
        return [
            'description' => 'Read slide text from a presentation (PPTX/ODP) by sys_file UID. '
                . 'Slides are 1-indexed. Accepts slide_range "3", "1-5", "7-" or "all". '
                . 'With outline_only=true returns just the slide titles. '
                . 'Output is capped at ~50k characters; if truncated, narrow slide_range. '
                . 'Accepts sys_file_reference / sys_file_metadata UIDs as a fallback.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'uid' => [
                        'type' => 'integer',
                        'description' => 'The sys_file UID of the presentation.',
                    ],
                    'slide_range' => [
                        'type' => 'string',
                        'description' => 'Slides to read. Examples: "1" (single slide), "1-5", "10-", "all". Default: "1-".',
                        'default' => '1-',
                    ],
                    'outline_only' => [
                        'type' => 'boolean',
                        'description' => 'If true, return just the slide titles (no body text). Default: false.',
                        'default' => false,
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
        $outlineOnly = (bool)($params['outline_only'] ?? false);
        $rangeSpec = (string)($params['slide_range'] ?? '1-');

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
                [new TextContent(sprintf('%ssys_file:%d (%s) is %s — presentation reading is capped at %s.',
                    $head, $info['file']->getUid(), $info['mime'],
                    $this->attachmentService->formatBytes($info['size']),
                    $this->attachmentService->formatBytes(AttachmentService::MAX_OFFICE_BYTES),
                ))],
                true,
            );
        }
        if ($info['kind'] !== 'presentation') {
            return new CallToolResult(
                [new TextContent(sprintf('%ssys_file:%d has MIME %s. ReadPresentation only handles PPTX/ODP — pick the right tool (ReadPdfText / ReadSpreadsheet / ReadDocument / ViewImage) or call GetFileInfo.',
                    $head, $info['file']->getUid(), $info['mime'] !== '' ? $info['mime'] : 'application/octet-stream',
                ))],
                true,
            );
        }

        try {
            if ($outlineOnly) {
                $outline = $this->extractor->getPresentationOutline($info['file']);
                return new CallToolResult([new TextContent($this->formatOutline($head, $info['file'], $outline))]);
            }

            // Need slide count for range parsing; cheapest way is the outline call.
            $outline = $this->extractor->getPresentationOutline($info['file']);
            $slideCount = count($outline['slides']);
            $range = $this->extractor->parseRange($rangeSpec, $slideCount);
            $result = $this->extractor->extractPresentationSlides($info['file'], $range['from'], $range['to']);
        } catch (DocumentExtractionException $e) {
            return new CallToolResult([new TextContent($head . 'Error: ' . $e->getMessage())], true);
        }

        $metadata = sprintf(
            "%sFile: %s\nUID: sys_file:%d\nGesamtslides: %d\nGelesener Bereich: Slide %d–%d",
            $head,
            $info['file']->getName(),
            $info['file']->getUid(),
            $result['slideCount'],
            $result['fromSlide'],
            $result['toSlide'],
        );

        $continuationHint = $result['toSlide'] < $result['slideCount']
            ? sprintf('Weitere Slides: ReadPresentation mit slide_range="%d-".', $result['toSlide'] + 1)
            : 'Weiter mit ReadPresentation und engerer slide_range, falls Detail benötigt.';

        $body = $this->extractor->capOutput($result['text'], $continuationHint);

        return new CallToolResult([new TextContent($metadata . "\n\n" . $body)]);
    }

    /**
     * @param array{slides: list<array{index: int, title: string}>} $outline
     */
    private function formatOutline(string $head, \TYPO3\CMS\Core\Resource\File $file, array $outline): string
    {
        $lines = [];
        $lines[] = $head . sprintf('File: %s', $file->getName());
        $lines[] = sprintf('UID: sys_file:%d', $file->getUid());
        $lines[] = sprintf('Gesamtslides: %d', count($outline['slides']));
        $lines[] = '';
        $lines[] = 'Slides:';
        foreach ($outline['slides'] as $slide) {
            $lines[] = sprintf('  %d. %s', $slide['index'], $slide['title']);
        }
        $lines[] = '';
        $lines[] = 'Erneut aufrufen mit slide_range="X-Y", um die Inhalte zu lesen.';
        return implode("\n", $lines);
    }
}
