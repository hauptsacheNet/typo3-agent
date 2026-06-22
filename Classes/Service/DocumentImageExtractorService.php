<?php

declare(strict_types=1);

namespace Hn\Agent\Service;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Smalot\PdfParser\Parser as PdfParser;
use TYPO3\CMS\Core\Resource\File;

/**
 * Extracts the *images embedded inside* a document — the binary originals —
 * as the counterpart to DocumentExtractorService, which extracts text.
 *
 * OOXML (DOCX/PPTX/XLSX) and ODF (ODT/ODP/ODS) are ZIP containers, so the
 * embedded raster images sit as plain files under well-known media folders
 * (`word|ppt|xl/media/`, `Pictures/`). A direct ZIP scan is far more reliable
 * than walking the PhpOffice element trees. PDFs are handled best-effort via
 * smalot/pdfparser's image XObjects — streams that aren't standalone raster
 * images (FlateDecode pixel samples, EMF/WMF/SVG, …) are silently skipped.
 *
 * Ordering is stable (ZIP: entry name; PDF: object order) because the index is
 * the handle the user picks and ExtractedImageStore keys on.
 */
class DocumentImageExtractorService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

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
                    $bytes = $object->getContent();
                } catch (\Throwable) {
                    continue;
                }
                if (!is_string($bytes) || strlen($bytes) < self::MIN_IMAGE_BYTES) {
                    continue;
                }
                // Only keep streams that are standalone raster images (e.g.
                // DCTDecode/JPEG). FlateDecode pixel data is not and is skipped.
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
