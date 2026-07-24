<?php

declare(strict_types=1);

namespace Hn\Agent\Tests\Functional\MCP\Tool;

use Hn\Agent\MCP\Tool\ViewImageTool;
use Hn\Agent\Service\AttachmentService;
use Hn\Agent\Service\ImageScalingService;
use Mcp\Types\ImageContent;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class ViewImageToolTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
        'agent',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');
    }

    public function testReadsImageAndReturnsBase64(): void
    {
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=');
        $scaler = $this->createUncalledScaler();
        $tool = $this->buildTool(101, 'image/png', strlen($pngBytes), 'pixel.png', '1:/uploads/pixel.png', $pngBytes, 1, 1, $scaler);

        $result = $tool->execute(['uid' => 101]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        self::assertCount(2, $result->content);

        $text = $result->content[0];
        self::assertInstanceOf(TextContent::class, $text);
        self::assertStringContainsString('pixel.png', $text->text);
        self::assertStringContainsString('image/png', $text->text);

        $image = $result->content[1];
        self::assertInstanceOf(ImageContent::class, $image);
        self::assertSame('image/png', $image->mimeType);
        self::assertSame(base64_encode($pngBytes), $image->data);
    }

    public function testReturnsErrorForPdf(): void
    {
        // Non-image MIME → isError pointing the LLM to the right viewer tool
        // (ReadPdfText / ViewPdfPage for PDF). content=null asserts
        // getContents() is never called.
        $tool = $this->buildTool(202, 'application/pdf', 4096, 'doc.pdf', '1:/uploads/doc.pdf', null, 0, 0, $this->createUncalledScaler());

        $result = $tool->execute(['uid' => 202]);

        self::assertTrue($result->isError);
        self::assertCount(1, $result->content);
        $text = $result->content[0];
        self::assertInstanceOf(TextContent::class, $text);
        self::assertStringContainsString('application/pdf', $text->text);
        self::assertStringContainsString('ReadPdfText', $text->text);
        self::assertStringContainsString('ViewPdfPage', $text->text);
    }

    public function testReturnsErrorForUnsupportedMime(): void
    {
        // application/zip is not on any viewer-tool allowlist — the hint
        // falls through to GetFileInfo.
        $tool = $this->buildTool(404, 'application/zip', 100, 'archive.zip', '1:/uploads/archive.zip', null, 0, 0, $this->createUncalledScaler());

        $result = $tool->execute(['uid' => 404]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('application/zip', $result->content[0]->text);
        self::assertStringContainsString('GetFileInfo', $result->content[0]->text);
    }

    public function testReturnsErrorWhenOversize(): void
    {
        // 6 MiB > 5 MiB image cap. getContents must never be called.
        $tool = $this->buildTool(303, 'image/png', 6 * 1024 * 1024, 'huge.png', '1:/uploads/huge.png', null, 0, 0, $this->createUncalledScaler());

        $result = $tool->execute(['uid' => 303]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('sys_file:303', $result->content[0]->text);
        self::assertStringContainsString('image inspection is capped', $result->content[0]->text);
        self::assertStringContainsString('6.0 MiB', $result->content[0]->text);
    }

    public function testReturnsErrorWhenUidNotFound(): void
    {
        $tool = new ViewImageTool(
            new AttachmentService(
                GeneralUtility::makeInstance(ResourceFactory::class),
                GeneralUtility::makeInstance(ConnectionPool::class),
            ),
            new ImageScalingService(),
        );

        $result = $tool->execute(['uid' => 999999]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('999999', $result->content[0]->text);
        self::assertStringContainsString('could not be resolved', $result->content[0]->text);
    }

    public function testReturnsErrorWhenUidIsZero(): void
    {
        $tool = new ViewImageTool(
            new AttachmentService(
                GeneralUtility::makeInstance(ResourceFactory::class),
                GeneralUtility::makeInstance(ConnectionPool::class),
            ),
            new ImageScalingService(),
        );

        $result = $tool->execute(['uid' => 0]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('uid', $result->content[0]->text);
    }

    public function testScalesImageWhenWidthExceedsMaxSide(): void
    {
        $scaledBytes = str_repeat("\x00", 1024);
        $scaler = $this->getMockBuilder(ImageScalingService::class)->disableOriginalConstructor()->getMock();
        $scaler->expects(self::once())
            ->method('scaleToMaxSide')
            ->with('/tmp/big.png', ViewImageTool::MAX_IMAGE_SIDE, 'png')
            ->willReturn(['bytes' => $scaledBytes, 'width' => 2048, 'height' => 1536, 'mime' => 'image/png']);

        // getContents must never be called on the scaling path.
        $tool = $this->buildTool(501, 'image/png', 3 * 1024 * 1024, 'big.png', '1:/uploads/big.png', null, 4000, 3000, $scaler, '/tmp/big.png');

        $result = $tool->execute(['uid' => 501]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        self::assertCount(2, $result->content);

        $text = $result->content[0];
        self::assertInstanceOf(TextContent::class, $text);
        self::assertStringContainsString('big.png', $text->text);
        self::assertStringContainsString('4000×3000', $text->text);
        self::assertStringContainsString('2048×1536', $text->text);

        $image = $result->content[1];
        self::assertInstanceOf(ImageContent::class, $image);
        self::assertSame('image/png', $image->mimeType);
        self::assertSame(base64_encode($scaledBytes), $image->data);
    }

    public function testScalesImageWhenHeightExceedsMaxSide(): void
    {
        $scaledBytes = str_repeat("\x01", 512);
        $scaler = $this->getMockBuilder(ImageScalingService::class)->disableOriginalConstructor()->getMock();
        $scaler->expects(self::once())
            ->method('scaleToMaxSide')
            ->with('/tmp/tall.jpg', ViewImageTool::MAX_IMAGE_SIDE, 'jpg')
            ->willReturn(['bytes' => $scaledBytes, 'width' => 1024, 'height' => 2048, 'mime' => 'image/jpeg']);

        $tool = $this->buildTool(502, 'image/jpeg', 1024 * 1024, 'tall.jpg', '1:/uploads/tall.jpg', null, 1500, 3000, $scaler, '/tmp/tall.jpg');

        $result = $tool->execute(['uid' => 502]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $image = $result->content[1];
        self::assertInstanceOf(ImageContent::class, $image);
        self::assertSame('image/jpeg', $image->mimeType);
        self::assertSame(base64_encode($scaledBytes), $image->data);
    }

    public function testDoesNotScaleWhenDimensionsWithinMaxSide(): void
    {
        $pngBytes = str_repeat("\x02", 64);
        $scaler = $this->createUncalledScaler();

        $tool = $this->buildTool(503, 'image/png', strlen($pngBytes), 'small.png', '1:/uploads/small.png', $pngBytes, 1000, 800, $scaler);

        $result = $tool->execute(['uid' => 503]);

        self::assertFalse($result->isError);
        $image = $result->content[1];
        self::assertInstanceOf(ImageContent::class, $image);
        self::assertSame('image/png', $image->mimeType);
        self::assertSame(base64_encode($pngBytes), $image->data);
    }

    public function testConvertsNonPngToJpegOnScale(): void
    {
        $scaledBytes = str_repeat("\x03", 256);
        $scaler = $this->getMockBuilder(ImageScalingService::class)->disableOriginalConstructor()->getMock();
        $scaler->expects(self::once())
            ->method('scaleToMaxSide')
            ->with('/tmp/anim.gif', ViewImageTool::MAX_IMAGE_SIDE, 'jpg')
            ->willReturn(['bytes' => $scaledBytes, 'width' => 2048, 'height' => 2048, 'mime' => 'image/jpeg']);

        $tool = $this->buildTool(504, 'image/gif', 2 * 1024 * 1024, 'anim.gif', '1:/uploads/anim.gif', null, 3000, 3000, $scaler, '/tmp/anim.gif');

        $result = $tool->execute(['uid' => 504]);

        self::assertFalse($result->isError);
        $image = $result->content[1];
        self::assertInstanceOf(ImageContent::class, $image);
        self::assertSame('image/jpeg', $image->mimeType);
        self::assertStringContainsString('image/gif', $result->content[0]->text);
        self::assertStringContainsString('image/jpeg', $result->content[0]->text);
    }

    public function testErrorsWhenScaledResultStillExceedsByteCap(): void
    {
        $bigBytes = str_repeat("\x00", 6 * 1024 * 1024);
        $scaler = $this->getMockBuilder(ImageScalingService::class)->disableOriginalConstructor()->getMock();
        $scaler->expects(self::once())
            ->method('scaleToMaxSide')
            ->willReturn(['bytes' => $bigBytes, 'width' => 2048, 'height' => 2048, 'mime' => 'image/png']);

        $tool = $this->buildTool(505, 'image/png', 3 * 1024 * 1024, 'dense.png', '1:/uploads/dense.png', null, 4000, 4000, $scaler, '/tmp/dense.png');

        $result = $tool->execute(['uid' => 505]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('Skaliertes Bild', $result->content[0]->text);
        self::assertStringContainsString('6.0 MiB', $result->content[0]->text);
    }

    public function testErrorsWhenScalingFails(): void
    {
        $scaler = $this->getMockBuilder(ImageScalingService::class)->disableOriginalConstructor()->getMock();
        $scaler->expects(self::once())->method('scaleToMaxSide')->willReturn(null);

        $tool = $this->buildTool(506, 'image/png', 3 * 1024 * 1024, 'broken.png', '1:/uploads/broken.png', null, 5000, 5000, $scaler, '/tmp/broken.png');

        $result = $tool->execute(['uid' => 506]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('GraphicsMagick', $result->content[0]->text);
    }

    private function createUncalledScaler(): ImageScalingService
    {
        $scaler = $this->getMockBuilder(ImageScalingService::class)->disableOriginalConstructor()->getMock();
        $scaler->expects(self::never())->method('scaleToMaxSide');
        return $scaler;
    }

    private function buildTool(
        int $uid,
        string $mime,
        int $size,
        string $name,
        string $identifier,
        ?string $content,
        int $width,
        int $height,
        ImageScalingService $scaler,
        ?string $localPath = null,
    ): ViewImageTool {
        $file = $this->getMockBuilder(File::class)->disableOriginalConstructor()->getMock();
        $file->method('getUid')->willReturn($uid);
        $file->method('getMimeType')->willReturn($mime);
        $file->method('getSize')->willReturn($size);
        $file->method('getName')->willReturn($name);
        $file->method('getCombinedIdentifier')->willReturn($identifier);
        $file->method('getProperty')->willReturnMap([
            ['width', $width],
            ['height', $height],
        ]);
        if ($localPath !== null) {
            $file->method('getForLocalProcessing')->willReturn($localPath);
        }
        if ($content === null) {
            $file->expects(self::never())->method('getContents');
        } else {
            $file->expects(self::atLeastOnce())->method('getContents')->willReturn($content);
        }

        $factory = $this->getMockBuilder(ResourceFactory::class)->disableOriginalConstructor()->getMock();
        $factory->expects(self::atLeastOnce())->method('getFileObject')->with($uid)->willReturn($file);

        return new ViewImageTool(
            new AttachmentService($factory, GeneralUtility::makeInstance(ConnectionPool::class)),
            $scaler,
        );
    }
}
