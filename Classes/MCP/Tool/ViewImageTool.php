<?php

declare(strict_types=1);

namespace Hn\Agent\MCP\Tool;

use Hn\Agent\Service\AttachmentService;
use Hn\McpServer\MCP\Tool\AbstractTool;
use Mcp\Types\CallToolResult;
use Mcp\Types\ImageContent;
use Mcp\Types\TextContent;

/**
 * Inspect an image file from FAL by its sys_file UID. For supported
 * formats (PNG/JPEG/WEBP/GIF) within size limits the bytes are returned
 * as base64-encoded ImageContent so the LLM can actually see them.
 *
 * Non-image MIMEs (PDFs, audio, …) and oversize images return an
 * `isError: true` result with a hint to call `GetFileInfo` for
 * metadata-only inspection. Binary payloads in `tool`-role messages
 * have proven unreliable across providers (OpenRouter↔Bedrock 500s for
 * Claude on PDFs), so this tool stays strictly image-only.
 *
 * Accepts sys_file_reference / sys_file_metadata UIDs as a fallback
 * via AttachmentService::resolveWithFallback().
 *
 * Auto-registered into the MCP ToolRegistry via the `mcp.tool` Symfony tag
 * declared on Hn\McpServer\MCP\Tool\ToolInterface.
 */
class ViewImageTool extends AbstractTool
{
    public function __construct(
        private readonly AttachmentService $attachmentService,
    ) {}

    public function getSchema(): array
    {
        return [
            'description' => 'Inspect an image (PNG/JPEG/WEBP/GIF) from TYPO3\'s File Abstraction Layer (FAL) '
                . 'by its sys_file UID. Returns the binary content base64-encoded so it can be displayed / '
                . 'analyzed inline, plus a metadata block. Use the GetFileInfo tool for any non-image file '
                . '(PDFs, audio, …) — those return an error here. '
                . 'If you pass a sys_file_reference or sys_file_metadata UID by mistake the tool '
                . 'transparently resolves it to the underlying sys_file and tells you the canonical '
                . 'sys_file UID in the result.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'uid' => [
                        'type' => 'integer',
                        'description' => 'The sys_file UID of the image to inspect. Accepts sys_file_reference / sys_file_metadata UIDs as a fallback.',
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
        $head = $resolutionNote !== null ? $resolutionNote . "\n" : '';

        if ($info['kind'] === 'unsupported') {
            return new CallToolResult(
                [new TextContent(sprintf(
                    "%ssys_file:%d has MIME %s. ViewImage only handles PNG/JPEG/WEBP/GIF — call GetFileInfo for metadata of other file types.",
                    $head,
                    $file->getUid(),
                    $info['mime'] !== '' ? $info['mime'] : 'application/octet-stream',
                ))],
                true,
            );
        }

        if ($info['kind'] === 'oversize') {
            return new CallToolResult(
                [new TextContent(sprintf(
                    "%ssys_file:%d (%s) is %s — image inspection is capped at %s. Use GetFileInfo for metadata.",
                    $head,
                    $file->getUid(),
                    $info['mime'],
                    $this->attachmentService->formatBytes($info['size']),
                    $this->attachmentService->formatBytes(AttachmentService::MAX_IMAGE_BYTES),
                ))],
                true,
            );
        }

        // image within limits: hand bytes off via MCP's ImageContent transport.
        // AgentService::buildToolContent wraps it into the OpenAI `image_url`
        // content block that reaches the LLM.
        $metadata = $head . sprintf(
            "File: %s\nMIME: %s\nSize: %s\nUID: sys_file:%d\nIdentifier: %s",
            $file->getName(),
            $info['mime'],
            $this->attachmentService->formatBytes($info['size']),
            $file->getUid(),
            $file->getCombinedIdentifier(),
        );
        $base64 = base64_encode($file->getContents());
        return new CallToolResult([
            new TextContent($metadata),
            new ImageContent($base64, $info['mime']),
        ]);
    }
}
