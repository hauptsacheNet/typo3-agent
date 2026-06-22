<?php

declare(strict_types=1);

namespace Hn\Agent\MCP\Tool;

use Hn\Agent\Service\AttachmentService;
use Hn\Agent\Service\DocumentExtractionException;
use Hn\Agent\Service\DocumentImageExtractorService;
use Hn\Agent\Service\ExtractedImageStore;
use Hn\Agent\Service\MediaImportService;
use Hn\McpServer\MCP\Tool\AbstractTool;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * Store one image extracted from a document into fileadmin as a real sys_file,
 * by the document's sys_file UID + the image index from ExtractDocumentImages.
 *
 * This is the write step of the extract → pick → store flow. The new sys_file
 * UID is returned so the agent can reference it afterwards (e.g. attach it to a
 * content element via WriteTable).
 *
 * Auto-registered into the MCP ToolRegistry via the `mcp.tool` Symfony tag
 * declared on Hn\McpServer\MCP\Tool\ToolInterface.
 */
class StoreImageInFileadminTool extends AbstractTool implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly AttachmentService $attachmentService,
        private readonly DocumentImageExtractorService $extractor,
        private readonly ExtractedImageStore $store,
        private readonly MediaImportService $mediaImport,
    ) {}

    public function getSchema(): array
    {
        return [
            'description' => 'Save one image extracted from a document into fileadmin as a new sys_file. '
                . 'Pass the document\'s sys_file UID and the image index from ExtractDocumentImages. '
                . 'Optionally set a target folder (combined identifier like "1:/user_upload/") and a filename. '
                . 'Returns the new sys_file UID, which you can then reference (e.g. via WriteTable to attach it '
                . 'to a content element). This writes a file — only call it once the user has chosen an image.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'uid' => [
                        'type' => 'integer',
                        'description' => 'The sys_file UID of the source document.',
                    ],
                    'index' => [
                        'type' => 'integer',
                        'description' => 'The image index from ExtractDocumentImages (0-based).',
                        'minimum' => 0,
                    ],
                    'target_folder' => [
                        'type' => 'string',
                        'description' => 'Optional FAL combined identifier of the target folder (e.g. "1:/user_upload/"). Defaults to the editor\'s default upload folder.',
                    ],
                    'filename' => [
                        'type' => 'string',
                        'description' => 'Optional file name. The extension is normalised to the image type. Defaults to the original embedded name.',
                    ],
                ],
                'required' => ['uid', 'index'],
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'idempotentHint' => false,
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
        $targetFolder = isset($params['target_folder']) ? (string)$params['target_folder'] : null;
        $filename = isset($params['filename']) ? (string)$params['filename'] : '';

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

        try {
            $stored = $this->mediaImport->importImage(
                (string)$image['bytes'],
                (string)$image['mime'],
                $filename !== '' ? $filename : (string)$image['name'],
                $targetFolder,
            );
        } catch (\Throwable $e) {
            return new CallToolResult([new TextContent($head . 'Error beim Speichern in fileadmin: ' . $e->getMessage())], true);
        }

        return new CallToolResult([new TextContent(sprintf(
            "%sBild #%d gespeichert.\nNeue Datei: sys_file:%d\nIdentifier: %s\nMIME: %s\nGröße: %s\n"
            . "Diese UID kann jetzt weiterverwendet werden (z. B. via WriteTable in ein Inhaltselement einbinden).",
            $head,
            $index,
            $stored->getUid(),
            $stored->getCombinedIdentifier(),
            $stored->getMimeType(),
            $this->attachmentService->formatBytes((int)$stored->getSize()),
        ))]);
    }
}
