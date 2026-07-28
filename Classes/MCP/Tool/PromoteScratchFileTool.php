<?php

declare(strict_types=1);

namespace Hn\Agent\MCP\Tool;

use Hn\Agent\Service\AgentScratchStorage;
use Hn\Agent\Service\AttachmentService;
use Hn\McpServer\MCP\Tool\AbstractTool;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\DefaultUploadFolderResolver;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Promote a sys_file that currently lives in the internal agent scratch
 * storage (var/agent-scratch/, is_public=0) into a regular, web-reachable
 * FAL location. Uses ResourceStorage::copyFile() so the original stays
 * around for chat-history preview; metadata (title, alt) rides along
 * automatically via ResourceStorage's metaDataAspect.
 *
 * The returned sys_file UID can be fed straight into WriteTable as the
 * `uid_local` of a sys_file_reference (e.g. tt_content.image), which is
 * the missing link that keeps extracted images from being usable in
 * TYPO3 today.
 *
 * Registered agent-only via the `agent.tool` tag in Services.yaml — this
 * tool is NOT exposed through the external MCP server.
 */
class PromoteScratchFileTool extends AbstractTool
{
    public function __construct(
        private readonly AttachmentService $attachmentService,
        private readonly AgentScratchStorage $scratchStorage,
        private readonly ResourceFactory $resourceFactory,
        private readonly DefaultUploadFolderResolver $defaultUploadFolderResolver,
    ) {}

    public function getSchema(): array
    {
        return [
            'description' => 'Copy a sys_file from the internal agent scratch storage into a public FAL folder so it can be referenced by TYPO3 records. '
                . 'Files landing in the scratch storage include: (a) binary tool outputs such as ExtractImages/ReadFile attachments and (b) files the user uploaded through the chat composer. Both kinds live in a non-public storage and are NOT web-reachable — you MUST promote them before using them in any record other than tx_agent_message. '
                . 'Typical use: pick the sys_file UID of the scratch attachment you want to reference (e.g. from a prior ExtractImages result or from the user\'s composer upload) and call this tool. '
                . 'The returned sys_file UID is the value you pass as `uid_local` when creating a sys_file_reference via WriteTable (e.g. `image: [{uid_local: <returnedUid>, alternative: "…"}]` on tt_content). '
                . 'Only files from the agent scratch storage may be promoted — regular fileadmin files are rejected. Copies (does not move); the original stays in scratch for chat-history preview.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'uid' => [
                        'type' => 'integer',
                        'description' => 'sys_file UID of the scratch file to promote (usually an attachment UID from a prior ExtractImages result).',
                    ],
                    'target_folder' => [
                        'type' => 'string',
                        'description' => 'Optional combined identifier of the destination folder, e.g. "1:/fileadmin/user_upload/agent/". If omitted, the TYPO3 default upload folder for the current backend user is used.',
                    ],
                    'target_name' => [
                        'type' => 'string',
                        'description' => 'Optional destination filename. If omitted, the source filename is kept. Name collisions are resolved automatically (suffix `_01`).',
                    ],
                ],
                'required' => ['uid'],
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

        $sourceFile = $info['file'];
        if (!$sourceFile instanceof File) {
            return new CallToolResult(
                [new TextContent(sprintf('%sUID %d resolved but did not yield a sys_file.', $head, $uid))],
                true,
            );
        }

        if (!$this->scratchStorage->isScratchFile($sourceFile)) {
            return new CallToolResult(
                [new TextContent(sprintf(
                    '%ssys_file:%d ("%s") is not in the agent scratch storage — only files produced by agent tools (e.g. ExtractImages) can be promoted. Regular fileadmin files are already usable via WriteTable directly.',
                    $head, $sourceFile->getUid(), $sourceFile->getName(),
                ))],
                true,
            );
        }

        $targetFolderInput = trim((string)($params['target_folder'] ?? ''));
        $targetName = trim((string)($params['target_name'] ?? ''));

        try {
            $targetFolder = $this->resolveTargetFolder($targetFolderInput);
        } catch (\Throwable $e) {
            return new CallToolResult([new TextContent($head . 'Error: ' . $e->getMessage())], true);
        }

        try {
            $newFile = $targetFolder->getStorage()->copyFile(
                $sourceFile,
                $targetFolder,
                $targetName !== '' ? $targetName : $sourceFile->getName(),
                DuplicationBehavior::RENAME,
            );
        } catch (\Throwable $e) {
            return new CallToolResult(
                [new TextContent(sprintf(
                    '%sKopieren nach %s ist fehlgeschlagen: %s',
                    $head, $targetFolder->getCombinedIdentifier(), $e->getMessage(),
                ))],
                true,
            );
        }

        $summary = sprintf(
            "%sPromoted sys_file:%d -> sys_file:%d\nOriginal: %s (Scratch, is_public=0)\nZiel: %s (%s)\nNutze uid_local=%d in einem sys_file_reference via WriteTable, um die Datei an einen Record zu hängen.",
            $head,
            $sourceFile->getUid(),
            $newFile->getUid(),
            $sourceFile->getName(),
            $newFile->getCombinedIdentifier(),
            $this->attachmentService->formatBytes((int)$newFile->getSize()),
            $newFile->getUid(),
        );
        return new CallToolResult([new TextContent($summary)]);
    }

    private function resolveTargetFolder(string $combinedIdentifier): Folder
    {
        if ($combinedIdentifier !== '') {
            $folder = $this->resourceFactory->getFolderObjectFromCombinedIdentifier($combinedIdentifier);
            if (!$folder instanceof Folder) {
                throw new \RuntimeException(sprintf('Zielordner "%s" konnte nicht aufgelöst werden.', $combinedIdentifier));
            }
            return $folder;
        }

        $beUser = $GLOBALS['BE_USER'] ?? null;
        if (!$beUser instanceof BackendUserAuthentication) {
            throw new \RuntimeException('Kein BE-User-Kontext verfügbar — Zielordner muss explizit angegeben werden (target_folder).');
        }
        $folder = $this->defaultUploadFolderResolver->resolve($beUser);
        if (!$folder instanceof Folder) {
            throw new \RuntimeException('Default-Upload-Folder konnte nicht ermittelt werden — Zielordner explizit angeben (target_folder, z.B. "1:/fileadmin/user_upload/").');
        }
        return $folder;
    }
}
