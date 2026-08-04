<?php

declare(strict_types=1);

namespace Hn\Agent\Service;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Imaging\GraphicalFunctions;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Thin, mockable wrapper around TYPO3's GraphicalFunctions::imageMagickConvert()
 * used by ReadFileTool to downscale oversized-in-pixels images before they
 * are handed to the LLM. Keeps the ImageMagick/GraphicsMagick call in one
 * place so functional tests can inject a fake.
 *
 * ReadFileTool's PDF page rendering intentionally still calls
 * imageMagickConvert() inline — a shared abstraction is a separate refactor.
 */
class ImageScalingService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * Scale an image at $sourcePath so neither side exceeds $maxSide.
     * Returns null when the underlying pipeline fails (GraphicsMagick /
     * ImageMagick missing, unsupported source format, …).
     *
     * @return array{bytes: string, width: int, height: int, mime: string}|null
     */
    public function scaleToMaxSide(string $sourcePath, int $maxSide, string $outputFormat): ?array
    {
        try {
            $gfx = GeneralUtility::makeInstance(GraphicalFunctions::class);
            // 'm' modifier tells TYPO3 to treat width/height as *max* bounds
            // and keep the aspect ratio. Without it, values are exact targets
            // and the image gets squished to 2048×2048.
            $bound = $maxSide . 'm';
            $result = $gfx->imageMagickConvert(
                $sourcePath,
                $outputFormat,
                $bound,
                $bound,
                '-quality 80 -background white -flatten',
            );
            if (!is_array($result) || !isset($result[3]) || !is_string($result[3])) {
                return null;
            }
            $renderedPath = $result[3];
            if (!is_file($renderedPath)) {
                return null;
            }
            $bytes = file_get_contents($renderedPath);
            if ($bytes === false) {
                return null;
            }
            return [
                'bytes' => $bytes,
                'width' => (int)($result[0] ?? 0),
                'height' => (int)($result[1] ?? 0),
                'mime' => $outputFormat === 'png' ? 'image/png' : 'image/jpeg',
            ];
        } catch (\Throwable $e) {
            $this->logger?->warning('Image scaling failed', [
                'source' => $sourcePath,
                'maxSide' => $maxSide,
                'format' => $outputFormat,
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
