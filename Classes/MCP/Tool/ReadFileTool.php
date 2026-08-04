<?php

declare(strict_types=1);

namespace Hn\Agent\MCP\Tool;

use Hn\Agent\Service\AttachmentService;
use Hn\Agent\Service\DocumentExtractionException;
use Hn\Agent\Service\DocumentExtractorService;
use Hn\Agent\Service\ImageScalingService;
use Hn\McpServer\MCP\Tool\AbstractTool;
use Mcp\Types\CallToolResult;
use Mcp\Types\ImageContent;
use Mcp\Types\TextContent;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Imaging\GraphicalFunctions;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The single file-reading tool of the agent. Reads any FAL file by
 * sys_file UID and dispatches on the file's MIME type, so the LLM never
 * has to pick a per-format tool:
 *
 *  - image (PNG/JPEG/WEBP/GIF) → bytes as ImageContent, downscaled to
 *    MAX_IMAGE_SIDE via TYPO3's GraphicsMagick/ImageMagick pipeline.
 *  - pdf → text per page `range`; `format=image` renders one page as
 *    JPEG (Ghostscript pipeline); `format=outline` reports the page count.
 *  - spreadsheet (XLSX/ODS/XLS/CSV) → sheet outline until `sheet` is
 *    given, then tab-separated cells for `a1_range`.
 *  - document (DOCX/ODT/RTF/TXT/MD/HTML) → character window advanced
 *    via `char_offset` (these formats have no native pagination).
 *  - presentation (PPTX/ODP) → slide text per `range`; `format=outline`
 *    lists the slide titles.
 *  - any other MIME → metadata only (name, MIME, size), no error.
 *
 * Replaces the former GetFileInfo, ViewImage, ViewPdfPage, ReadPdfText,
 * ReadDocument, ReadSpreadsheet and ReadPresentation tools — one tool is
 * much easier for small models to pick than seven near-identical ones,
 * and "wrong tool for this MIME" errors cannot happen anymore.
 *
 * Accepts sys_file_reference / sys_file_metadata UIDs as a fallback via
 * AttachmentService::resolveWithFallback().
 *
 * Registered agent-only via the `agent.tool` tag in Services.yaml — this
 * tool is NOT exposed through the external MCP server.
 */
