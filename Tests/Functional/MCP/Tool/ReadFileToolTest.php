<?php

declare(strict_types=1);

namespace Hn\Agent\Tests\Functional\MCP\Tool;

use Hn\Agent\MCP\Tool\ReadFileTool;
use Hn\Agent\Service\AttachmentService;
use Hn\Agent\Service\DocumentExtractorService;
use Hn\Agent\Service\ImageScalingService;
use Mcp\Types\ImageContent;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class ReadFileToolTest extends FunctionalTestCase
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

    // -----------------------------------------------------------------
    // Images (former ViewImage behavior)
    // -----------------------------------------------------------------

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

    public function testImageWithOutlineFormatReturnsMetadataWithoutBytes(): void
    {
        // format=outline replaces the former GetFileInfo: metadata only,
        // getContents() must never be called (content=null asserts that).
        $tool = $this->buildTool(102, 'image/png', 2048, 'pixel.png', '1:/uploads/pixel.png', null, 1, 1, $this->createUncalledScaler());

        $result = $tool->execute(['uid' => 102, 'format' => 'outline']);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        self::assertCount(1, $result->content);
        $text = $result->content[0];
        self::assertInstanceOf(TextContent::class, $text);
        self::assertStringContainsString('pixel.png', $text->text);
        self::assertStringContainsString('sys_file:102', $text->text);
    }

    public function testScalesImageWhenWidthExceedsMaxSide(): void
    {
        $scaledBytes = str_repeat("\x00", 1024);
        $scaler = $this->getMockBuilder(ImageScalingService::class)->disableOriginalConstructor()->getMock();
        $scaler->expects(self::once())
            ->method('scaleToMaxSide')
            ->with('/tmp/big.png', ReadFileTool::MAX_IMAGE_SIDE, 'png')
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
            ->with('/tmp/tall.jpg', ReadFileTool::MAX_IMAGE_SIDE, 'jpg')
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
            ->with('/tmp/anim.gif', ReadFileTool::MAX_IMAGE_SIDE, 'jpg')
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

    // -----------------------------------------------------------------
    // Documents (former ReadDocument behavior — text/plain is enough to
    // exercise the dispatch without office fixtures)
    // -----------------------------------------------------------------

    public function testReadsPlainTextDocument(): void
    {
        $content = "Hallo Welt.\nZweite Zeile.";
        $tool = $this->buildTool(601, 'text/plain', strlen($content), 'notes.txt', '1:/uploads/notes.txt', $content, 0, 0, $this->createUncalledScaler());

        $result = $tool->execute(['uid' => 601]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        self::assertCount(1, $result->content);
        $text = $result->content[0];
        self::assertInstanceOf(TextContent::class, $text);
        self::assertStringContainsString('notes.txt', $text->text);
        self::assertStringContainsString('Gesamtzeichen', $text->text);
        self::assertStringContainsString('Hallo Welt.', $text->text);
        self::assertStringContainsString('Zweite Zeile.', $text->text);
    }

    public function testDocumentCharOffsetWindowsThroughContent(): void
    {
        $content = 'ABCDEF';
        $tool = $this->buildTool(602, 'text/plain', strlen($content), 'window.txt', '1:/uploads/window.txt', $content, 0, 0, $this->createUncalledScaler());

        $result = $tool->execute(['uid' => 602, 'char_offset' => 3]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $text = $result->content[0]->text;
        self::assertStringContainsString('ab Offset 3', $text);
        self::assertStringContainsString('DEF', $text);
    }

    public function testDocumentOutlineReportsLengthWithoutBody(): void
    {
        $content = 'Zwölf Zeichen';
        $tool = $this->buildTool(603, 'text/plain', strlen($content), 'meta.txt', '1:/uploads/meta.txt', $content, 0, 0, $this->createUncalledScaler());

        $result = $tool->execute(['uid' => 603, 'format' => 'outline']);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $text = $result->content[0]->text;
        self::assertStringContainsString('Gesamtzeichen', $text);
        self::assertStringContainsString('text/plain', $text);
        self::assertStringNotContainsString('Zwölf Zeichen', $text);
    }

    // -----------------------------------------------------------------
    // Unsupported MIME → metadata only, no error
    // -----------------------------------------------------------------

    public function testReturnsMetadataForUnsupportedMime(): void
    {
        // application/zip has no content handler — ReadFile degrades to
        // metadata (former GetFileInfo) instead of erroring, so the LLM
        // never has to pick a second tool. content=null asserts
        // getContents() is never called.
        $tool = $this->buildTool(404, 'application/zip', 100, 'archive.zip', '1:/uploads/archive.zip', null, 0, 0, $this->createUncalledScaler());

        $result = $tool->execute(['uid' => 404]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        self::assertCount(1, $result->content);
        self::assertStringContainsString('application/zip', $result->content[0]->text);
        self::assertStringContainsString('archive.zip', $result->content[0]->text);
        self::assertStringContainsString('nur Metadaten', $result->content[0]->text);
    }

    // -----------------------------------------------------------------
    // Errors
    // -----------------------------------------------------------------

    public function testReturnsErrorWhenOversize(): void
    {
        // 6 MiB > 5 MiB image cap. getContents must never be called.
        $tool = $this->buildTool(303, 'image/png', 6 * 1024 * 1024, 'huge.png', '1:/uploads/huge.png', null, 0, 0, $this->createUncalledScaler());

        $result = $tool->execute(['uid' => 303]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('sys_file:303', $result->content[0]->text);
        self::assertStringContainsString('zu groß', $result->content[0]->text);
        self::assertStringContainsString('6.0 MiB', $result->content[0]->text);
    }

    public function testReturnsErrorForImageFormatOnNonRenderableMime(): void
    {
        $tool = $this->buildTool(505, 'text/plain', 100, 'notes.txt', '1:/uploads/notes.txt', null, 0, 0, $this->createUncalledScaler());

        $result = $tool->execute(['uid' => 505, 'format' => 'image']);

        self::assertTrue($result->isError);
        self::assertStringContainsString('format="image"', $result->content[0]->text);
        self::assertStringContainsString('text/plain', $result->content[0]->text);
    }

    public function testReturnsErrorForUnknownFormat(): void
    {
        // Format is validated before the file is even resolved.
        $tool = $this->buildDefaultTool();

        $result = $tool->execute(['uid' => 506, 'format' => 'video']);

        self::assertTrue($result->isError);
        self::assertStringContainsString('format', $result->content[0]->text);
    }

    public function testReturnsErrorWhenUidNotFound(): void
    {
        $tool = $this->buildDefaultTool();

        $result = $tool->execute(['uid' => 999999]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('999999', $result->content[0]->text);
        self::assertStringContainsString('could not be resolved', $result->content[0]->text);
    }

    public function testReturnsErrorWhenUidIsZero(): void
    {
        $tool = $this->buildDefaultTool();

        $result = $tool->execute(['uid' => 0]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('uid', $result->content[0]->text);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function createUncalledScaler(): ImageScalingService
    {
        $scaler = $this->getMockBuilder(ImageScalingService::class)->disableOriginalConstructor()->getMock();
        $scaler->expects(self::never())->method('scaleToMaxSide');
        return $scaler;
    }

    private function buildDefaultTool(): ReadFileTool
    {
        return new ReadFileTool(
            new AttachmentService(
                GeneralUtility::makeInstance(ResourceFactory::class),
                GeneralUtility::makeInstance(ConnectionPool::class),
            ),
            new DocumentExtractorService(),
            new ImageScalingService(),
        );
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
    ): ReadFileTool {
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

        return new ReadFileTool(
            new AttachmentService($factory, GeneralUtility::makeInstance(ConnectionPool::class)),
            new DocumentExtractorService(),
            $scaler,
        );
    }
}
