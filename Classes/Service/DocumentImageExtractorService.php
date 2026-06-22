<?php

declare(strict_types=1);

namespace Hn\Agent\Service;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Smalot\PdfParser\Parser as PdfParser;
use Smalot\PdfParser\PDFObject;
use Symfony\Component\Process\Process;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Extracts the *images embedded inside* a document — the binary originals —
 * as the counterpart to DocumentExtractorService, which extracts text.
 *
 * OOXML (DOCX/PPTX/XLSX) and ODF (ODT/ODP/ODS) are ZIP containers, so the
 * embedded raster images sit as plain files under well-known media folders
 * (`word|ppt|xl/media/`, `Pictures/`). A direct ZIP scan is far more reliable
 * than walking the PhpOffice element trees. PDFs are handled best-effort via
 * smalot/pdfparser's image XObjects: standalone JPEGs are used as-is and 8-bit
 * gray/RGB raster is reconstructed into PNGs; layouts we can't rebuild (CMYK,
 * indexed, sub-byte depths, EMF/WMF/SVG, …) are skipped. When the optional
 * `pdfImagesPath` extension setting points at poppler's `pdfimages` binary, it
 * is used for PDFs instead (full colorspace coverage), with automatic fallback
 * to the built-in path on any failure.
 *
 * Ordering is stable (ZIP: entry name; PDF: object order) because the index is
 * the handle the user picks and ExtractedImageStore keys on.
 */
