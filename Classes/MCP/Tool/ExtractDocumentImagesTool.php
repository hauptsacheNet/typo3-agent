<?php

declare(strict_types=1);

namespace Hn\Agent\MCP\Tool;

use Hn\Agent\Service\AttachmentService;
use Hn\Agent\Service\DocumentExtractionException;
use Hn\Agent\Service\DocumentImageExtractorService;
use Hn\Agent\Service\ExtractedImageStore;
use Hn\McpServer\MCP\Tool\AbstractTool;
use Mcp\Types\Annotations;
use Mcp\Types\CallToolResult;
use Mcp\Types\ImageContent;
use Mcp\Types\Role;
use Mcp\Types\TextContent;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Imaging\GraphicalFunctions;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Extract the images embedded in a document (DOCX/PPTX/XLSX/ODT/ODP/ODS/PDF)
 * by its sys_file UID, so an editor can pick one and store it in fileadmin.
 *
 * Context-budget design: the model receives only a **text index** of the
 * images (`#0 — name (mime, WxH, bytes)`). The downscaled thumbnails are
 * attached as user-audience ImageContent (MCP `annotations.audience = [user]`),
 * which ToolConverterService routes into a UI-only lane that the chat renders
 * but AgentService::serializeForLlm() strips — so the previews never re-enter
 * the model context on later turns. To actually *see* one image, the model
 * calls ViewExtractedImage(uid, index); to keep one, StoreImageInFileadmin.
 *
 * Auto-registered into the MCP ToolRegistry via the `mcp.tool` Symfony tag
 * declared on Hn\McpServer\MCP\Tool\ToolInterface.
 */