class ReadFileTool extends AbstractTool implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const MAX_IMAGE_SIDE = 2048;

    private const PDF_RENDER_WIDTHS = [1500, 1200, 900];
    private const PDF_RENDER_MIME = 'image/jpeg';

    public function __construct(
        private readonly AttachmentService $attachmentService,
        private readonly DocumentExtractorService $extractor,
        private readonly ImageScalingService $imageScalingService,
    ) {}

    public function getSchema(): array
    {
        return [
            'description' => 'Read a file from TYPO3\'s File Abstraction Layer (FAL) by its sys_file UID. '
                . 'This is THE tool for file content of every type — the result depends on the file\'s MIME type: '
                . 'Images (PNG/JPEG/WEBP/GIF) are returned inline as image (downscaled if larger than '
                . self::MAX_IMAGE_SIDE . ' px). '
                . 'PDFs return the text of the pages in "range"; with format="image" exactly one page is rendered as JPEG. '
                . 'Spreadsheets (XLSX/ODS/XLS/CSV) return an outline of all sheets until you pass "sheet" (plus optional "a1_range") to read cells. '
                . 'Text documents (DOCX/ODT/RTF/TXT/MD/HTML) return a ~50k character window; continue with "char_offset". '
                . 'Presentations (PPTX/ODP) return slide text for "range". '
                . 'format="outline" returns structure only (sheet list, slide titles, PDF page count, document length). '
                . 'Every other file type returns metadata only (name, MIME, size). '
                . 'Output is capped at ~50k characters — truncated responses tell you how to continue. '
                . 'Accepts sys_file_reference / sys_file_metadata UIDs as a fallback.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'uid' => [
                        'type' => 'integer',
                        'description' => 'The sys_file UID of the file to read.',
                    ],
                    'format' => [
                        'type' => 'string',
                        'enum' => ['text', 'image', 'outline'],
                        'description' => '"text" (default): textual content. '
                            . '"image": only for PDFs — render exactly one page (set "range" to a single page, e.g. "3") as JPEG; images return the image regardless of format. '
                            . '"outline": structure only — sheet names (spreadsheet), slide titles (presentation), page count (PDF), total characters (document), metadata (image).',
                        'default' => 'text',
                    ],
                    'range' => [
                        'type' => 'string',
                        'description' => 'PDF pages or presentation slides to read (1-indexed). Examples: "3", "1-5", "10-" (from 10 to end), "all". Default: "1-". Ignored for other file types.',
                    ],
                    'sheet' => [
                        'description' => 'Spreadsheets only: sheet name (string) or zero-based index (integer). Omit to get an outline of all sheets first.',
                        'oneOf' => [
                            ['type' => 'string'],
                            ['type' => 'integer'],
                        ],
                    ],
                    'a1_range' => [
                        'type' => 'string',
                        'description' => 'Spreadsheets only: A1-notation cell range like "A1:Z100". Omit to read the whole sheet.',
                    ],
                    'char_offset' => [
                        'type' => 'integer',
                        'description' => 'Text documents only: start position in characters (0-based) to read long documents in windows. Default: 0.',
                        'minimum' => 0,
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

        $format = (string)($params['format'] ?? 'text');
        if (!in_array($format, ['text', 'image', 'outline'], true)) {
            return new CallToolResult([new TextContent('Error: parameter "format" must be "text", "image" or "outline".')], true);
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
                [new TextContent(sprintf(
                    '%ssys_file:%d (%s) ist %s — Inhalt nicht abrufbar. Metadaten:%s%s',
                    $head, $info['file']->getUid(), $info['mime'], $info['reason'],
                    "\n", $this->describe($info['file'], $info['mime'], $info['size']),
                ))],
                true,
            );
        }

        if ($format === 'image' && !in_array($info['kind'], ['pdf', 'image'], true)) {
            return new CallToolResult(
                [new TextContent(sprintf(
                    '%sformat="image" ist nur für PDFs (eine Seite rendern) und Bilder möglich — sys_file:%d ist %s. Verwende format="text".',
                    $head, $info['file']->getUid(), $info['mime'],
                ))],
                true,
            );
        }

        try {
            return match ($info['kind']) {
                'image' => $format === 'outline'
                    ? $this->metadataOnly($head, $info)
                    : $this->readImage($head, $info),
                'pdf' => match ($format) {
                    'image' => $this->renderPdfPage($head, $info, (string)($params['range'] ?? '1')),
                    'outline' => $this->pdfOutline($head, $info),
                    default => $this->readPdfText($head, $info, (string)($params['range'] ?? '1-')),
                },
                'spreadsheet' => $format !== 'outline' && array_key_exists('sheet', $params)
                    ? $this->readSpreadsheet($head, $info, $params)
                    : $this->spreadsheetOutline($head, $info),
                'document' => $format === 'outline'
                    ? $this->documentOutline($head, $info)
                    : $this->readDocument($head, $info, (int)($params['char_offset'] ?? 0)),
                'presentation' => $format === 'outline'
                    ? $this->presentationOutline($head, $info)
                    : $this->readPresentation($head, $info, (string)($params['range'] ?? '1-')),
                default => $this->metadataOnly($head, $info, 'Dateityp wird nicht inhaltlich unterstützt — nur Metadaten verfügbar.'),
            };
        } catch (DocumentExtractionException $e) {
            return new CallToolResult([new TextContent($head . 'Error: ' . $e->getMessage())], true);
        }
    }

    // -----------------------------------------------------------------
    // Metadata (former GetFileInfo)
    // -----------------------------------------------------------------

    /**
     * @param array{kind: string, mime: string, size: int, file: File} $info
     */
    private function metadataOnly(string $head, array $info, ?string $note = null): CallToolResult
    {
        $text = $head . $this->describe($info['file'], $info['mime'], $info['size']);
        if ($note !== null) {
            $text .= "\n" . $note;
        }
        return new CallToolResult([new TextContent($text)]);
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

    // -----------------------------------------------------------------
    // Image (former ViewImage)
    // -----------------------------------------------------------------

    /**
     * @param array{kind: string, mime: string, size: int, file: File} $info
     */
    private function readImage(string $head, array $info): CallToolResult
    {
        $file = $info['file'];
        $srcWidth = (int)$file->getProperty('width');
        $srcHeight = (int)$file->getProperty('height');
        $needsScaling = $srcWidth > self::MAX_IMAGE_SIDE || $srcHeight > self::MAX_IMAGE_SIDE;

        if ($needsScaling) {
            $outputFormat = $info['mime'] === 'image/png' ? 'png' : 'jpg';
            $sourcePath = $file->getForLocalProcessing(false);
            $scaled = $this->imageScalingService->scaleToMaxSide($sourcePath, self::MAX_IMAGE_SIDE, $outputFormat);

            if ($scaled === null) {
                return new CallToolResult(
                    [new TextContent($head . 'Error: Bild konnte nicht skaliert werden — prüfen, ob GraphicsMagick/ImageMagick im Container verfügbar sind.')],
                    true,
                );
            }

            $scaledBytes = $scaled['bytes'];
            if (strlen($scaledBytes) > AttachmentService::MAX_IMAGE_BYTES) {
                return new CallToolResult(
                    [new TextContent(sprintf(
                        '%sSkaliertes Bild ist %s und überschreitet damit %s. Verwende format="outline" für Metadaten.',
                        $head,
                        $this->attachmentService->formatBytes(strlen($scaledBytes)),
                        $this->attachmentService->formatBytes(AttachmentService::MAX_IMAGE_BYTES),
                    ))],
                    true,
                );
            }

            $metadata = $head . sprintf(
                "File: %s\nMIME (Original): %s\nMIME (skaliert): %s\nGröße (Original): %s\nGröße (skaliert): %s\nDimensionen (Original): %d×%d px\nDimensionen (skaliert): %d×%d px (max %d px pro Seite)\nUID: sys_file:%d\nIdentifier: %s",
                $file->getName(),
                $info['mime'],
                $scaled['mime'],
                $this->attachmentService->formatBytes($info['size']),
                $this->attachmentService->formatBytes(strlen($scaledBytes)),
                $srcWidth,
                $srcHeight,
                $scaled['width'],
                $scaled['height'],
                self::MAX_IMAGE_SIDE,
                $file->getUid(),
                $file->getCombinedIdentifier(),
            );
            return new CallToolResult([
                new TextContent($metadata),
                new ImageContent(base64_encode($scaledBytes), $scaled['mime']),
            ]);
        }

        // image within limits: hand bytes off via MCP's ImageContent transport.
        // AgentService::buildToolContent wraps it into the OpenAI `image_url`
        // content block that reaches the LLM.
        $metadata = $head . $this->describe($file, $info['mime'], $info['size']);
        $base64 = base64_encode($file->getContents());
        return new CallToolResult([
            new TextContent($metadata),
            new ImageContent($base64, $info['mime']),
        ]);
    }

    // -----------------------------------------------------------------
    // PDF (former ReadPdfText / ViewPdfPage)
    // -----------------------------------------------------------------

    /**
     * @param array{kind: string, mime: string, size: int, file: File} $info
     */
    private function readPdfText(string $head, array $info, string $rangeSpec): CallToolResult
    {
        $pageCount = $this->extractor->getPdfPageCount($info['file']);
        $range = $this->extractor->parseRange($rangeSpec, $pageCount);
        $result = $this->extractor->extractPdfPages($info['file'], $range['from'], $range['to']);

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
            ? sprintf('Weiterer Inhalt: ReadFile erneut mit range="%d-" aufrufen.', $result['toPage'] + 1)
            : 'Weiter mit ReadFile und engerer range, falls Detail benötigt.';

        $body = $this->extractor->capOutput($result['text'], $continuationHint);

        return new CallToolResult([new TextContent($metadata . "\n\n" . $body)]);
    }

    /**
     * @param array{kind: string, mime: string, size: int, file: File} $info
     */
    private function pdfOutline(string $head, array $info): CallToolResult
    {
        $pageCount = $this->extractor->getPdfPageCount($info['file']);
        $text = $head . $this->describe($info['file'], $info['mime'], $info['size'])
            . sprintf("\nGesamtseiten: %d", $pageCount)
            . "\nErneut aufrufen mit range=\"X-Y\" für den Text oder format=\"image\" + range=\"X\" für eine gerenderte Seite.";
        return new CallToolResult([new TextContent($text)]);
    }

    /**
     * @param array{kind: string, mime: string, size: int, file: File} $info
     */
    private function renderPdfPage(string $head, array $info, string $rangeSpec): CallToolResult
    {
        $pageCount = $this->extractor->getPdfPageCount($info['file']);
        $range = $this->extractor->parseRange($rangeSpec, $pageCount);
        if ($range['from'] !== $range['to']) {
            return new CallToolResult(
                [new TextContent(sprintf(
                    '%sformat="image" rendert genau eine Seite — range auf eine einzelne Seite setzen (z.B. range="%d"). Das PDF hat %d Seite(n).',
                    $head, $range['from'], $pageCount,
                ))],
                true,
            );
        }
        $page = $range['from'];

        $sourcePath = $info['file']->getForLocalProcessing(false);

        foreach (self::PDF_RENDER_WIDTHS as $width) {
            $bytes = $this->renderPage($sourcePath, $width, $page - 1);
            if ($bytes === null) {
                return new CallToolResult(
                    [new TextContent($head . 'Error: PDF konnte nicht gerendert werden — prüfen, ob GraphicsMagick/ImageMagick + Ghostscript im Container verfügbar sind.')],
                    true,
                );
            }
            if (strlen($bytes) <= AttachmentService::MAX_IMAGE_BYTES) {
                $metadata = sprintf(
                    "%sFile: %s\nUID: sys_file:%d\nSeite: %d von %d\nGerendert: JPEG, Breite %d px, %s",
                    $head,
                    $info['file']->getName(),
                    $info['file']->getUid(),
                    $page,
                    $pageCount,
                    $width,
                    $this->attachmentService->formatBytes(strlen($bytes)),
                );
                return new CallToolResult([
                    new TextContent($metadata),
                    new ImageContent(base64_encode($bytes), self::PDF_RENDER_MIME),
                ]);
            }
        }

        return new CallToolResult(
            [new TextContent(sprintf(
                '%sGerenderte Seite überschreitet %s auch bei reduzierter Breite. Verwende stattdessen format="text" für den Inhalt.',
                $head,
                $this->attachmentService->formatBytes(AttachmentService::MAX_IMAGE_BYTES),
            ))],
            true,
        );
    }

    private function renderPage(string $sourcePath, int $width, int $pageZeroBased): ?string
    {
        try {
            $gfx = GeneralUtility::makeInstance(GraphicalFunctions::class);
            $result = $gfx->imageMagickConvert(
                $sourcePath,
                'jpg',
                (string)$width,
                '',
                '-quality 80 -density 144 -background white -flatten',
                (string)$pageZeroBased,
            );
            // imageMagickConvert returns the legacy [w, h, ext, filepath] array via $result?->toLegacyArray().
            if (!is_array($result) || !isset($result[3]) || !is_string($result[3])) {
                return null;
            }
            $renderedPath = $result[3];
            if (!is_file($renderedPath)) {
                return null;
            }
            $bytes = file_get_contents($renderedPath);
            return $bytes === false ? null : $bytes;
        } catch (\Throwable $e) {
            $this->logger?->warning('PDF page rendering failed', [
                'source' => $sourcePath,
                'width' => $width,
                'page' => $pageZeroBased,
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
    }

    // -----------------------------------------------------------------
    // Spreadsheet (former ReadSpreadsheet)
    // -----------------------------------------------------------------

    /**
     * @param array{kind: string, mime: string, size: int, file: File} $info
     * @param array<string, mixed> $params
     */
    private function readSpreadsheet(string $head, array $info, array $params): CallToolResult
    {
        $sheet = $params['sheet'];
        if (!is_string($sheet) && !is_int($sheet)) {
            return new CallToolResult([new TextContent('Error: parameter "sheet" must be a string (name) or integer (zero-based index).')], true);
        }
        $a1Range = isset($params['a1_range']) ? (string)$params['a1_range'] : null;
        if ($a1Range === '') {
            $a1Range = null;
        }
        $result = $this->extractor->extractSpreadsheetRange($info['file'], $sheet, $a1Range);

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
            'Weiter mit ReadFile und engerer a1_range (z. B. nur Spalten A:E oder kleinere Zeilen-Range).',
        );

        return new CallToolResult([new TextContent($metadata . "\n\n" . $body)]);
    }

    /**
     * @param array{kind: string, mime: string, size: int, file: File} $info
     */
    private function spreadsheetOutline(string $head, array $info): CallToolResult
    {
        $outline = $this->extractor->getSpreadsheetOutline($info['file']);
        $lines = [];
        $lines[] = $head . sprintf('File: %s', $info['file']->getName());
        $lines[] = sprintf('UID: sys_file:%d', $info['file']->getUid());
        $lines[] = sprintf('Aktives Sheet: %s', $outline['activeSheet']);
        $lines[] = '';
        $lines[] = 'Sheets:';
        foreach ($outline['sheets'] as $sheet) {
            $lines[] = sprintf('  [%d] "%s" — %d Zeilen × %d Spalten', $sheet['index'], $sheet['name'], $sheet['rows'], $sheet['cols']);
        }
        $lines[] = '';
        $lines[] = 'Erneut aufrufen mit "sheet" (Name oder Index) und optional "a1_range" um Zellen zu lesen.';
        return new CallToolResult([new TextContent(implode("\n", $lines))]);
    }

    // -----------------------------------------------------------------
    // Document (former ReadDocument)
    // -----------------------------------------------------------------

    /**
     * @param array{kind: string, mime: string, size: int, file: File} $info
     */
    private function readDocument(string $head, array $info, int $charOffset): CallToolResult
    {
        $result = $this->extractor->extractDocumentText($info['file'], $charOffset, DocumentExtractorService::MAX_OUTPUT_CHARS);

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
            ? sprintf('Fortsetzung: ReadFile mit char_offset=%d.', $nextOffset)
            : 'Dokument vollständig gelesen.';

        $body = $this->extractor->capOutput($result['text'], $continuationHint);

        return new CallToolResult([new TextContent($metadata . "\n\n" . $body)]);
    }

    /**
     * @param array{kind: string, mime: string, size: int, file: File} $info
     */
    private function documentOutline(string $head, array $info): CallToolResult
    {
        $result = $this->extractor->extractDocumentText($info['file'], 0, 1);
        $text = $head . $this->describe($info['file'], $info['mime'], $info['size'])
            . sprintf("\nGesamtzeichen: %d", $result['totalChars'])
            . "\nErneut aufrufen ohne format (und ggf. char_offset) um den Text zu lesen.";
        return new CallToolResult([new TextContent($text)]);
    }

    // -----------------------------------------------------------------
    // Presentation (former ReadPresentation)
    // -----------------------------------------------------------------

    /**
     * @param array{kind: string, mime: string, size: int, file: File} $info
     */
    private function readPresentation(string $head, array $info, string $rangeSpec): CallToolResult
    {
        // Need slide count for range parsing; cheapest way is the outline call.
        $outline = $this->extractor->getPresentationOutline($info['file']);
        $slideCount = count($outline['slides']);
        $range = $this->extractor->parseRange($rangeSpec, $slideCount);
        $result = $this->extractor->extractPresentationSlides($info['file'], $range['from'], $range['to']);

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
            ? sprintf('Weitere Slides: ReadFile mit range="%d-".', $result['toSlide'] + 1)
            : 'Weiter mit ReadFile und engerer range, falls Detail benötigt.';

        $body = $this->extractor->capOutput($result['text'], $continuationHint);

        return new CallToolResult([new TextContent($metadata . "\n\n" . $body)]);
    }

    /**
     * @param array{kind: string, mime: string, size: int, file: File} $info
     */
    private function presentationOutline(string $head, array $info): CallToolResult
    {
        $outline = $this->extractor->getPresentationOutline($info['file']);
        $lines = [];
        $lines[] = $head . sprintf('File: %s', $info['file']->getName());
        $lines[] = sprintf('UID: sys_file:%d', $info['file']->getUid());
        $lines[] = sprintf('Gesamtslides: %d', count($outline['slides']));
        $lines[] = '';
        $lines[] = 'Slides:';
        foreach ($outline['slides'] as $slide) {
            $lines[] = sprintf('  %d. %s', $slide['index'], $slide['title']);
        }
        $lines[] = '';
        $lines[] = 'Erneut aufrufen mit range="X-Y", um die Inhalte zu lesen.';
        return new CallToolResult([new TextContent(implode("\n", $lines))]);
    }
}