class DocumentImageExtractorService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {
    }

    /** Hard cap on the number of images returned from one document. */
    public const MAX_IMAGES = 50;

    /** Skip tiny artefacts (spacer pixels, bullets, list markers, …). */
    private const MIN_IMAGE_BYTES = 1024;

    private const SUPPORTED_MIME_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /**
     * @return list<array{bytes: string, mime: string, sourceName: string, width: ?int, height: ?int}>
     */
    public function extractImages(File $file): array
    {
        $mime = strtolower((string)$file->getMimeType());
        if ($mime === 'application/pdf') {
            return $this->extractFromPdf($file);
        }
        return $this->extractFromZip($file);
    }

    /**
     * @return list<array{bytes: string, mime: string, sourceName: string, width: ?int, height: ?int}>
     */
    private function extractFromZip(File $file): array
    {
        $path = $file->getForLocalProcessing(false);
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new DocumentExtractionException(
                'Dokument konnte nicht als ZIP geöffnet werden — kein unterstütztes Office/ODF-Format?',
            );
        }
        try {
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false) {
                    continue;
                }
                // OOXML media folders + ODF Pictures.
                if (!preg_match('#(^|/)(word|ppt|xl)/media/#', $name)
                    && !preg_match('#(^|/)Pictures/#', $name)) {
                    continue;
                }
                $entries[] = $name;
            }
            sort($entries, SORT_STRING); // deterministic, stable index ordering

            $images = [];
            foreach ($entries as $name) {
                if (count($images) >= self::MAX_IMAGES) {
                    break;
                }
                $bytes = $zip->getFromName($name);
                if ($bytes === false || strlen($bytes) < self::MIN_IMAGE_BYTES) {
                    continue;
                }
                $detected = $this->detectImage($bytes);
                if ($detected === null) {
                    continue; // EMF/WMF/SVG/… — not a supported raster image
                }
                $images[] = [
                    'bytes' => $bytes,
                    'mime' => $detected['mime'],
                    'sourceName' => basename($name),
                    'width' => $detected['width'],
                    'height' => $detected['height'],
                ];
            }
            return $images;
        } finally {
            $zip->close();
        }
    }

    /**
     * @return list<array{bytes: string, mime: string, sourceName: string, width: ?int, height: ?int}>
     */
    private function extractFromPdf(File $file): array
    {
        // Prefer poppler's pdfimages when an admin has configured it: it covers
        // colorspaces the built-in extractor skips (CMYK, indexed, …). Any
        // failure returns null and we fall through to the built-in path below.
        $binary = $this->pdfImagesBinary();
        if ($binary !== null) {
            $result = $this->extractWithPdfImages($file->getForLocalProcessing(false), $binary);
            if ($result !== null) {
                return $result;
            }
        }

        try {
            $parser = new PdfParser();
            $document = $parser->parseContent($file->getContents());
        } catch (\Throwable $e) {
            throw new DocumentExtractionException(
                sprintf('PDF konnte nicht gelesen werden: %s', $e->getMessage()),
                0,
                $e,
            );
        }

        $images = [];
        try {
            $objects = $document->getObjectsByType('XObject', 'Image');
            $idx = 0;
            foreach ($objects as $object) {
                if (count($images) >= self::MAX_IMAGES) {
                    break;
                }
                try {
                    $content = $object->getContent();
                } catch (\Throwable) {
                    continue;
                }
                if (!is_string($content) || $content === '') {
                    continue;
                }

                $detected = $this->detectImage($content);
                if ($detected !== null) {
                    // The stream is already a standalone image file, e.g. a
                    // DCTDecode (JPEG) or JPXDecode image — use it as-is.
                    if (strlen($content) < self::MIN_IMAGE_BYTES) {
                        continue;
                    }
                    $bytes = $content;
                    $mime = $detected['mime'];
                    $width = $detected['width'];
                    $height = $detected['height'];
                } else {
                    // The stream is raw raster samples (the common FlateDecode
                    // case): reconstruct a real PNG from them. Returns null for
                    // layouts we can't safely rebuild (CMYK, indexed, sub-byte
                    // depths, unexpected predictors).
                    $png = $this->rasterSamplesToPng($object, $content);
                    if ($png === null) {
                        continue;
                    }
                    $det = $this->detectImage($png);
                    if ($det === null || strlen($png) < self::MIN_IMAGE_BYTES) {
                        continue;
                    }
                    $bytes = $png;
                    $mime = 'image/png';
                    $width = $det['width'];
                    $height = $det['height'];
                }

                $idx++;
                $images[] = [
                    'bytes' => $bytes,
                    'mime' => $mime,
                    'sourceName' => sprintf('pdf-image-%d.%s', $idx, $this->extensionFor($mime)),
                    'width' => $width,
                    'height' => $height,
                ];
            }
        } catch (\Throwable $e) {
            // Best-effort: a parser hiccup yields "no images", not a hard error.
            $this->logger?->warning('PDF image extraction failed', [
                'file' => $file->getCombinedIdentifier(),
                'exception' => $e->getMessage(),
            ]);
        }
        return $images;
    }

    /**
     * Resolve the configured poppler `pdfimages` binary path, or null when the
     * `pdfImagesPath` extension setting is empty/unreadable (use built-in path).
     */
    private function pdfImagesBinary(): ?string
    {
        try {
            $config = $this->extensionConfiguration->get('agent');
        } catch (\Throwable) {
            return null;
        }
        $path = is_array($config) ? trim((string)($config['pdfImagesPath'] ?? '')) : '';
        return $path !== '' ? $path : null;
    }

    /**
     * Extract embedded images via poppler's `pdfimages -png`, which renders
     * every image (JPEG, CMYK, indexed, raw raster, …) to a PNG. Returns null
     * on any failure so the caller can fall back to the built-in extractor.
     *
     * @return list<array{bytes: string, mime: string, sourceName: string, width: ?int, height: ?int}>|null
     */
    private function extractWithPdfImages(string $pdfPath, string $binary): ?array
    {
        if (!is_executable($binary)) {
            $this->logger?->warning('pdfimages binary is not executable, falling back', ['binary' => $binary]);
            return null;
        }

        // pdfimages writes <prefix>-NNN.png; tempnam() only gives us a unique
        // name, so drop the empty file it creates and reuse the name as prefix.
        $prefix = GeneralUtility::tempnam('agent_pdfimages_');
        @unlink($prefix);

        try {
            $process = new Process([$binary, '-png', $pdfPath, $prefix]);
            $process->setTimeout(60.0);
            $process->run();
            if (!$process->isSuccessful()) {
                $this->logger?->warning('pdfimages failed, falling back', [
                    'exitCode' => $process->getExitCode(),
                    'stderr' => $process->getErrorOutput(),
                ]);
                return null;
            }

            $files = glob($prefix . '-*.png') ?: [];
            sort($files, SORT_STRING); // stable, page/position order

            $images = [];
            $idx = 0;
            foreach ($files as $file) {
                if (count($images) >= self::MAX_IMAGES) {
                    break;
                }
                $bytes = @file_get_contents($file);
                if ($bytes === false || strlen($bytes) < self::MIN_IMAGE_BYTES) {
                    continue;
                }
                $detected = $this->detectImage($bytes);
                if ($detected === null) {
                    continue;
                }
                $idx++;
                $images[] = [
                    'bytes' => $bytes,
                    'mime' => $detected['mime'],
                    'sourceName' => sprintf('pdf-image-%d.%s', $idx, $this->extensionFor($detected['mime'])),
                    'width' => $detected['width'],
                    'height' => $detected['height'],
                ];
            }
            return $images;
        } catch (\Throwable $e) {
            $this->logger?->warning('pdfimages extraction error, falling back', ['exception' => $e->getMessage()]);
            return null;
        } finally {
            foreach (glob($prefix . '-*.png') ?: [] as $file) {
                @unlink($file);
            }
            if (is_file($prefix)) {
                @unlink($prefix);
            }
        }
    }

    /**
     * Reconstruct a PNG from a PDF image XObject whose stream is raw raster
     * samples (Flate-decoded but not yet de-predicted). Supports 8-bit
     * DeviceGray and DeviceRGB — the common images that aren't stored as JPEG.
     *
     * PDF's PNG predictors are byte-for-byte identical to PNG's own scanline
     * filtering, so the decoded scanlines can simply be re-wrapped as a PNG
     * IDAT rather than decoding pixels by hand. Returns null when the layout
     * can't be rebuilt safely (CMYK, indexed, sub-byte depths, …).
     */
    private function rasterSamplesToPng(PDFObject $object, string $content): ?string
    {
        $details = $object->getDetails(false);
        $width = (int)($details['Width'] ?? 0);
        $height = (int)($details['Height'] ?? 0);
        $bitsPerComponent = (int)($details['BitsPerComponent'] ?? 0);
        if ($width <= 0 || $height <= 0 || $bitsPerComponent !== 8) {
            return null;
        }
        return $this->samplesToPng($width, $height, $content);
    }

    /**
     * Build an 8-bit PNG from raw gray/RGB samples, detecting the layout from
     * the byte length (with or without the per-row PNG predictor filter byte).
     *
     * @return string|null PNG bytes, or null when $content is not an 8-bit
     *   grayscale or RGB raster of exactly $width x $height.
     */
    private function samplesToPng(int $width, int $height, string $content): ?string
    {
        $length = strlen($content);
        // component count => PNG color type (0 = grayscale, 2 = truecolor RGB)
        foreach ([1 => 0, 3 => 2] as $components => $colorType) {
            $rowBytes = $width * $components;
            if ($length === ($rowBytes + 1) * $height) {
                // Each scanline already carries its PNG predictor filter byte.
                return $this->assemblePng($width, $height, $colorType, $content);
            }
            if ($length === $rowBytes * $height) {
                // No predictor: prepend a "None" (0) filter byte to each row.
                $scanlines = '';
                for ($row = 0; $row < $height; $row++) {
                    $scanlines .= "\x00" . substr($content, $row * $rowBytes, $rowBytes);
                }
                return $this->assemblePng($width, $height, $colorType, $scanlines);
            }
        }
        return null;
    }

    /**
     * Assemble a minimal 8-bit PNG. $scanlines must be the filter-byte-prefixed
     * rows; they are zlib-compressed into the IDAT chunk (PNG's required format).
     */
    private function assemblePng(int $width, int $height, int $colorType, string $scanlines): string
    {
        $ihdr = pack('N', $width) . pack('N', $height) . chr(8) . chr($colorType) . "\x00\x00\x00";
        return "\x89PNG\r\n\x1a\n"
            . $this->pngChunk('IHDR', $ihdr)
            . $this->pngChunk('IDAT', gzcompress($scanlines, 6))
            . $this->pngChunk('IEND', '');
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    /**
     * @return array{mime: string, width: ?int, height: ?int}|null
     */
    private function detectImage(string $bytes): ?array
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return null;
        }
        $mime = strtolower((string)($info['mime'] ?? ''));
        if (!in_array($mime, self::SUPPORTED_MIME_TYPES, true)) {
            return null;
        }
        return [
            'mime' => $mime,
            'width' => isset($info[0]) ? (int)$info[0] : null,
            'height' => isset($info[1]) ? (int)$info[1] : null,
        ];
    }

    private function extensionFor(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'img',
        };
    }
}