class ExtractDocumentImagesTool extends AbstractTool implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** Max thumbnails attached per call; the rest are paged via `offset`. */
    private const MAX_INLINE_PREVIEWS = 12;
    private const THUMB_MAX_DIM = 512;
    private const THUMB_MAX_BYTES = 400 * 1024;

    public function __construct(
        private readonly AttachmentService $attachmentService,
        private readonly DocumentImageExtractorService $extractor,
        private readonly ExtractedImageStore $store,
    ) {}

    public function getSchema(): array
    {
        return [
            'description' => 'Extract the images embedded inside a document (DOCX/PPTX/XLSX/ODT/ODP/ODS/PDF) '
                . 'by its sys_file UID. Returns a numbered text index of the images; thumbnails are shown to '
                . 'the user in the chat (not loaded into your context). When several images are found, present '
                . 'the index and ask the user which one to keep, then call StoreImageInFileadmin with this '
                . 'document\'s uid and the chosen index. Call ViewExtractedImage(uid, index) only if you '
                . 'genuinely need to see a specific image yourself. For a standalone image file use ViewImage. '
                . 'Accepts sys_file_reference / sys_file_metadata UIDs as a fallback.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'uid' => [
                        'type' => 'integer',
                        'description' => 'The sys_file UID of the document.',
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'description' => 'Index of the first thumbnail to render (for paging large documents). Default: 0. The text index always lists all images.',
                        'default' => 0,
                        'minimum' => 0,
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
        $offset = max(0, (int)($params['offset'] ?? 0));

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
                [new TextContent(sprintf('%ssys_file:%d (%s) is %s — image extraction is capped at %s.',
                    $head, $info['file']->getUid(), $info['mime'],
                    $this->attachmentService->formatBytes($info['size']),
                    $this->attachmentService->formatBytes(AttachmentService::MAX_OFFICE_BYTES),
                ))],
                true,
            );
        }
        if (!in_array($info['kind'], ['document', 'presentation', 'spreadsheet', 'pdf'], true)) {
            $hint = $info['kind'] === 'image'
                ? 'This is already a standalone image — use ViewImage to inspect it.'
                : 'ExtractDocumentImages handles DOCX/PPTX/XLSX/ODT/ODP/ODS/PDF — call GetFileInfo for other types.';
            return new CallToolResult(
                [new TextContent(sprintf('%ssys_file:%d has MIME %s. %s',
                    $head, $info['file']->getUid(),
                    $info['mime'] !== '' ? $info['mime'] : 'application/octet-stream',
                    $hint,
                ))],
                true,
            );
        }

        try {
            $images = $this->extractor->extractImages($info['file']);
        } catch (DocumentExtractionException $e) {
            return new CallToolResult([new TextContent($head . 'Error: ' . $e->getMessage())], true);
        }

        if ($images === []) {
            return new CallToolResult([new TextContent(sprintf(
                '%ssys_file:%d (%s) enthält keine extrahierbaren Bilder.%s',
                $head, $info['file']->getUid(), $info['mime'],
                $info['kind'] === 'pdf' ? ' Bei PDFs ggf. ViewPdfPage nutzen, um eine Seite als Bild zu rendern.' : '',
            ))]);
        }

        $this->store->put($info['file'], $images);

        $total = count($images);
        $lines = [];
        foreach ($images as $i => $img) {
            $lines[] = sprintf(
                '#%d — %s (%s, %s, %s)',
                $i,
                $img['sourceName'],
                $img['mime'],
                $img['width'] && $img['height'] ? $img['width'] . '×' . $img['height'] : 'Maße unbekannt',
                $this->attachmentService->formatBytes(strlen((string)$img['bytes'])),
            );
        }

        $shownTo = min($offset + self::MAX_INLINE_PREVIEWS, $total);
        $metadata = sprintf(
            "%sFile: %s\nUID: sys_file:%d\nGefundene Bilder: %d\n\n%s",
            $head,
            $info['file']->getName(),
            $info['file']->getUid(),
            $total,
            implode("\n", $lines),
        );
        $metadata .= sprintf(
            "\n\nThumbnails für #%d–#%d werden dem Nutzer im Chat angezeigt (nicht in deinem Kontext). "
            . "Frage den Nutzer, welches Bild übernommen werden soll, und rufe dann "
            . "StoreImageInFileadmin(uid=%d, index=<n>) auf.",
            $offset,
            max($offset, $shownTo - 1),
            $info['file']->getUid(),
        );
        if ($shownTo < $total) {
            $metadata .= sprintf("\nWeitere Thumbnails: ExtractDocumentImages mit offset=%d.", $shownTo);
        }

        $content = [new TextContent($metadata)];

        // Downscaled, user-audience thumbnails for the chat UI only.
        for ($i = $offset; $i < $shownTo; $i++) {
            $thumb = $this->makeThumbnail((string)$images[$i]['bytes'], (string)$images[$i]['mime']);
            if ($thumb === null) {
                continue;
            }
            $annotations = new Annotations(audience: [Role::USER]);
            $annotations->label = sprintf('#%d — %s', $i, $images[$i]['sourceName']);
            $content[] = new ImageContent($thumb['data'], $thumb['mime'], $annotations);
        }

        return new CallToolResult($content);
    }

    /**
     * Downscale to a small JPEG preview via TYPO3's ImageMagick pipeline.
     * Falls back to the original bytes when conversion is unavailable and the
     * original is already small enough.
     *
     * @return array{data: string, mime: string}|null base64 data + MIME
     */
    private function makeThumbnail(string $bytes, string $sourceMime): ?array
    {
        $tmpIn = GeneralUtility::tempnam('agent_thumb_');
        GeneralUtility::writeFile($tmpIn, $bytes, true);
        try {
            $gfx = GeneralUtility::makeInstance(GraphicalFunctions::class);
            $result = $gfx->imageMagickConvert(
                $tmpIn,
                'jpg',
                (string)self::THUMB_MAX_DIM,
                (string)self::THUMB_MAX_DIM,
                '-quality 80 -background white -flatten',
                '',
            );
            if (is_array($result) && isset($result[3]) && is_string($result[3]) && is_file($result[3])) {
                $out = file_get_contents($result[3]);
                if ($out !== false && strlen($out) <= self::THUMB_MAX_BYTES) {
                    return ['data' => base64_encode($out), 'mime' => 'image/jpeg'];
                }
            }
        } catch (\Throwable $e) {
            $this->logger?->warning('Thumbnail generation failed', ['exception' => $e->getMessage()]);
        } finally {
            if (is_file($tmpIn)) {
                @unlink($tmpIn);
            }
        }

        if (strlen($bytes) <= self::THUMB_MAX_BYTES
            && in_array($sourceMime, AttachmentService::SUPPORTED_IMAGE_MIME_TYPES, true)) {
            return ['data' => base64_encode($bytes), 'mime' => $sourceMime];
        }
        return null;
    }
}
