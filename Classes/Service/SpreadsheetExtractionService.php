<?php

declare(strict_types=1);

namespace Hn\Agent\Service;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Resource\File;

/**
 * Extracts a textual representation (and best-effort embedded images) from a
 * spreadsheet file (XLSX/XLS/ODS/CSV) so the LLM can read it — the LLM cannot
 * interpret raw spreadsheet bytes the way it does images/PDFs.
 *
 * This is the only place that reads spreadsheet bytes; AttachmentService stays
 * byte-free and only classifies. Used by ReadFileTool for the 'spreadsheet'
 * kind. Autowired via Configuration/Services.yaml.
 */
class SpreadsheetExtractionService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** Hard caps to keep the extracted text from blowing up the LLM context. */
    public const MAX_ROWS_PER_SHEET = 1000;
    public const MAX_COLS = 50;
    public const MAX_TOTAL_CHARS = 100_000;
    public const MAX_IMAGES = 10;

    private const EMBEDDABLE_IMAGE_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /**
     * Read the spreadsheet and return its text plus any embedded images.
     *
     * @return array{text: string, images: list<array{mime: string, data: string}>, truncated: bool}
     */
    public function extract(File $file): array
    {
        $images = [];
        $truncated = false;

        try {
            // Local copy on disk; PhpSpreadsheet readers operate on a path.
            $path = $file->getForLocalProcessing(false);

            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadEmptyCells(false);
            // Keep full read (not data-only) so drawings/images are available.
            $spreadsheet = $reader->load($path);
        } catch (\Throwable $e) {
            $this->logger?->warning('Spreadsheet could not be parsed', [
                'uid' => $file->getUid(),
                'exception' => $e->getMessage(),
            ]);
            return [
                'text' => 'Die Tabellendatei konnte nicht gelesen werden: ' . $e->getMessage(),
                'images' => [],
                'truncated' => false,
            ];
        }

        $out = '';
        $charBudgetHit = false;

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            if ($charBudgetHit) {
                $truncated = true;
                break;
            }

            $sheetHeader = sprintf("=== Sheet: %s ===\n", $sheet->getTitle());
            $out .= $sheetHeader;

            [$sheetText, $sheetTruncated, $budgetHit] = $this->renderSheet($sheet, strlen($out));
            $out .= $sheetText;
            $out .= "\n";
            $truncated = $truncated || $sheetTruncated;
            $charBudgetHit = $budgetHit;

            // Best-effort image extraction per sheet.
            if (count($images) < self::MAX_IMAGES) {
                foreach ($this->extractImages($sheet) as $image) {
                    $images[] = $image;
                    if (count($images) >= self::MAX_IMAGES) {
                        break;
                    }
                }
            }
        }

        return [
            'text' => rtrim($out) === '' ? '(Tabelle enthält keine lesbaren Zellinhalte.)' : rtrim($out),
            'images' => $images,
            'truncated' => $truncated,
        ];
    }

    /**
     * Render a single sheet as tab-separated rows, honouring row/column caps
     * and the global character budget.
     *
     * @return array{0: string, 1: bool, 2: bool} [text, truncated, charBudgetHit]
     */
    private function renderSheet(Worksheet $sheet, int $charsSoFar): array
    {
        $rows = $sheet->toArray(null, true, true, false);
        $totalRows = count($rows);
        $truncated = false;
        $text = '';
        $charsUsed = $charsSoFar;

        $rowsRendered = 0;
        foreach ($rows as $row) {
            if ($rowsRendered >= self::MAX_ROWS_PER_SHEET) {
                $truncated = true;
                $text .= sprintf("… (gekürzt: %d weitere Zeilen)\n", $totalRows - $rowsRendered);
                break;
            }

            $cells = array_slice(array_values($row), 0, self::MAX_COLS);
            if (count($row) > self::MAX_COLS) {
                $truncated = true;
            }
            $line = implode("\t", array_map(static fn ($c): string => (string)$c, $cells)) . "\n";

            if ($charsUsed + strlen($line) > self::MAX_TOTAL_CHARS) {
                $truncated = true;
                $text .= "… (gekürzt: Zeichenlimit erreicht)\n";
                return [$text, $truncated, true];
            }

            $text .= $line;
            $charsUsed += strlen($line);
            $rowsRendered++;
        }

        return [$text, $truncated, false];
    }

    /**
     * Best-effort extraction of embedded images. Failures on a single drawing
     * are swallowed so the text result is always delivered.
     *
     * @return list<array{mime: string, data: string}>
     */
    private function extractImages(Worksheet $sheet): array
    {
        $images = [];
        foreach ($sheet->getDrawingCollection() as $drawing) {
            try {
                if ($drawing instanceof MemoryDrawing) {
                    // In-memory GD image: render it to a binary string.
                    $resource = $drawing->getImageResource();
                    if ($resource === null) {
                        continue;
                    }
                    ob_start();
                    call_user_func($drawing->getRenderingFunction(), $resource);
                    $bytes = (string)ob_get_clean();
                    $mime = strtolower($drawing->getMimeType());
                } else {
                    // File-backed drawing: path may be a real file, a zip://
                    // stream (embedded in the xlsx) or a data: URI.
                    $drawingPath = $drawing->getPath();
                    if ($drawingPath === '') {
                        continue;
                    }
                    if (str_starts_with($drawingPath, 'data:image/')) {
                        $comma = strpos($drawingPath, ',');
                        $bytes = $comma === false ? '' : (string)base64_decode(substr($drawingPath, $comma + 1), true);
                    } else {
                        $bytes = (string)@file_get_contents($drawingPath);
                    }
                    // Worksheet\Drawing exposes the type via the file extension,
                    // not a getMimeType() accessor.
                    $extension = strtolower($drawing->getExtension());
                    $mime = 'image/' . ($extension === 'jpg' ? 'jpeg' : $extension);
                }

                if ($bytes === '' || !in_array($mime, self::EMBEDDABLE_IMAGE_MIMES, true)) {
                    continue;
                }
                if (strlen($bytes) > AttachmentService::MAX_IMAGE_BYTES) {
                    continue;
                }

                $images[] = ['mime' => $mime, 'data' => base64_encode($bytes)];
            } catch (\Throwable $e) {
                $this->logger?->info('Embedded spreadsheet image skipped', [
                    'exception' => $e->getMessage(),
                ]);
            }
        }
        return $images;
    }
}
