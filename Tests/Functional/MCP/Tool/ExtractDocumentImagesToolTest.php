<?php

declare(strict_types=1);

namespace Hn\Agent\Tests\Functional\MCP\Tool;

use Hn\Agent\MCP\Tool\ExtractDocumentImagesTool;
use Hn\Agent\Service\AttachmentService;
use Hn\Agent\Service\DocumentImageExtractorService;
use Hn\Agent\Service\ExtractedImageStore;
use Mcp\Types\ImageContent;
use Mcp\Types\Role;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class ExtractDocumentImagesToolTest extends FunctionalTestCase
{
    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    protected array $coreExtensionsToLoad = ['workspaces', 'frontend'];
    protected array $testExtensionsToLoad = ['mcp_server', 'agent'];

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    public function testExtractsImagesAndKeepsThumbnailsUserOnly(): void
    {
        $zip = $this->makeOfficeZip(['word/media/image1.png' => $this->makePng()]);
        $tool = $this->buildTool(101, self::DOCX_MIME, $zip);

        $result = $tool->execute(['uid' => 101]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $text = $result->content[0];
        self::assertInstanceOf(TextContent::class, $text);
        self::assertStringContainsString('Gefundene Bilder: 1', $text->text);
        self::assertStringContainsString('#0', $text->text);
        self::assertStringContainsString('StoreImageInFileadmin', $text->text);

        // Every image must be flagged user-audience so the converter routes it
        // into the UI-only lane and the model never receives the thumbnails.
        $imageBlocks = array_filter($result->content, static fn($c) => $c instanceof ImageContent);
        self::assertNotEmpty($imageBlocks, 'expected at least one thumbnail');
        foreach ($imageBlocks as $image) {
            self::assertInstanceOf(ImageContent::class, $image);
            self::assertNotNull($image->annotations, 'thumbnail must carry annotations');
            self::assertContains(Role::USER, $image->annotations->audience ?? []);
            self::assertNotContains(Role::ASSISTANT, $image->annotations->audience ?? []);
        }
    }

    public function testReportsWhenNoImagesArePresent(): void
    {
        $zip = $this->makeOfficeZip([]); // only the Content_Types stub, no media
        $tool = $this->buildTool(102, self::DOCX_MIME, $zip);

        $result = $tool->execute(['uid' => 102]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        self::assertStringContainsString('keine extrahierbaren Bilder', $result->content[0]->text);
    }

    public function testRejectsStandaloneImageWithHint(): void
    {
        $tool = $this->buildTool(103, 'image/png', null, 1024);

        $result = $tool->execute(['uid' => 103]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('ViewImage', $result->content[0]->text);
    }

    public function testReturnsErrorWhenUidIsZero(): void
    {
        $tool = new ExtractDocumentImagesTool(
            new AttachmentService(
                GeneralUtility::makeInstance(ResourceFactory::class),
                GeneralUtility::makeInstance(ConnectionPool::class),
            ),
            GeneralUtility::makeInstance(DocumentImageExtractorService::class),
            GeneralUtility::makeInstance(ExtractedImageStore::class),
        );

        $result = $tool->execute(['uid' => 0]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('uid', $result->content[0]->text);
    }

    private function buildTool(int $uid, string $mime, ?string $localPath, ?int $size = null): ExtractDocumentImagesTool
    {
        $size ??= $localPath !== null ? (int)filesize($localPath) : 4096;

        $file = self::createStub(File::class);
        $file->method('getUid')->willReturn($uid);
        $file->method('getMimeType')->willReturn($mime);
        $file->method('getSize')->willReturn($size);
        $file->method('getName')->willReturn('document.docx');
        $file->method('getCombinedIdentifier')->willReturn('1:/uploads/document.docx');
        $file->method('getSha1')->willReturn(sha1((string)$uid . $mime));
        if ($localPath !== null) {
            $file->method('getForLocalProcessing')->willReturn($localPath);
            $file->method('getContents')->willReturn((string)file_get_contents($localPath));
        }

        $factory = self::createStub(ResourceFactory::class);
        $factory->method('getFileObject')->willReturn($file);

        return new ExtractDocumentImagesTool(
            new AttachmentService($factory, GeneralUtility::makeInstance(ConnectionPool::class)),
            GeneralUtility::makeInstance(DocumentImageExtractorService::class),
            GeneralUtility::makeInstance(ExtractedImageStore::class),
        );
    }

    /**
     * @param array<string, string> $entries path => bytes
     */
    private function makeOfficeZip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'agent_docx_') . '.zip';
        $this->tempFiles[] = $path;
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types/>');
        foreach ($entries as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }
        $zip->close();
        return $path;
    }

    /** A noisy PNG large enough to clear the extractor's 1 KiB minimum. */
    private function makePng(int $w = 160, int $h = 120): string
    {
        $im = imagecreatetruecolor($w, $h);
        for ($i = 0; $i < 300; $i++) {
            $col = imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255));
            imagefilledrectangle($im, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $col);
        }
        ob_start();
        imagepng($im);
        $bytes = (string)ob_get_clean();
        imagedestroy($im);
        return $bytes;
    }
}
