<?php

declare(strict_types=1);

namespace Hn\Agent\Service;

use PhpOffice\PhpPresentation\IOFactory as PresentationIOFactory;
use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\Shape\RichText;
use PhpOffice\PhpPresentation\Slide;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\Element\Text as WordText;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Smalot\PdfParser\Parser as PdfParser;
use TYPO3\CMS\Core\Resource\File;

/**
 * Extracts plain text from PDFs, spreadsheets, text documents and
 * presentations stored in FAL. Owns the output budget so tools just
 * call the extractor and emit the result.
 *
 * Output is capped at MAX_OUTPUT_CHARS (~12k tokens) — the LLM paginates
 * via format-specific range parameters on the calling tools.
 *
 * Each extraction wraps third-party parser exceptions in
 * DocumentExtractionException so tools can return them as `isError: true`.
 */
class DocumentExtractorService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const MAX_OUTPUT_CHARS = 50_000;

    // -----------------------------------------------------------------
    // PDF
    // -----------------------------------------------------------------

    public function getPdfPageCount(File $file): int
    {
        $document = $this->parsePdf($file);
        return count($document->getPages());
    }

    /**
     * @return array{text: string, pageCount: int, fromPage: int, toPage: int}
     */
    public function extractPdfPages(File $file, int $from, int $to): array
    {
        $document = $this->parsePdf($file);
        $pages = $document->getPages();
        $pageCount = count($pages);

        $from = max(1, min($from, max(1, $pageCount)));
        $to = max($from, min($to, $pageCount));

        $parts = [];
        for ($i = $from; $i <= $to; $i++) {
            $page = $pages[$i - 1] ?? null;
            if ($page === null) {
                break;
            }
            try {
                $text = (string)$page->getText();
            } catch (\Throwable $e) {
                $this->logger?->warning('pdfparser failed to extract page text', [
                    'page' => $i,
                    'file' => $file->getCombinedIdentifier(),
                    'exception' => $e->getMessage(),
                ]);
                $text = '[Seite ' . $i . ': Text konnte nicht extrahiert werden]';
            }
            $parts[] = sprintf("--- Seite %d ---\n\n%s", $i, trim($text));
        }

        return [
            'text' => implode("\n\n", $parts),
            'pageCount' => $pageCount,
            'fromPage' => $from,
            'toPage' => $to,
        ];
    }

    private function parsePdf(File $file): \Smalot\PdfParser\Document
    {
        try {
            $parser = new PdfParser();
            return $parser->parseContent($file->getContents());
        } catch (\Throwable $e) {
            throw new DocumentExtractionException(
                sprintf('PDF konnte nicht gelesen werden: %s', $e->getMessage()),
                0,
                $e,
            );
        }
    }

    // -----------------------------------------------------------------
    // Spreadsheet
    // -----------------------------------------------------------------

    /**
     * @return array{sheets: list<array{index: int, name: string, rows: int, cols: int}>, activeSheet: string}
     */
    public function getSpreadsheetOutline(File $file): array
    {
        $spreadsheet = $this->loadSpreadsheet($file);
        try {
            $sheets = [];
            foreach ($spreadsheet->getAllSheets() as $index => $sheet) {
                $sheets[] = [
                    'index' => $index,
                    'name' => $sheet->getTitle(),
                    'rows' => $sheet->getHighestDataRow(),
                    'cols' => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn()),
                ];
            }
            return [
                'sheets' => $sheets,
                'activeSheet' => $spreadsheet->getActiveSheet()->getTitle(),
            ];
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * @param string|int|null $sheet Sheet name or zero-based index; null = active sheet
     * @return array{text: string, sheetName: string, totalRows: int, totalCols: int, rangeUsed: string}
     */
    public function extractSpreadsheetRange(File $file, string|int|null $sheet, ?string $a1Range): array
    {
        $spreadsheet = $this->loadSpreadsheet($file);
        try {
            $worksheet = match (true) {
                $sheet === null => $spreadsheet->getActiveSheet(),
                is_int($sheet) => $spreadsheet->getSheet($sheet),
                default => $spreadsheet->getSheetByName($sheet) ?? throw new DocumentExtractionException(
                    sprintf('Sheet "%s" existiert nicht.', $sheet),
                ),
            };

            $maxRow = $worksheet->getHighestDataRow();
            $maxCol = $worksheet->getHighestDataColumn();
            $range = $a1Range ?? sprintf('A1:%s%d', $maxCol, $maxRow);
            $rows = $worksheet->rangeToArray($range, null, true, false, false);

            $lines = [];
            foreach ($rows as $row) {
                $lines[] = implode("\t", array_map(static fn($v) => $v === null ? '' : (string)$v, $row));
            }

            return [
                'text' => implode("\n", $lines),
                'sheetName' => $worksheet->getTitle(),
                'totalRows' => $maxRow,
                'totalCols' => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($maxCol),
                'rangeUsed' => $range,
            ];
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function loadSpreadsheet(File $file): Spreadsheet
    {
        $path = $this->localPath($file);
        try {
            $readerType = SpreadsheetIOFactory::identify($path);
            $reader = SpreadsheetIOFactory::createReader($readerType);
            if ($reader instanceof IReader) {
                $reader->setReadDataOnly(true);
            }
            return $reader->load($path);
        } catch (DocumentExtractionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new DocumentExtractionException(
                sprintf('Spreadsheet konnte nicht gelesen werden: %s', $e->getMessage()),
                0,
                $e,
            );
        }
    }

    // -----------------------------------------------------------------
    // Document (DOCX / ODT / RTF / TXT / MD / HTML)
    // -----------------------------------------------------------------

    /**
     * @return array{text: string, totalChars: int, returnedChars: int, charOffset: int}
     */
    public function extractDocumentText(File $file, int $charOffset, int $charLimit): array
    {
        $charOffset = max(0, $charOffset);
        $charLimit = max(1, min($charLimit, self::MAX_OUTPUT_CHARS));

        $fullText = $this->extractFullDocumentText($file);
        $totalChars = mb_strlen($fullText);
        if ($charOffset >= $totalChars) {
            return [
                'text' => '',
                'totalChars' => $totalChars,
                'returnedChars' => 0,
                'charOffset' => $charOffset,
            ];
        }
        $slice = mb_substr($fullText, $charOffset, $charLimit);
        return [
            'text' => $slice,
            'totalChars' => $totalChars,
            'returnedChars' => mb_strlen($slice),
            'charOffset' => $charOffset,
        ];
    }

    private function extractFullDocumentText(File $file): string
    {
        $mime = strtolower((string)$file->getMimeType());

        // Plain-text family: just decode bytes, strip HTML if needed.
        if (in_array($mime, ['text/plain', 'text/markdown', 'text/csv'], true)) {
            return $this->normalizeWhitespace($file->getContents());
        }
        if ($mime === 'text/html') {
            return $this->normalizeWhitespace(strip_tags($file->getContents()));
        }
        if (in_array($mime, ['application/rtf', 'text/rtf'], true)) {
            return $this->loadPhpWordText($file, 'RTF');
        }
        if ($mime === 'application/vnd.oasis.opendocument.text') {
            return $this->loadPhpWordText($file, 'ODText');
        }
        // Default: assume DOCX (also covers any future ml.document variants).
        return $this->loadPhpWordText($file, 'Word2007');
    }

    private function loadPhpWordText(File $file, string $readerName): string
    {
        $path = $this->localPath($file);
        try {
            $phpWord = WordIOFactory::load($path, $readerName);
        } catch (\Throwable $e) {
            throw new DocumentExtractionException(
                sprintf('Dokument konnte nicht gelesen werden: %s', $e->getMessage()),
                0,
                $e,
            );
        }
        $parts = [];
        foreach ($phpWord->getSections() as $section) {
            $this->collectPhpWordText($section, $parts);
        }
        return $this->normalizeWhitespace(implode("\n", $parts));
    }

    /**
     * @param list<string> $out
     */
    private function collectPhpWordText(AbstractElement $element, array &$out): void
    {
        if ($element instanceof WordText) {
            $out[] = (string)$element->getText();
            return;
        }
        if ($element instanceof TextBreak) {
            $out[] = "\n";
            return;
        }
        if ($element instanceof TextRun) {
            foreach ($element->getElements() as $child) {
                $this->collectPhpWordText($child, $out);
            }
            $out[] = "\n";
            return;
        }
        if ($element instanceof AbstractContainer) {
            foreach ($element->getElements() as $child) {
                $this->collectPhpWordText($child, $out);
            }
            return;
        }
        if (method_exists($element, 'getText')) {
            $text = $element->getText();
            if (is_string($text) && $text !== '') {
                $out[] = $text;
            }
        }
    }

    private function normalizeWhitespace(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // collapse runs of 3+ newlines into 2, trim trailing whitespace per line
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }

    // -----------------------------------------------------------------
    // Presentation
    // -----------------------------------------------------------------

    /**
     * @return array{slides: list<array{index: int, title: string}>}
     */
    public function getPresentationOutline(File $file): array
    {
        $presentation = $this->loadPresentation($file);
        $slides = [];
        foreach ($presentation->getAllSlides() as $i => $slide) {
            $slides[] = [
                'index' => $i + 1,
                'title' => $this->extractSlideTitle($slide) ?? sprintf('Slide %d', $i + 1),
            ];
        }
        return ['slides' => $slides];
    }

    /**
     * @return array{text: string, slideCount: int, fromSlide: int, toSlide: int}
     */
    public function extractPresentationSlides(File $file, int $from, int $to): array
    {
        $presentation = $this->loadPresentation($file);
        $slides = $presentation->getAllSlides();
        $count = count($slides);

        $from = max(1, min($from, max(1, $count)));
        $to = max($from, min($to, $count));

        $parts = [];
        for ($i = $from; $i <= $to; $i++) {
            $slide = $slides[$i - 1] ?? null;
            if ($slide === null) {
                break;
            }
            $title = $this->extractSlideTitle($slide) ?? '';
            $body = $this->extractSlideBody($slide);
            $header = $title !== '' ? sprintf('--- Slide %d: %s ---', $i, $title) : sprintf('--- Slide %d ---', $i);
            $parts[] = $header . "\n\n" . trim($body);
        }

        return [
            'text' => implode("\n\n", $parts),
            'slideCount' => $count,
            'fromSlide' => $from,
            'toSlide' => $to,
        ];
    }

    private function loadPresentation(File $file): PhpPresentation
    {
        $path = $this->localPath($file);
        try {
            $mime = strtolower((string)$file->getMimeType());
            $readerName = $mime === 'application/vnd.oasis.opendocument.presentation' ? 'ODPresentation' : 'PowerPoint2007';
            $reader = PresentationIOFactory::createReader($readerName);
            return $reader->load($path);
        } catch (\Throwable $e) {
            throw new DocumentExtractionException(
                sprintf('Präsentation konnte nicht gelesen werden: %s', $e->getMessage()),
                0,
                $e,
            );
        }
    }

    private function extractSlideTitle(Slide $slide): ?string
    {
        foreach ($slide->getShapeCollection() as $shape) {
            if (!$shape instanceof RichText) {
                continue;
            }
            // PhpPresentation marks the title shape via placeholder type "title" / "ctrTitle".
            $placeholder = $shape->getPlaceholder();
            if ($placeholder === null) {
                continue;
            }
            $type = strtolower((string)$placeholder->getType());
            if ($type !== 'title' && $type !== 'ctrtitle') {
                continue;
            }
            $text = trim($this->collectRichTextString($shape));
            if ($text !== '') {
                return $text;
            }
        }
        // Fallback: first non-empty text block on the slide
        foreach ($slide->getShapeCollection() as $shape) {
            if ($shape instanceof RichText) {
                $text = trim($this->collectRichTextString($shape));
                if ($text !== '') {
                    return mb_substr($text, 0, 80);
                }
            }
        }
        return null;
    }

    private function extractSlideBody(Slide $slide): string
    {
        $blocks = [];
        foreach ($slide->getShapeCollection() as $shape) {
            if (!$shape instanceof RichText) {
                continue;
            }
            $text = trim($this->collectRichTextString($shape));
            if ($text !== '') {
                $blocks[] = $text;
            }
        }
        // Notes
        $notes = $slide->getNote();
        if ($notes !== null) {
            foreach ($notes->getShapeCollection() as $shape) {
                if (!$shape instanceof RichText) {
                    continue;
                }
                $text = trim($this->collectRichTextString($shape));
                if ($text !== '') {
                    $blocks[] = "[Notizen]\n" . $text;
                }
            }
        }
        return implode("\n\n", $blocks);
    }

    private function collectRichTextString(RichText $shape): string
    {
        $lines = [];
        foreach ($shape->getParagraphs() as $paragraph) {
            $line = '';
            foreach ($paragraph->getRichTextElements() as $element) {
                if (method_exists($element, 'getText')) {
                    $line .= (string)$element->getText();
                }
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    // -----------------------------------------------------------------
    // Output budget + range parsing helpers
    // -----------------------------------------------------------------

    public function capOutput(string $text, string $continuationHint): string
    {
        if (mb_strlen($text) <= self::MAX_OUTPUT_CHARS) {
            return $text;
        }
        $slice = mb_substr($text, 0, self::MAX_OUTPUT_CHARS);
        // Cut at last newline to avoid mid-line truncation
        $lastNewline = mb_strrpos($slice, "\n");
        if ($lastNewline !== false && $lastNewline > self::MAX_OUTPUT_CHARS * 0.8) {
            $slice = mb_substr($slice, 0, $lastNewline);
        }
        return $slice
            . "\n\n---\n[Output gekürzt bei "
            . mb_strlen($slice) . " Zeichen (Original: " . mb_strlen($text) . " Zeichen). "
            . $continuationHint . "]";
    }

    /**
     * Parse a range like "3", "1-5", "7-", "all" against an upper bound.
     *
     * @return array{from: int, to: int}
     */
    public function parseRange(string $range, int $max): array
    {
        $range = trim($range);
        if ($range === '' || strcasecmp($range, 'all') === 0) {
            return ['from' => 1, 'to' => max(1, $max)];
        }
        if (!preg_match('/^(\d+)(?:-(\d*))?$/', $range, $m)) {
            throw new DocumentExtractionException(
                sprintf('Ungültiges Range-Format "%s". Erwarte z. B. "3", "1-5", "7-" oder "all".', $range),
            );
        }
        $from = max(1, (int)$m[1]);
        if (!isset($m[2]) || $m[2] === '') {
            // "7" -> single page; "7-" -> 7..end
            $to = str_contains($range, '-') ? $max : $from;
        } else {
            $to = (int)$m[2];
        }
        if ($to < $from) {
            throw new DocumentExtractionException(
                sprintf('Range "%s" ist ungültig: Ende (%d) liegt vor Anfang (%d).', $range, $to, $from),
            );
        }
        return ['from' => $from, 'to' => min($to, max(1, $max))];
    }

    private function localPath(File $file): string
    {
        return $file->getForLocalProcessing(false);
    }
}
