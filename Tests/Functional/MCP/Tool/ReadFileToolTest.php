<?php

declare(strict_types=1);

namespace Hn\Agent\Tests\Functional\MCP\Tool;

use Hn\Agent\MCP\Tool\ReadFileTool;
use Hn\Agent\Service\AttachmentService;
use Hn\Agent\Service\SpreadsheetExtractionService;
use Mcp\Types\ImageContent;
use Mcp\Types\TextContent;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
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

    public function testReadsImageAndReturnsBase64(): void
    {
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=');
        $tool = $this->buildTool(101, 'image/png', strlen($pngBytes), 'pixel.png', '1:/uploads/pixel.png', $pngBytes);

        $result = $tool->execute(['uid' => 101]);

        self::assertFalse($result->isError, 'Image read should not be flagged as error');
        self::assertCount(2, $result->content);

        $text = $result->content[0];
        $image = $result->content[1];
        self::assertInstanceOf(TextContent::class, $text);
        self::assertStringContainsString('pixel.png', $text->text);
        self::assertStringContainsString('image/png', $text->text);
        self::assertStringContainsString('sys_file:101', $text->text);

        self::assertInstanceOf(ImageContent::class, $image);
        self::assertSame('image/png', $image->mimeType);
        self::assertSame(base64_encode($pngBytes), $image->data);
    }

    public function testReadsPdfAndReturnsBase64(): void
    {
        $pdfBytes = "%PDF-1.4\n% minimal\n";
        $tool = $this->buildTool(202, 'application/pdf', strlen($pdfBytes), 'doc.pdf', '1:/uploads/doc.pdf', $pdfBytes);

        $result = $tool->execute(['uid' => 202]);

        self::assertFalse($result->isError);
        self::assertCount(2, $result->content);
        self::assertInstanceOf(ImageContent::class, $result->content[1]);
        self::assertSame('application/pdf', $result->content[1]->mimeType);
        self::assertSame(base64_encode($pdfBytes), $result->content[1]->data);
    }

    public function testReturnsMetadataOnlyForUnsupportedMime(): void
    {
        // text/plain is not on the embed allowlist — must not load contents.
        $tool = $this->buildTool(404, 'text/plain', 100, 'notes.txt', '1:/uploads/notes.txt', null);

        $result = $tool->execute(['uid' => 404]);

        self::assertFalse($result->isError);
        self::assertCount(1, $result->content);
        $text = $result->content[0];
        self::assertInstanceOf(TextContent::class, $text);
        self::assertStringContainsString('notes.txt', $text->text);
        self::assertStringContainsString('text/plain', $text->text);
        self::assertStringContainsString('metadata only', $text->text);
    }

    public function testReturnsMetadataOnlyWhenOversize(): void
    {
        // 6 MiB > 5 MiB image cap — getContents must never be called.
        $tool = $this->buildTool(303, 'image/png', 6 * 1024 * 1024, 'huge.png', '1:/uploads/huge.png', null);

        $result = $tool->execute(['uid' => 303]);

        self::assertFalse($result->isError);
        self::assertCount(1, $result->content);
        $text = $result->content[0];
        self::assertInstanceOf(TextContent::class, $text);
        self::assertStringContainsString('huge.png', $text->text);
        self::assertStringContainsString('zu groß', $text->text);
        self::assertStringContainsString('metadata only', $text->text);
    }

    public function testReturnsErrorWhenUidNotFound(): void
    {
        // No mapping for this uid — the real ResourceFactory will throw,
        // AttachmentService catches and reports unresolvable.
        $tool = new ReadFileTool(
            new AttachmentService(GeneralUtility::makeInstance(ResourceFactory::class)),
            GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class),
            new SpreadsheetExtractionService(),
        );

        $result = $tool->execute(['uid' => 999999]);

        self::assertTrue($result->isError);
        self::assertCount(1, $result->content);
        $text = $result->content[0];
        self::assertInstanceOf(TextContent::class, $text);
        self::assertStringContainsString('999999', $text->text);
        self::assertStringContainsString('could not be resolved', $text->text);
    }

    public function testReturnsErrorWhenUidIsZero(): void
    {
        $tool = new ReadFileTool(
            new AttachmentService(GeneralUtility::makeInstance(ResourceFactory::class)),
            GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class),
            new SpreadsheetExtractionService(),
        );

        $result = $tool->execute(['uid' => 0]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('uid', $result->content[0]->text);
    }

    private function buildTool(int $uid, string $mime, int $size, string $name, string $identifier, ?string $content): ReadFileTool
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
        $factory->method('getFileObject')->with($uid)->willReturn($file);

        return new ReadFileTool(
            new AttachmentService($factory),
            GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class),
            new SpreadsheetExtractionService(),
        );
    }

    public function testReadsXlsxAndExtractsTextAndEmbeddedImage(): void
    {
        $xlsxPath = $this->createXlsxFixture();
        $tool = $this->buildSpreadsheetTool(
            501,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xlsx',
            'report.xlsx',
            '1:/uploads/report.xlsx',
            $xlsxPath,
        );

        $result = $tool->execute(['uid' => 501]);

        self::assertFalse($result->isError);
        self::assertInstanceOf(TextContent::class, $result->content[0]);
        self::assertStringContainsString('report.xlsx', $result->content[0]->text);
        self::assertStringContainsString('Daten', $result->content[0]->text);
        self::assertStringContainsString('Hallo', $result->content[0]->text);
        self::assertStringContainsString('Welt', $result->content[0]->text);

        $images = array_values(array_filter(
            $result->content,
            static fn ($c) => $c instanceof ImageContent,
        ));
        self::assertNotEmpty($images, 'Embedded image should be extracted best-effort');
        self::assertStringStartsWith('image/', $images[0]->mimeType);
    }

    public function testReadsCsvAsText(): void
    {
        $csvPath = tempnam(sys_get_temp_dir(), 'csvfix') . '.csv';
        file_put_contents($csvPath, "Name,Wert\nAlpha,1\nBeta,2\n");

        $tool = $this->buildSpreadsheetTool(601, 'text/csv', 'csv', 'data.csv', '1:/uploads/data.csv', $csvPath);

        $result = $tool->execute(['uid' => 601]);

        self::assertFalse($result->isError);
        self::assertInstanceOf(TextContent::class, $result->content[0]);
        self::assertStringContainsString('Alpha', $result->content[0]->text);
        self::assertStringContainsString('Beta', $result->content[0]->text);

        $images = array_filter($result->content, static fn ($c) => $c instanceof ImageContent);
        self::assertEmpty($images, 'CSV carries no embedded images');
    }

    private function createXlsxFixture(): string
    {
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=');
        $pngPath = tempnam(sys_get_temp_dir(), 'xlsximg') . '.png';
        file_put_contents($pngPath, $pngBytes);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Daten');
        $sheet->setCellValue('A1', 'Hallo');
        $sheet->setCellValue('B1', 'Welt');
        $sheet->setCellValue('A2', 42);

        $drawing = new Drawing();
        $drawing->setName('Logo');
        $drawing->setPath($pngPath);
        $drawing->setCoordinates('D1');
        $drawing->setWorksheet($sheet);

        $xlsxPath = tempnam(sys_get_temp_dir(), 'xlsxfix') . '.xlsx';
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($xlsxPath);

        return $xlsxPath;
    }

    private function buildSpreadsheetTool(int $uid, string $mime, string $extension, string $name, string $identifier, string $localPath): ReadFileTool
    {
        $file = $this->getMockBuilder(File::class)->disableOriginalConstructor()->getMock();
        $file->method('getUid')->willReturn($uid);
        $file->method('getMimeType')->willReturn($mime);
        $file->method('getExtension')->willReturn($extension);
        $file->method('getSize')->willReturn(filesize($localPath) ?: 1024);
        $file->method('getName')->willReturn($name);
        $file->method('getCombinedIdentifier')->willReturn($identifier);
        $file->method('getForLocalProcessing')->willReturn($localPath);

        $factory = $this->getMockBuilder(ResourceFactory::class)->disableOriginalConstructor()->getMock();
        $factory->method('getFileObject')->with($uid)->willReturn($file);

        return new ReadFileTool(
            new AttachmentService($factory),
            GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class),
            new SpreadsheetExtractionService(),
        );
    }
}
