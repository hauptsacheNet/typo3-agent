<?php

declare(strict_types=1);

namespace Hn\Agent\Tests\Functional\Service;

use Hn\Agent\Service\DocumentImageExtractorService;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers PDF image extraction: the pure-PHP raw-raster → PNG reconstruction
 * (samplesToPng) for the common 8-bit gray/RGB cases, plus the optional poppler
 * `pdfimages` backend wiring (pdfImagesBinary / extractWithPdfImages).
 */
class DocumentImageExtractorServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces', 'frontend'];
    protected array $testExtensionsToLoad = ['mcp_server', 'agent'];

    private DocumentImageExtractorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DocumentImageExtractorService(
            GeneralUtility::makeInstance(ExtensionConfiguration::class),
        );
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

    // --- optional pdfimages backend -------------------------------------

    private function serviceWithConfig(array $config): DocumentImageExtractorService
    {
        $extConf = $this->createStub(ExtensionConfiguration::class);
        $extConf->method('get')->willReturn($config);
        return new DocumentImageExtractorService($extConf);
    }

    private function callExtractWithPdfImages(string $pdfPath, string $binary): ?array
    {
        $method = new \ReflectionMethod($this->service, 'extractWithPdfImages');
        $method->setAccessible(true);
        return $method->invoke($this->service, $pdfPath, $binary);
    }

    public function testPdfImagesBinaryResolvesConfiguredPath(): void
    {
        $service = $this->serviceWithConfig(['pdfImagesPath' => '  /usr/bin/pdfimages  ']);
        $method = new \ReflectionMethod($service, 'pdfImagesBinary');
        $method->setAccessible(true);

        self::assertSame('/usr/bin/pdfimages', $method->invoke($service));
    }

    public function testPdfImagesBinaryNullWhenUnset(): void
    {
        $service = $this->serviceWithConfig(['pdfImagesPath' => '']);
        $method = new \ReflectionMethod($service, 'pdfImagesBinary');
        $method->setAccessible(true);

        self::assertNull($method->invoke($service));
    }

    public function testExtractWithPdfImagesNonExecutableFallsBack(): void
    {
        // A missing/non-executable binary must return null so the caller can
        // fall back to the built-in extractor.
        self::assertNull($this->callExtractWithPdfImages('/dev/null', '/nonexistent/pdfimages'));
    }

    public function testExtractWithPdfImagesCollectsFiltersAndCleansUp(): void
    {
        $dir = sys_get_temp_dir() . '/agent_pdfimages_test_' . uniqid();
        mkdir($dir);
        try {
            // A valid PNG well over MIN_IMAGE_BYTES, and a tiny one that must be filtered.
            $valid = $dir . '/valid.png';
            imagepng($this->noiseImage(80, 80), $valid);
            self::assertGreaterThan(1024, filesize($valid));
            $tiny = $dir . '/tiny.png';
            imagepng(imagecreatetruecolor(2, 2), $tiny);
            self::assertLessThan(1024, filesize($tiny));

            // Fake "pdfimages": invoked as `<bin> -png <pdf> <prefix>`; $3 is the prefix.
            $script = $dir . '/fake-pdfimages';
            file_put_contents($script, "#!/bin/sh\ncp '$valid' \"\$3-000.png\"\ncp '$tiny' \"\$3-001.png\"\n");
            chmod($script, 0755);

            $transient = Environment::getVarPath() . '/transient';

            $result = $this->callExtractWithPdfImages('/dev/null', $script);

            self::assertIsArray($result);
            self::assertCount(1, $result, 'tiny image below MIN_IMAGE_BYTES must be filtered');
            self::assertSame('image/png', $result[0]['mime']);
            self::assertSame('pdf-image-1.png', $result[0]['sourceName']);
            self::assertSame(80, $result[0]['width']);
            self::assertSame(80, $result[0]['height']);

            // Temp output must be cleaned up.
            self::assertSame([], glob($transient . '/agent_pdfimages_*'));
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    }

    private function noiseImage(int $width, int $height): \GdImage
    {
        $im = imagecreatetruecolor($width, $height);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                imagesetpixel($im, $x, $y, imagecolorallocate($im, ($x * 7) % 256, ($y * 13) % 256, ($x * $y) % 256));
            }
        }
        return $im;
    }
}
