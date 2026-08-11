<?php

declare(strict_types=1);

namespace Hn\Agent\Service;

use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Turns persisted messages (as returned by AgentMessageRepository) into
 * the shape the LLM client expects.
 *
 * Images referenced via the `attachments` sys_file-UID list are inlined
 * as OpenAI-style `image_url` blocks (base64 data URIs). Non-image
 * attachments become a marker block appended to the text content —
 * the LLM then calls the ReadFile tool.
 *
 * The `attachments` key never leaves this layer — the LLM API does not
 * know about it.
 */
class MessageLlmSerializer
{
    public function __construct(
        private readonly ResourceFactory $resourceFactory,
        private readonly AttachmentService $attachmentService,
    ) {}

    /**
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    public function serialize(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            $out[] = $this->serializeOne($message);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private function serializeOne(array $message): array
    {
        // Strip internal fields the LLM API does not need / would reject.
        unset($message['uid']);

        $attachmentUids = $message['attachments'] ?? null;
        unset($message['attachments']);
        if (!is_array($attachmentUids) || $attachmentUids === []) {
            return $message;
        }

        $imageBlocks = [];
        $markerRefs = [];
        foreach ($attachmentUids as $uid) {
            $uid = (int)$uid;
            if ($uid <= 0) {
                continue;
            }
            $file = $this->tryLoadFile($uid);
            if (!$file instanceof File) {
                $markerRefs[] = ['unresolvable' => true, 'name' => 'sys_file:' . $uid];
                continue;
            }

            $mime = strtolower((string)$file->getMimeType());
            $size = (int)$file->getSize();
            if ($this->isInlineImage($mime, $size)) {
                $imageBlocks[] = [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => 'data:' . $mime . ';base64,' . base64_encode($file->getContents()),
                    ],
                ];
                // The bytes are already delivered inline above. We still emit a
                // marker so the LLM knows the file's sys_file UID (and can pass
                // it to file tools / cite it) — flagged `inline` so the marker
                // note tells it no viewer tool is needed.
                $markerRefs[] = [
                    'uid' => $file->getUid(),
                    'identifier' => $file->getCombinedIdentifier(),
                    'name' => $file->getName(),
                    'mime_type' => $mime !== '' ? $mime : 'application/octet-stream',
                    'inline' => true,
                ];
                continue;
            }

            $markerRefs[] = [
                'uid' => $file->getUid(),
                'identifier' => $file->getCombinedIdentifier(),
                'name' => $file->getName(),
                'mime_type' => $mime !== '' ? $mime : 'application/octet-stream',
            ];
        }

        $text = (string)($message['content'] ?? '');
        if ($markerRefs !== []) {
            $text = $this->attachmentService->mergeMarkersIntoContent($text, $markerRefs);
        }

        if ($imageBlocks === []) {
            $message['content'] = $text;
            return $message;
        }

        $blocks = [];
        if ($text !== '') {
            $blocks[] = ['type' => 'text', 'text' => $text];
        }
        foreach ($imageBlocks as $block) {
            $blocks[] = $block;
        }
        $message['content'] = $blocks;
        return $message;
    }

    private function tryLoadFile(int $uid): ?File
    {
        try {
            $file = $this->resourceFactory->getFileObject($uid);
            return $file instanceof File ? $file : null;
        } catch (FileDoesNotExistException) {
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function isInlineImage(string $mime, int $size): bool
    {
        return in_array($mime, AttachmentService::SUPPORTED_IMAGE_MIME_TYPES, true)
            && $size > 0
            && $size <= AttachmentService::MAX_IMAGE_BYTES;
    }
}
