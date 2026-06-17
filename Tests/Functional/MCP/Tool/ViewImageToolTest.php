<?php

declare(strict_types=1);

namespace Hn\Agent\Tests\Functional\MCP\Tool;

use Hn\Agent\MCP\Tool\ViewImageTool;
use Hn\Agent\Service\AttachmentService;
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
        $tool = $this->buildTool(101, 'image/png', strlen($pngBytes), 'pixel.png', '1:/uploads/pixel.png', $pngBytes);

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
        $tool = $this->buildTool(202, 'application/pdf', 4096, 'doc.pdf', '1:/uploads/doc.pdf', null);

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
        $tool = $this->buildTool(404, 'application/zip', 100, 'archive.zip', '1:/uploads/archive.zip', null);

        $result = $tool->execute(['uid' => 404]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('application/zip', $result->content[0]->text);
        self::assertStringContainsString('GetFileInfo', $result->content[0]->text);
    }

    public function testReturnsErrorWhenOversize(): void
    {
        // 6 MiB > 5 MiB image cap. getContents must never be called.
        $tool = $this->buildTool(303, 'image/png', 6 * 1024 * 1024, 'huge.png', '1:/uploads/huge.png', null);

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
        );

        $result = $tool->execute(['uid' => 0]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('uid', $result->content[0]->text);
    }

    private function buildTool(int $uid, string $mime, int $size, string $name, string $identifier, ?string $content): ViewImageTool
    {
        $file = $this->getMockBuilder(File::class)->disableOriginalConstructor()->getMock();
        $file->method('getUid')->willReturn($uid);
        $file->method('getMimeType')->willReturn($mime);
        $file->method('getSize')->willReturn($size);
        $file->method('getName')->willReturn($name);
        $file->method('getCombinedIdentifier')->willReturn($identifier);
        if ($content === null) {
            $file->expects(self::never())->method('getContents');
        } else {
            $file->expects(self::atLeastOnce())->method('getContents')->willReturn($content);
        }

        $factory = $this->getMockBuilder(ResourceFactory::class)->disableOriginalConstructor()->getMock();
        $factory->expects(self::atLeastOnce())->method('getFileObject')->with($uid)->willReturn($file);

        return new ViewImageTool(
            new AttachmentService($factory, GeneralUtility::makeInstance(ConnectionPool::class)),
        );
    }
}
