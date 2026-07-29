<?php

declare(strict_types=1);

namespace Hn\Agent\Tests\Functional\Service;

use Hn\Agent\Service\DocumentExtractionException;
use Hn\Agent\Service\DocumentExtractorService;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Exercises the real parser paths (smalot/pdfparser, PhpSpreadsheet,
 * PhpWord, PhpPresentation) against the committed binary fixtures in
 * Tests/Functional/Fixtures/Files/ — regenerate them via
 * `php Build/generate-test-fixtures.php` if the expected content changes.
 */
class DocumentExtractorServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
        'agent',
    ];

    private DocumentExtractorService $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new DocumentExtractorService();
    }

    // -----------------------------------------------------------------
    // PDF
    // -----------------------------------------------------------------

    public function testPdfPageCount(): void
    {
        $file = $this->fixtureFile('document.pdf', 'application/pdf');

        self::assertSame(2, $this->extractor->getPdfPageCount($file));
    }

    public function testExtractsSinglePdfPage(): void
    {
        $file = $this->fixtureFile('document.pdf', 'application/pdf');

        $result = $this->extractor->extractPdfPages($file, 1, 1);

        self::assertSame(2, $result['pageCount']);
        self::assertSame(1, $result['fromPage']);
        self::assertSame(1, $result['toPage']);
        self::assertStringContainsString('--- Seite 1 ---', $result['text']);
        self::assertStringContainsString('Hauptsache PDF Testdokument Seite eins', $result['text']);
        self::assertStringNotContainsString('Zweiter Abschnitt', $result['text']);
    }

    public function testExtractsPdfPageRangeToEnd(): void
    {
        $file = $this->fixtureFile('document.pdf', 'application/pdf');

        $result = $this->extractor->extractPdfPages($file, 2, 2);

        self::assertStringContainsString('--- Seite 2 ---', $result['text']);
        self::assertStringContainsString('Zweiter Abschnitt auf Seite zwei', $result['text']);
        self::assertStringNotContainsString('Seite eins', $result['text']);
    }

    public function testPdfRangeIsClampedToDocument(): void
    {
        $file = $this->fixtureFile('document.pdf', 'application/pdf');

        $result = $this->extractor->extractPdfPages($file, 1, 99);

        self::assertSame(2, $result['toPage']);
        self::assertStringContainsString('Seite eins', $result['text']);
        self::assertStringContainsString('Seite zwei', $result['text']);
    }

    public function testBrokenPdfThrowsExtractionException(): void
    {
        $file = $this->createStub(File::class);
        $file->method('getContents')->willReturn('definitely not a pdf');

        $this->expectException(DocumentExtractionException::class);
        $this->extractor->getPdfPageCount($file);
    }

    // -----------------------------------------------------------------
    // Spreadsheet
    // -----------------------------------------------------------------

    public function testSpreadsheetOutlineListsAllSheets(): void
    {
        $file = $this->fixtureFile('spreadsheet.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $outline = $this->extractor->getSpreadsheetOutline($file);

        self::assertSame('Umsatz', $outline['activeSheet']);
        self::assertCount(2, $outline['sheets']);
        self::assertSame(['index' => 0, 'name' => 'Umsatz', 'rows' => 3, 'cols' => 2], $outline['sheets'][0]);
        self::assertSame('Notizen', $outline['sheets'][1]['name']);
    }

    public function testExtractsSpreadsheetCellsBySheetName(): void
    {
        $file = $this->fixtureFile('spreadsheet.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $result = $this->extractor->extractSpreadsheetRange($file, 'Umsatz', null);

        self::assertSame('Umsatz', $result['sheetName']);
        self::assertSame(3, $result['totalRows']);
        self::assertSame(2, $result['totalCols']);
        self::assertStringContainsString("Monat\tUmsatz", $result['text']);
        self::assertStringContainsString("Februar\t1500", $result['text']);
    }

    public function testExtractsSpreadsheetA1RangeSubset(): void
    {
        $file = $this->fixtureFile('spreadsheet.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $result = $this->extractor->extractSpreadsheetRange($file, 'Umsatz', 'A1:B2');

        self::assertSame('A1:B2', $result['rangeUsed']);
        self::assertStringContainsString("Januar\t1200", $result['text']);
        self::assertStringNotContainsString('Februar', $result['text']);
    }

    public function testExtractsSpreadsheetSheetByZeroBasedIndex(): void
    {
        $file = $this->fixtureFile('spreadsheet.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $result = $this->extractor->extractSpreadsheetRange($file, 1, null);

        self::assertSame('Notizen', $result['sheetName']);
        self::assertStringContainsString('Interne Notiz', $result['text']);
    }

    public function testUnknownSheetNameThrowsExtractionException(): void
    {
        $file = $this->fixtureFile('spreadsheet.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->expectException(DocumentExtractionException::class);
        $this->expectExceptionMessageMatches('/existiert nicht/');
        $this->extractor->extractSpreadsheetRange($file, 'GibtEsNicht', null);
    }

    // -----------------------------------------------------------------
    // Document (DOCX)
    // -----------------------------------------------------------------

    public function testExtractsDocxText(): void
    {
        $file = $this->fixtureFile('document.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $result = $this->extractor->extractDocumentText($file, 0, DocumentExtractorService::MAX_OUTPUT_CHARS);

        self::assertStringContainsString('Hauptsache Testdokument', $result['text']);
        self::assertStringContainsString('Dies ist der erste Absatz des Testdokuments.', $result['text']);
        self::assertStringContainsString('Der zweite Absatz enthält weitere Inhalte.', $result['text']);
        self::assertSame(mb_strlen($result['text']), $result['returnedChars']);
        self::assertSame($result['returnedChars'], $result['totalChars']);
    }

    public function testDocxCharOffsetWindowsThroughText(): void
    {
        $file = $this->fixtureFile('document.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $full = $this->extractor->extractDocumentText($file, 0, DocumentExtractorService::MAX_OUTPUT_CHARS);
        $window = $this->extractor->extractDocumentText($file, 10, 5);

        self::assertSame(10, $window['charOffset']);
        self::assertSame(5, $window['returnedChars']);
        self::assertSame(mb_substr($full['text'], 10, 5), $window['text']);
        self::assertSame($full['totalChars'], $window['totalChars']);
    }

    public function testExtractsEmbeddedDocxImages(): void
    {
        $file = $this->fixtureFile('document.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $images = $this->extractor->extractDocumentImages($file);

        self::assertCount(1, $images);
        self::assertSame('image/png', $images[0]['mime']);
        self::assertStringStartsWith("\x89PNG", $images[0]['bytes']);
    }

    // -----------------------------------------------------------------
    // Presentation (PPTX)
    // -----------------------------------------------------------------

    public function testPresentationOutlineListsSlideTitles(): void
    {
        $file = $this->fixtureFile('presentation.pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation');

        $outline = $this->extractor->getPresentationOutline($file);

        self::assertCount(3, $outline['slides']);
        self::assertSame(['index' => 1, 'title' => 'Agenda'], $outline['slides'][0]);
        self::assertSame('Marktübersicht', $outline['slides'][1]['title']);
        self::assertSame('Fazit', $outline['slides'][2]['title']);
    }

    public function testExtractsSingleSlideWithTitleAndBody(): void
    {
        $file = $this->fixtureFile('presentation.pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation');

        $result = $this->extractor->extractPresentationSlides($file, 2, 2);

        self::assertSame(3, $result['slideCount']);
        self::assertStringContainsString('--- Slide 2: Marktübersicht ---', $result['text']);
        self::assertStringContainsString('Der Markt wächst stetig.', $result['text']);
        self::assertStringNotContainsString('Agenda', $result['text']);
        self::assertStringNotContainsString('Fazit', $result['text']);
    }

    // -----------------------------------------------------------------
    // Range parsing + output budget (pure logic)
    // -----------------------------------------------------------------

    public function testParseRangeVariants(): void
    {
        self::assertSame(['from' => 1, 'to' => 9], $this->extractor->parseRange('all', 9));
        self::assertSame(['from' => 3, 'to' => 3], $this->extractor->parseRange('3', 9));
        self::assertSame(['from' => 1, 'to' => 5], $this->extractor->parseRange('1-5', 9));
        self::assertSame(['from' => 7, 'to' => 9], $this->extractor->parseRange('7-', 9));
        self::assertSame(['from' => 2, 'to' => 9], $this->extractor->parseRange('2-99', 9));
    }

    public function testParseRangeRejectsGarbage(): void
    {
        $this->expectException(DocumentExtractionException::class);
        $this->extractor->parseRange('erste Seite', 9);
    }

    public function testParseRangeRejectsBackwardsRange(): void
    {
        $this->expectException(DocumentExtractionException::class);
        $this->extractor->parseRange('5-2', 9);
    }

    public function testCapOutputTruncatesWithContinuationHint(): void
    {
        $line = str_repeat('a', 99) . "\n";
        $text = str_repeat($line, 600); // 60k chars > 50k cap

        $capped = $this->extractor->capOutput($text, 'Nächste Seite anfordern.');

        self::assertLessThan(mb_strlen($text), mb_strlen($capped));
        self::assertStringContainsString('[Output gekürzt bei', $capped);
        self::assertStringContainsString('Nächste Seite anfordern.', $capped);
    }

    public function testCapOutputLeavesShortTextUntouched(): void
    {
        self::assertSame('kurz', $this->extractor->capOutput('kurz', 'Hinweis'));
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * File stub backed by a committed fixture: getContents() serves the
     * bytes (PDF path), getForLocalProcessing() the on-disk path (PhpOffice
     * readers), getMimeType() drives the document-format dispatch.
     */
    private function fixtureFile(string $name, string $mime): File
    {
        $path = __DIR__ . '/../Fixtures/Files/' . $name;
        self::assertFileExists($path, 'Fixture missing — run `php Build/generate-test-fixtures.php`.');

        $file = $this->createStub(File::class);
        $file->method('getContents')->willReturnCallback(static fn(): string => (string)file_get_contents($path));
        $file->method('getForLocalProcessing')->willReturn($path);
        $file->method('getMimeType')->willReturn($mime);
        $file->method('getName')->willReturn($name);
        $file->method('getCombinedIdentifier')->willReturn('1:/fixtures/' . $name);
        return $file;
    }
}
