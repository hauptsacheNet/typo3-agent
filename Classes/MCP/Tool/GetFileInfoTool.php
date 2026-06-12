<?php

declare(strict_types=1);

namespace Hn\Agent\MCP\Tool;

use Hn\Agent\Service\AttachmentService;
use Hn\McpServer\MCP\Tool\AbstractTool;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Resource\File;

/**
 * Return metadata for a FAL file by its sys_file UID — name, MIME, size,
 * identifier — without ever reading the bytes. Works for every MIME type.
 *
 * For inspecting an image's content the LLM must call `ViewImage` instead;
 * other binary tools (e.g. a future PDF reader) live next to this one.
 *
 * Accepts sys_file_reference and sys_file_metadata UIDs as a fallback:
 * `GetPage` lists records with the *reference* row's uid, which LLMs
 * reliably pass into file tools by mistake. The tool resolves
 * transparently via AttachmentService::resolveWithFallback() and reports
 * the canonical sys_file UID so the next call uses it directly.
 *
 * Auto-registered into the MCP ToolRegistry via the `mcp.tool` Symfony tag
 * declared on Hn\McpServer\MCP\Tool\ToolInterface.
 */
class GetFileInfoTool extends AbstractTool
{
    public function __construct(
        private readonly AttachmentService $attachmentService,
    ) {}

    public function getSchema(): array
    {
        return [
            'description' => 'Get metadata for a file from TYPO3\'s File Abstraction Layer (FAL) by its sys_file UID. '
                . 'Returns name, MIME type, size, sys_file UID and combined identifier — but never the file bytes. '
                . 'Works for every MIME type. To actually inspect an image use the ViewImage tool. '
                . 'If you pass a sys_file_reference or sys_file_metadata UID by mistake the tool '
                . 'transparently resolves it to the underlying sys_file and tells you the canonical '
                . 'sys_file UID in the result.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'uid' => [
                        'type' => 'integer',
                        'description' => 'The sys_file UID to inspect. Accepts sys_file_reference / sys_file_metadata UIDs as a fallback.',
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

        if ($info['kind'] === 'unresolvable') {
            return new CallToolResult(
                [new TextContent(sprintf(
                    'UID %d could not be resolved as sys_file, sys_file_reference, or sys_file_metadata.',
                    $uid,
                ))],
                true,
            );
        }

        $file = $info['file'];
        $metadata = $this->describe($file, $info['mime'], $info['size']);
        if ($resolutionNote !== null) {
            $metadata = $resolutionNote . "\n" . $metadata;
        }
        if ($info['kind'] === 'oversize') {
            $metadata .= "\n" . $info['reason'];
        }

        return new CallToolResult([new TextContent($metadata)]);
    }

    private function describe(File $file, string $mime, int $size): string
    {
        return sprintf(
            "File: %s\nMIME: %s\nSize: %s\nUID: sys_file:%d\nIdentifier: %s",
            $file->getName(),
            $mime !== '' ? $mime : 'application/octet-stream',
            $this->attachmentService->formatBytes($size),
            $file->getUid(),
            $file->getCombinedIdentifier(),
        );
    }
}
