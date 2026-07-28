<?php

declare(strict_types=1);

namespace Hn\Agent\MCP\Tool;

use Hn\Agent\Service\AttachmentService;
use Hn\Agent\Service\DocumentExtractionException;
use Hn\Agent\Service\DocumentExtractorService;
use Hn\McpServer\MCP\Tool\AbstractTool;
use Mcp\Types\CallToolResult;
use Mcp\Types\ImageContent;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Extract raster images embedded in a document (DOCX/ODT, PPTX/ODP,
 * XLSX/ODS, PDF) by FAL sys_file UID. Each extracted image is returned
 * as ImageContent — ToolConverterService persists the bytes into the
 * agent scratch storage and hangs them off the tool-result message as
 * sys_file_references, so they show up in the chat as attachments the
 * user can reference in follow-up prompts.
 *
 * PDF support is optional and only enabled if `pdfimagesPath` is
 * configured (Settings > Extension Configuration > agent), pointing at
 * a poppler-utils `pdfimages` binary. Without it, PDFs return an error.
 *
 * Registered agent-only via the `agent.tool` tag in Services.yaml — this
 * tool is NOT exposed through the external MCP server.
 */
class ExtractImagesTool extends AbstractTool
{
    public function __construct(
        private readonly AttachmentService $attachmentService,
        private readonly DocumentExtractorService $extractor,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function getSchema(): array
    {
        return [
            'description' => 'Extract embedded raster images from a document (DOCX/ODT, PPTX/ODP, XLSX/ODS, PDF) by sys_file UID. '
                . 'Each returned image is attached to this tool result as a sys_file — the user sees them as chat attachments '
                . 'and can reference them in follow-up prompts (e.g. "beschreib das zweite Bild"). '
                . 'Capped at ' . DocumentExtractorService::MAX_IMAGES_PER_CALL . ' images per call and ' . (AttachmentService::MAX_IMAGE_BYTES / 1024 / 1024) . ' MB per image; oversize images are dropped with a note. '
                . 'PDF extraction requires the pdfimages binary to be configured in the extension settings. '
                . 'Accepts sys_file_reference / sys_file_metadata UIDs as a fallback. '
                . 'Die extrahierten Bilder liegen in der internen Scratch-Storage und sind noch nicht direkt in TYPO3 verwendbar — zum Einbinden an einen Content-Datensatz das gewählte Bild zuerst mit PromoteScratchFile in eine öffentliche FAL-Location kopieren, dann per WriteTable als sys_file_reference (z.B. tt_content.image) referenzieren.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'uid' => [
                        'type' => 'integer',
                        'description' => 'The sys_file UID of the document to extract images from.',
                    ],
                ],
                'required' => ['uid'],
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'idempotentHint' => true,
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $uid = (int)($params['uid'] ?? 0);
        if ($uid <= 0) {
            return new CallToolResult([new TextContent('Error: parameter "uid" is required and must be a positive integer.')], true);
        }

        [$info, $resolutionNote] = $this->attachmentService->resolveWithFallback($uid);
        $head = $resolutionNote !== null ? $resolutionNote . "\n" : '';

        if ($info['kind'] === 'unresolvable') {
            return new CallToolResult(
                [new TextContent(sprintf('UID %d could not be resolved as sys_file, sys_file_reference, or sys_file_metadata.', $uid))],
                true,
            );
        }
        if ($info['kind'] === 'oversize') {
            return new CallToolResult(
                [new TextContent(sprintf('%ssys_file:%d (%s) is %s — file exceeds the size cap for image extraction.',
                    $head, $info['file']->getUid(), $info['mime'], $this->attachmentService->formatBytes($info['size']),
                ))],
                true,
            );
        }
        if (!in_array($info['kind'], ['document', 'presentation', 'spreadsheet', 'pdf'], true)) {
            return new CallToolResult(
                [new TextContent(sprintf('%ssys_file:%d has MIME %s. ExtractImages only handles DOCX/ODT, PPTX/ODP, XLSX/ODS and PDF — use ReadFile to read this file instead.',
                    $head, $info['file']->getUid(), $info['mime'] !== '' ? $info['mime'] : 'application/octet-stream',
                ))],
                true,
            );
        }

        try {
            $images = match ($info['kind']) {
                'document' => $this->extractor->extractDocumentImages($info['file']),
                'presentation' => $this->extractor->extractPresentationImages($info['file']),
                'spreadsheet' => $this->extractor->extractSpreadsheetImages($info['file']),
                'pdf' => $this->extractPdfImages($info['file']),
            };
        } catch (DocumentExtractionException $e) {
            return new CallToolResult([new TextContent($head . 'Error: ' . $e->getMessage())], true);
        }

        if ($images === []) {
            return new CallToolResult([new TextContent(sprintf(
                '%sKeine eingebetteten Bilder in %s (sys_file:%d) gefunden.',
                $head, $info['file']->getName(), $info['file']->getUid(),
            ))]);
        }

        $content = [];
        $kept = 0;
        $droppedOversize = 0;
        foreach ($images as $image) {
            $size = strlen($image['bytes']);
            if ($size > AttachmentService::MAX_IMAGE_BYTES) {
                $droppedOversize++;
                continue;
            }
            $content[] = new ImageContent(base64_encode($image['bytes']), $image['mime']);
            $kept++;
        }

        $summaryLines = [
            sprintf('%sFile: %s', $head, $info['file']->getName()),
            sprintf('UID: sys_file:%d', $info['file']->getUid()),
            sprintf('Bilder extrahiert: %d', $kept),
        ];
        if ($droppedOversize > 0) {
            $summaryLines[] = sprintf(
                'Nicht mitgeliefert: %d Bild(er) über %s.',
                $droppedOversize,
                $this->attachmentService->formatBytes(AttachmentService::MAX_IMAGE_BYTES),
            );
        }
        if (count($images) >= DocumentExtractorService::MAX_IMAGES_PER_CALL) {
            $summaryLines[] = sprintf('Hinweis: pro Aufruf werden maximal %d Bilder gelesen. Datei könnte weitere Bilder enthalten.', DocumentExtractorService::MAX_IMAGES_PER_CALL);
        }

        array_unshift($content, new TextContent(implode("\n", $summaryLines)));
        return new CallToolResult($content);
    }

    /**
     * @return list<array{bytes: string, mime: string, name: string}>
     */
    private function extractPdfImages(\TYPO3\CMS\Core\Resource\File $file): array
    {
        $config = $this->extensionConfiguration->get('agent');
        $pdfimagesPath = trim((string)($config['pdfimagesPath'] ?? ''));
        if ($pdfimagesPath === '') {
            throw new DocumentExtractionException(
                'PDF-Bildextraktion ist nicht konfiguriert. Setze den Pfad zu pdfimages (poppler-utils) in den Extension-Einstellungen unter agent.pdfimagesPath.',
            );
        }
        if (!is_file($pdfimagesPath) || !is_executable($pdfimagesPath)) {
            throw new DocumentExtractionException(
                sprintf('Konfiguriertes pdfimages-Binary "%s" existiert nicht oder ist nicht ausführbar.', $pdfimagesPath),
            );
        }
        return $this->extractor->extractPdfImages($file, $pdfimagesPath);
    }
}
