<?php

declare(strict_types=1);

namespace Hn\Agent\Tests\Functional\Service;

use Hn\Agent\Service\DocumentImageExtractorService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers the PDF raw-raster → PNG reconstruction. PDF images are frequently
 * stored as Flate-compressed pixel samples (not embedded JPEG/PNG files);
 * samplesToPng() rebuilds a real PNG from those samples for the common 8-bit
 * gray/RGB cases.
 */
class DocumentImageExtractorServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces', 'frontend'];
    protected array $testExtensionsToLoad = ['mcp_server', 'agent'];

    private DocumentImageExtractorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = GeneralUtility::makeInstance(DocumentImageExtractorService::class);
    }

    private function samplesToPng(int $width, int $height, string $content): ?string
    {
        $method = new \ReflectionMethod($this->service, 'samplesToPng');
        $method->setAccessible(true);
        return $method->invoke($this->service, $width, $height, $content);
    }

    /** @return list<array{int,int,int}> RGB triples in row-major order */
    private function decodePixels(string $png, int $width, int $height): array
    {
        $im = imagecreatefromstring($png);
        self::assertNotFalse($im, 'reconstructed PNG must be decodable');
        self::assertSame($width, imagesx($im));
        self::assertSame($height, imagesy($im));
        $pixels = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                // imagecolorsforindex reads components portably whether GD loaded
                // the PNG as truecolor (RGB) or as a palette (grayscale).
                $c = imagecolorsforindex($im, imagecolorat($im, $x, $y));
                $pixels[] = [$c['red'], $c['green'], $c['blue']];
            }
        }
        imagedestroy($im);
        return $pixels;
    }

    public function testReconstructsRgbWithoutPredictor(): void
    {
        // 2x2 RGB, raw samples, no per-row predictor byte (length = w*3*h).
        $expected = [[255, 0, 0], [0, 255, 0], [0, 0, 255], [255, 255, 0]];
        $content = '';
        foreach ($expected as $px) {
            $content .= chr($px[0]) . chr($px[1]) . chr($px[2]);
        }

        $png = $this->samplesToPng(2, 2, $content);

        self::assertNotNull($png);
        self::assertSame($expected, $this->decodePixels($png, 2, 2));
    }

    public function testReconstructsRgbWithPngPredictorBytes(): void
    {
        // Same image, but each scanline is prefixed with a "None" (0) filter
        // byte — exactly how a PDF PNG-predictor stream arrives (length = (w*3+1)*h).
        $expected = [[10, 20, 30], [40, 50, 60], [70, 80, 90], [100, 110, 120]];
        $content = '';
        for ($row = 0; $row < 2; $row++) {
            $content .= "\x00";
            for ($col = 0; $col < 2; $col++) {
                $px = $expected[$row * 2 + $col];
                $content .= chr($px[0]) . chr($px[1]) . chr($px[2]);
            }
        }
        self::assertSame((2 * 3 + 1) * 2, strlen($content));

        $png = $this->samplesToPng(2, 2, $content);

        self::assertNotNull($png);
        self::assertSame($expected, $this->decodePixels($png, 2, 2));
    }

    public function testReconstructsGrayscale(): void
    {
        // 2x2 grayscale, raw samples (length = w*1*h).
        $gray = [0, 85, 170, 255];
        $content = '';
        foreach ($gray as $v) {
            $content .= chr($v);
        }

        $png = $this->samplesToPng(2, 2, $content);

        self::assertNotNull($png);
        $pixels = $this->decodePixels($png, 2, 2);
        foreach ($gray as $i => $v) {
            self::assertSame([$v, $v, $v], $pixels[$i]);
        }
    }

    public function testReturnsNullForUnsupportedLayout(): void
    {
        // 2x2 with 4 components (e.g. CMYK) — length matches neither gray nor RGB.
        $content = str_repeat("\x7f", 2 * 4 * 2);

        self::assertNull($this->samplesToPng(2, 2, $content));
    }
}
