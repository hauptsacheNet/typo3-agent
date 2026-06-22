<?php

declare(strict_types=1);

namespace Hn\Agent\Tests\Functional\MCP\Tool;

use Hn\Agent\MCP\Tool\StoreImageInFileadminTool;
use Hn\Agent\Service\AttachmentService;
use Hn\Agent\Service\DocumentImageExtractorService;
use Hn\Agent\Service\ExtractedImageStore;
use Hn\Agent\Service\MediaImportService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class StoreImageInFileadminToolTest extends FunctionalTestCase
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

    public function testStoresChosenImageAndReturnsNewUid(): void
    {
        $zip = $this->makeOfficeZip(['word/media/image1.png' => $this->makePng()]);

        $storedFile = self::createStub(File::class);
        $storedFile->method('getUid')->willReturn(500);
        $storedFile->method('getCombinedIdentifier')->willReturn('1:/user_upload/image1.png');
        $storedFile->method('getMimeType')->willReturn('image/png');
        $storedFile->method('getSize')->willReturn(2048);

        $mediaImport = $this->getMockBuilder(MediaImportService::class)->disableOriginalConstructor()->getMock();
        $mediaImport->expects(self::once())
            ->method('importImage')
            ->with(self::anything(), 'image/png', self::anything(), null)
            ->willReturn($storedFile);

        $tool = $this->buildTool(201, self::DOCX_MIME, $zip, $mediaImport);

        $result = $tool->execute(['uid' => 201, 'index' => 0]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        self::assertStringContainsString('sys_file:500', $result->content[0]->text);
        self::assertStringContainsString('1:/user_upload/image1.png', $result->content[0]->text);
    }

    public function testErrorsOnUnknownIndex(): void
    {
        $zip = $this->makeOfficeZip(['word/media/image1.png' => $this->makePng()]);
        $mediaImport = $this->getMockBuilder(MediaImportService::class)->disableOriginalConstructor()->getMock();
        $mediaImport->expects(self::never())->method('importImage');

        $tool = $this->buildTool(202, self::DOCX_MIME, $zip, $mediaImport);

        $result = $tool->execute(['uid' => 202, 'index' => 7]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('existiert nicht', $result->content[0]->text);
    }

    public function testRejectsNonDocument(): void
    {
        $mediaImport = $this->getMockBuilder(MediaImportService::class)->disableOriginalConstructor()->getMock();
        $mediaImport->expects(self::never())->method('importImage');

        $tool = $this->buildTool(203, 'image/png', null, $mediaImport, 1024);

        $result = $tool->execute(['uid' => 203, 'index' => 0]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('ExtractDocumentImages', $result->content[0]->text);
    }

    private function buildTool(int $uid, string $mime, ?string $localPath, MediaImportService $mediaImport, ?int $size = null): StoreImageInFileadminTool
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

        return new StoreImageInFileadminTool(
            new AttachmentService($factory, GeneralUtility::makeInstance(ConnectionPool::class)),
            GeneralUtility::makeInstance(DocumentImageExtractorService::class),
            GeneralUtility::makeInstance(ExtractedImageStore::class),
            $mediaImport,
        );
    }

    /**
     * @param array<string, string> $entries
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
