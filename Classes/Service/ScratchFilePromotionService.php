<?php

declare(strict_types=1);

namespace Hn\Agent\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\DefaultUploadFolderResolver;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Copies a sys_file out of the non-public agent scratch storage
 * (var/agent-scratch/, is_public=0) into a regular, web-reachable FAL
 * location. Uses ResourceStorage::copyFile() so the original stays around
 * for chat-history preview; metadata (title, alt) rides along automatically
 * via ResourceStorage's metaDataAspect.
 *
 * Shared by the PromoteScratchFile tool (explicit promotion by the LLM)
 * and the ScratchFileWriteGuard event listener (automatic promotion when a
 * WriteTable call references a scratch file in a regular record).
 */
class ScratchFilePromotionService
{
    public function __construct(
        private readonly AgentScratchStorage $scratchStorage,
        private readonly ResourceFactory $resourceFactory,
        private readonly DefaultUploadFolderResolver $defaultUploadFolderResolver,
    ) {}

    public function isScratchFile(File $file): bool
    {
        return $this->scratchStorage->isScratchFile($file);
    }

    /**
     * Copy a scratch file into the target folder (default upload folder of
     * the current BE user when none is given) and return the public copy.
     *
     * @throws \RuntimeException when no target folder can be resolved
     */
    public function promote(File $source, string $targetFolderIdentifier = '', string $targetName = ''): File
    {
        $targetFolder = $this->resolveTargetFolder($targetFolderIdentifier);
        return $targetFolder->getStorage()->copyFile(
            $source,
            $targetFolder,
            $targetName !== '' ? $targetName : $source->getName(),
            DuplicationBehavior::RENAME,
        );
    }

    /**
     * Resolve a combined identifier to a folder, creating it (recursively)
     * when it doesn't exist yet — LLMs routinely name folders that don't
     * exist, and erroring out kills the promote flow for a recoverable
     * situation. Empty identifier → default upload folder of the BE user.
     */
    public function resolveTargetFolder(string $combinedIdentifier): Folder
    {
        if ($combinedIdentifier !== '') {
            try {
                $folder = $this->resourceFactory->getFolderObjectFromCombinedIdentifier($combinedIdentifier);
                if ($folder instanceof Folder) {
                    return $folder;
                }
            } catch (FolderDoesNotExistException) {
                // fall through to auto-create
            }
            return $this->createTargetFolder($combinedIdentifier);
        }

        $beUser = $GLOBALS['BE_USER'] ?? null;
        if (!$beUser instanceof BackendUserAuthentication) {
            throw new \RuntimeException('Kein BE-User-Kontext verfügbar — Zielordner muss explizit angegeben werden (target_folder).');
        }
        $folder = $this->defaultUploadFolderResolver->resolve($beUser);
        if (!$folder instanceof Folder) {
            throw new \RuntimeException('Default-Upload-Folder konnte nicht ermittelt werden — Zielordner explizit angeben (target_folder, z.B. "1:/user_upload/").');
        }
        return $folder;
    }

    /**
     * Create a not-yet-existing target folder (recursively) inside the
     * storage named by the combined identifier. Storage permissions still
     * apply via ResourceStorage::createFolder().
     */
    private function createTargetFolder(string $combinedIdentifier): Folder
    {
        if (!str_contains($combinedIdentifier, ':')) {
            throw new \RuntimeException(sprintf(
                'Zielordner "%s" konnte nicht aufgelöst werden — Combined Identifier wie "1:/user_upload/agent/" erwartet.',
                $combinedIdentifier,
            ));
        }
        [$storageUid, $path] = explode(':', $combinedIdentifier, 2);
        $storage = $this->resourceFactory->getStorageObject((int)$storageUid);
        $path = trim($path, '/');
        if ($path === '') {
            return $storage->getRootLevelFolder();
        }
        return $storage->createFolder($path);
    }
}
