<?php

declare(strict_types=1);

namespace Hn\Agent\MCP\Tool;

use Hn\Agent\Service\AttachmentService;
use Hn\Agent\Service\DocumentExtractionException;
use Hn\Agent\Service\DocumentImageExtractorService;
use Hn\Agent\Service\ExtractedImageStore;
use Hn\McpServer\MCP\Tool\AbstractTool;
use Mcp\Types\CallToolResult;
use Mcp\Types\ImageContent;
use Mcp\Types\TextContent;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Imaging\GraphicalFunctions;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Load one specific image extracted from a document into the model context,
 * by document sys_file UID + image index (from ExtractDocumentImages).
 *
 * This is the deliberate vision escape hatch: ExtractDocumentImages keeps
 * thumbnails out of the model context, but when the agent must actually *see*
 * a particular image (e.g. the user referred to it visually), this returns
 * exactly that one image as ImageContent — one image, on demand, instead of
 * all of them on every turn.
 *
 * Auto-registered into the MCP ToolRegistry via the `mcp.tool` Symfony tag
 * declared on Hn\McpServer\MCP\Tool\ToolInterface.
 */
class ViewExtractedImageTool extends AbstractTool implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const DOWNSCALE_WIDTHS = [1200, 900, 600];

    public function __construct(
        private readonly AttachmentService $attachmentService,
        private readonly DocumentImageExtractorService $extractor,
        private readonly ExtractedImageStore $store,
    ) {}

    public function getSchema(): array
    {
        return [
            'description' => 'View one image extracted from a document, by the document\'s sys_file UID and the '
                . 'image index reported by ExtractDocumentImages. Returns that single image inline so you can '
                . 'see it. Use this sparingly — only when you must inspect a specific image yourself; otherwise '
                . 'let the user pick from the thumbnails and go straight to StoreImageInFileadmin.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'uid' => [
                        'type' => 'integer',
                        'description' => 'The sys_file UID of the document the image was extracted from.',
                    ],
                    'index' => [
                        'type' => 'integer',
                        'description' => 'The image index from ExtractDocumentImages (0-based).',
                        'minimum' => 0,
                    ],
                ],
                'required' => ['uid', 'index'],
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
        $index = (int)($params['index'] ?? -1);
        if ($uid <= 0) {
            return new CallToolResult([new TextContent('Error: parameter "uid" is required and must be a positive integer.')], true);
        }
        if ($index < 0) {
            return new CallToolResult([new TextContent('Error: parameter "index" is required and must be >= 0.')], true);
        }

        [$info, $resolutionNote] = $this->attachmentService->resolveWithFallback($uid);
        $head = $resolutionNote !== null ? $resolutionNote . "\n" : '';

        if ($info['kind'] === 'unresolvable') {
            return new CallToolResult(
                [new TextContent(sprintf('UID %d could not be resolved as sys_file, sys_file_reference, or sys_file_metadata.', $uid))],
                true,
            );
        }
        if (!in_array($info['kind'], ['document', 'presentation', 'spreadsheet', 'pdf'], true)) {
            return new CallToolResult(
                [new TextContent(sprintf('%ssys_file:%d is not a document (MIME %s). Run ExtractDocumentImages on a document first.',
                    $head, $info['file']->getUid(), $info['mime'] !== '' ? $info['mime'] : 'application/octet-stream'))],
                true,
            );
        }

        $file = $info['file'];
        try {
            $image = $this->store->getOrProduce($file, $index, fn() => $this->extractor->extractImages($file));
        } catch (DocumentExtractionException $e) {
            return new CallToolResult([new TextContent($head . 'Error: ' . $e->getMessage())], true);
        }
        if ($image === null) {
            return new CallToolResult([new TextContent(sprintf(
                '%sBild #%d existiert nicht in sys_file:%d. Rufe ExtractDocumentImages auf, um die gültigen Indizes zu sehen.',
                $head, $index, $file->getUid(),
            ))], true);
        }

        [$bytes, $mime] = $this->fitToImageBudget((string)$image['bytes'], (string)$image['mime']);
        if ($bytes === null) {
            return new CallToolResult([new TextContent(sprintf(
                '%sBild #%d (%s) überschreitet %s und konnte nicht verkleinert werden.',
                $head, $index, $image['mime'],
                $this->attachmentService->formatBytes(AttachmentService::MAX_IMAGE_BYTES),
            ))], true);
        }

        $metadata = $head . sprintf(
            "Bild #%d aus %s (sys_file:%d)\nName: %s\nMIME: %s\nGröße: %s",
            $index,
            $file->getName(),
            $file->getUid(),
            $image['name'],
            $mime,
            $this->attachmentService->formatBytes(strlen($bytes)),
        );

        return new CallToolResult([
            new TextContent($metadata),
            new ImageContent(base64_encode($bytes), $mime),
        ]);
    }

    /**
     * Return the image as-is when within the inline image cap, otherwise
     * downscale it to fit. Returns [null, mime] when it cannot be made to fit.
     *
     * @return array{0: ?string, 1: string}
     */
    private function fitToImageBudget(string $bytes, string $mime): array
    {
        if (strlen($bytes) <= AttachmentService::MAX_IMAGE_BYTES) {
            return [$bytes, $mime];
        }
        $tmpIn = GeneralUtility::tempnam('agent_view_extracted_');
        GeneralUtility::writeFile($tmpIn, $bytes, true);
        try {
            $gfx = GeneralUtility::makeInstance(GraphicalFunctions::class);
            foreach (self::DOWNSCALE_WIDTHS as $width) {
                $result = $gfx->imageMagickConvert($tmpIn, 'jpg', (string)$width, '', '-quality 82 -background white -flatten', '');
                if (is_array($result) && isset($result[3]) && is_string($result[3]) && is_file($result[3])) {
                    $out = file_get_contents($result[3]);
                    if ($out !== false && strlen($out) <= AttachmentService::MAX_IMAGE_BYTES) {
                        return [$out, 'image/jpeg'];
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger?->warning('Extracted image downscale failed', ['exception' => $e->getMessage()]);
        } finally {
            if (is_file($tmpIn)) {
                @unlink($tmpIn);
            }
        }
        return [null, $mime];
    }
}
