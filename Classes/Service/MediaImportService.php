<?php

declare(strict_types=1);

namespace Hn\Agent\Service;

use TYPO3\CMS\Core\Resource\DefaultUploadFolderResolver;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Writes raw image bytes into fileadmin via FAL and returns the new sys_file.
 *
 * This is the one genuinely new capability — every other agent file tool only
 * reads. FAL permission checks run against the backend user set up by
 * {@see AgentService::setupBackendUserContext()}, so resolving the user's
 * default upload folder respects their access rights.
 */
class MediaImportService
{
    public function __construct(
        private readonly ResourceFactory $resourceFactory,
        private readonly DefaultUploadFolderResolver $defaultUploadFolderResolver,
    ) {}

    /**
     * @param string $bytes Raw image bytes
     * @param string $mime e.g. image/png
     * @param string $filename Desired file name (extension is normalised to the MIME)
     * @param string|null $targetFolderIdentifier Combined identifier (e.g. "1:/user_upload/"); null = default upload folder
     * @throws \RuntimeException when no writable target folder can be resolved
     */
    public function importImage(string $bytes, string $mime, string $filename, ?string $targetFolderIdentifier): File
    {
        $folder = $this->resolveFolder($targetFolderIdentifier);
        $filename = $this->normaliseFilename($filename, $mime, $folder);

        // Folder::addFile() consumes a local file, so stage the bytes first.
        $tempPath = GeneralUtility::tempnam('agent_media_');
        GeneralUtility::writeFile($tempPath, $bytes, true);
        try {
            return $folder->addFile($tempPath, $filename, DuplicationBehavior::RENAME);
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    private function resolveFolder(?string $identifier): Folder
    {
        $identifier = $identifier !== null ? trim($identifier) : '';
        if ($identifier !== '') {
            $folder = $this->resourceFactory->getFolderObjectFromCombinedIdentifier($identifier);
            if ($folder instanceof Folder) {
                return $folder;
            }
            throw new \RuntimeException(sprintf('Zielordner "%s" konnte nicht aufgelöst werden.', $identifier));
        }

        $beUser = $GLOBALS['BE_USER'] ?? null;
        if ($beUser !== null) {
            $folder = $this->defaultUploadFolderResolver->resolve($beUser);
            if ($folder instanceof Folder) {
                return $folder;
            }
        }
        throw new \RuntimeException('Kein Standard-Upload-Ordner verfügbar — bitte target_folder angeben (z. B. "1:/user_upload/").');
    }

    private function normaliseFilename(string $filename, string $mime, Folder $folder): string
    {
        $filename = trim($filename);
        if ($filename === '') {
            $filename = 'extracted-image';
        }
        $ext = match (strtolower($mime)) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'img',
        };
        if (!preg_match('/\.' . preg_quote($ext, '/') . '$/i', $filename)) {
            $filename = preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $filename) . '.' . $ext;
        }
        return $folder->getStorage()->sanitizeFileName($filename);
    }
}
