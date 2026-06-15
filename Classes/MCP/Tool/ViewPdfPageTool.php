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
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Imaging\GraphicalFunctions;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Render one PDF page (1-indexed) as a JPEG via TYPO3's existing
 * GraphicsMagick / ImageMagick + Ghostscript pipeline. Result is
 * returned as base64-encoded ImageContent — same transport as ViewImage.
 *
 * One call = one page. Use ReadPdfText for the textual content of a
 * range of pages.
 *
 * Auto-registered into the MCP ToolRegistry via the `mcp.tool` Symfony tag
 * declared on Hn\McpServer\MCP\Tool\ToolInterface.
 */
class ViewPdfPageTool extends AbstractTool implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const RENDER_WIDTHS = [1500, 1200, 900];
    private const RENDER_MIME = 'image/jpeg';

    public function __construct(
        private readonly AttachmentService $attachmentService,
        private readonly DocumentExtractorService $extractor,
    ) {}

    public function getSchema(): array
    {
        return [
            'description' => 'Render one page of a PDF (FAL sys_file) as a JPEG image and return it inline. '
                . 'Pages are 1-indexed. One call returns exactly one page; call again with a different "page" to get more. '
                . 'For the text of a page or page range use ReadPdfText. '
                . 'Uses TYPO3\'s configured GraphicsMagick/ImageMagick + Ghostscript pipeline; results are cached on disk. '
                . 'Accepts sys_file_reference / sys_file_metadata UIDs as a fallback.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'uid' => [
                        'type' => 'integer',
                        'description' => 'The sys_file UID of the PDF.',
                    ],
                    'page' => [
                        'type' => 'integer',
                        'description' => 'Page number (1-indexed).',
                        'minimum' => 1,
                    ],
                ],
                'required' => ['uid', 'page'],
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
        $page = (int)($params['page'] ?? 0);
        if ($uid <= 0) {
            return new CallToolResult([new TextContent('Error: parameter "uid" is required and must be a positive integer.')], true);
        }
        if ($page <= 0) {
            return new CallToolResult([new TextContent('Error: parameter "page" is required and must be a positive integer (1-indexed).')], true);
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
                [new TextContent(sprintf('%ssys_file:%d (%s) is %s — PDF rendering is capped at %s.',
                    $head, $info['file']->getUid(), $info['mime'],
                    $this->attachmentService->formatBytes($info['size']),
                    $this->attachmentService->formatBytes(AttachmentService::MAX_PDF_BYTES),
                ))],
                true,
            );
        }
        if ($info['kind'] !== 'pdf') {
            return new CallToolResult(
                [new TextContent(sprintf('%ssys_file:%d has MIME %s. ViewPdfPage only handles PDFs — pick the right tool for this MIME or call GetFileInfo.',
                    $head, $info['file']->getUid(), $info['mime'] !== '' ? $info['mime'] : 'application/octet-stream',
                ))],
                true,
            );
        }

        try {
            $pageCount = $this->extractor->getPdfPageCount($info['file']);
        } catch (DocumentExtractionException $e) {
            return new CallToolResult([new TextContent($head . 'Error: ' . $e->getMessage())], true);
        }

        if ($page > $pageCount) {
            return new CallToolResult(
                [new TextContent(sprintf(
                    '%sPage %d does not exist — sys_file:%d has %d page(s).',
                    $head, $page, $info['file']->getUid(), $pageCount,
                ))],
                true,
            );
        }

        $sourcePath = $info['file']->getForLocalProcessing(false);

        foreach (self::RENDER_WIDTHS as $width) {
            $bytes = $this->renderPage($sourcePath, $width, $page - 1);
            if ($bytes === null) {
                return new CallToolResult(
                    [new TextContent($head . 'Error: PDF konnte nicht gerendert werden — prüfen, ob GraphicsMagick/ImageMagick + Ghostscript im Container verfügbar sind.')],
                    true,
                );
            }
            if (strlen($bytes) <= AttachmentService::MAX_IMAGE_BYTES) {
                $metadata = sprintf(
                    "%sFile: %s\nUID: sys_file:%d\nSeite: %d von %d\nGerendert: JPEG, Breite %d px, %s",
                    $head,
                    $info['file']->getName(),
                    $info['file']->getUid(),
                    $page,
                    $pageCount,
                    $width,
                    $this->attachmentService->formatBytes(strlen($bytes)),
                );
                return new CallToolResult([
                    new TextContent($metadata),
                    new ImageContent(base64_encode($bytes), self::RENDER_MIME),
                ]);
            }
        }

        return new CallToolResult(
            [new TextContent(sprintf(
                "%sGerenderte Seite überschreitet %s auch bei reduzierter Breite. Verwende stattdessen ReadPdfText für den Inhalt.",
                $head,
                $this->attachmentService->formatBytes(AttachmentService::MAX_IMAGE_BYTES),
            ))],
            true,
        );
    }

    private function renderPage(string $sourcePath, int $width, int $pageZeroBased): ?string
    {
        try {
            $gfx = GeneralUtility::makeInstance(GraphicalFunctions::class);
            $result = $gfx->imageMagickConvert(
                $sourcePath,
                'jpg',
                (string)$width,
                '',
                '-quality 80 -density 144 -background white -flatten',
                (string)$pageZeroBased,
            );
            // imageMagickConvert returns the legacy [w, h, ext, filepath] array via $result?->toLegacyArray().
            if (!is_array($result) || !isset($result[3]) || !is_string($result[3])) {
                return null;
            }
            $renderedPath = $result[3];
            if (!is_file($renderedPath)) {
                return null;
            }
            $bytes = file_get_contents($renderedPath);
            return $bytes === false ? null : $bytes;
        } catch (\Throwable $e) {
            $this->logger?->warning('PDF page rendering failed', [
                'source' => $sourcePath,
                'width' => $width,
                'page' => $pageZeroBased,
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
