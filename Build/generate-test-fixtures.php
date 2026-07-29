<?php

declare(strict_types=1);

/**
 * Regenerates the binary file fixtures under Tests/Functional/Fixtures/Files/
 * that DocumentExtractorServiceTest and ReadFileToolTest parse with the real
 * PhpOffice / pdfparser libraries. The fixtures are committed — run this only
 * when the expected content needs to change, then update the test assertions:
 *
 *   composer install
 *   php Build/generate-test-fixtures.php
 */

use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\IOFactory as PresentationIOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\PhpWord;

require __DIR__ . '/../vendor/autoload.php';

$targetDir = __DIR__ . '/../Tests/Functional/Fixtures/Files';
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0775, true);
}

// ---------------------------------------------------------------------
// PDF: two pages, hand-built (no PDF writer available) — offsets in the
// xref table are computed, so the file is valid for strict parsers.
// ---------------------------------------------------------------------

function buildPdf(array $pageTexts): string
{
    $objects = [];
    $pageCount = count($pageTexts);

    $kids = [];
    for ($i = 0; $i < $pageCount; $i++) {
        $kids[] = (3 + $i) . ' 0 R';
    }

    $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . "] /Count {$pageCount} >>";

    $fontObjNum = 3 + 2 * $pageCount;
    for ($i = 0; $i < $pageCount; $i++) {
        $pageObjNum = 3 + $i;
        $contentObjNum = 3 + $pageCount + $i;
        $objects[$pageObjNum] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] "
            . "/Contents {$contentObjNum} 0 R /Resources << /Font << /F1 {$fontObjNum} 0 R >> >> >>";
        $stream = "BT /F1 14 Tf 72 720 Td (" . $pageTexts[$i] . ") Tj ET";
        $objects[$contentObjNum] = "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}\nendstream";
    }
    $objects[$fontObjNum] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    ksort($objects);
    foreach ($objects as $num => $body) {
        $offsets[$num] = strlen($pdf);
        $pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $objTotal = count($objects) + 1;
    $pdf .= "xref\n0 {$objTotal}\n";
    $pdf .= "0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    $pdf .= "trailer\n<< /Size {$objTotal} /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";
    return $pdf;
}

file_put_contents($targetDir . '/document.pdf', buildPdf([
    'Hauptsache PDF Testdokument Seite eins',
    'Zweiter Abschnitt auf Seite zwei',
]));

// Sanity check: the committed fixture must be parseable by the same
// library the extractor uses.
$parsed = (new Smalot\PdfParser\Parser())->parseFile($targetDir . '/document.pdf');
assert(count($parsed->getPages()) === 2);
assert(str_contains($parsed->getPages()[0]->getText(), 'Seite eins'));

// ---------------------------------------------------------------------
// XLSX: two sheets with known cells
// ---------------------------------------------------------------------

$spreadsheet = new Spreadsheet();
$umsatz = $spreadsheet->getActiveSheet();
$umsatz->setTitle('Umsatz');
$umsatz->fromArray([
    ['Monat', 'Umsatz'],
    ['Januar', 1200],
    ['Februar', 1500],
]);
$notizen = $spreadsheet->createSheet();
$notizen->setTitle('Notizen');
$notizen->setCellValue('A1', 'Interne Notiz');
(new XlsxWriter($spreadsheet))->save($targetDir . '/spreadsheet.xlsx');
$spreadsheet->disconnectWorksheets();

// ---------------------------------------------------------------------
// DOCX: two paragraphs + one embedded PNG (for ExtractImages coverage)
// ---------------------------------------------------------------------

$pngPath = tempnam(sys_get_temp_dir(), 'fixture') . '.png';
file_put_contents($pngPath, base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=',
));

$phpWord = new PhpWord();
$section = $phpWord->addSection();
$section->addText('Hauptsache Testdokument');
$section->addText('Dies ist der erste Absatz des Testdokuments.');
$section->addText('Der zweite Absatz enthält weitere Inhalte.');
$section->addImage($pngPath);
WordIOFactory::createWriter($phpWord, 'Word2007')->save($targetDir . '/document.docx');

// ---------------------------------------------------------------------
// PPTX: three slides — title as first text shape (the extractor falls
// back to the first non-empty text block when no placeholder survives
// the write/read roundtrip)
// ---------------------------------------------------------------------

$presentation = new PhpPresentation();
$slides = [
    ['Agenda', 'Begrüßung und Überblick'],
    ['Marktübersicht', 'Der Markt wächst stetig.'],
    ['Fazit', 'Alle Ziele wurden erreicht.'],
];
foreach ($slides as $i => [$title, $body]) {
    $slide = $i === 0 ? $presentation->getActiveSlide() : $presentation->createSlide();
    $titleShape = $slide->createRichTextShape()->setOffsetX(50)->setOffsetY(50)->setWidth(600)->setHeight(80);
    $titleShape->createTextRun($title);
    $bodyShape = $slide->createRichTextShape()->setOffsetX(50)->setOffsetY(160)->setWidth(600)->setHeight(300);
    $bodyShape->createTextRun($body);
}
PresentationIOFactory::createWriter($presentation, 'PowerPoint2007')->save($targetDir . '/presentation.pptx');

unlink($pngPath);

echo "Fixtures written to {$targetDir}:\n";
foreach (glob($targetDir . '/*') as $file) {
    printf("  %-20s %6d bytes\n", basename($file), filesize($file));
}
