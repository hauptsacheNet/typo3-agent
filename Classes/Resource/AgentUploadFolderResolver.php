<?php

declare(strict_types=1);

namespace Hn\Agent\Resource;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Löst den Upload-Ordner für den Agent-Chat auf.
 *
 * Kaskade:
 *   1. Page-TSconfig `options.agent.defaultUploadFolder`
 *   2. User-TSconfig `options.agent.defaultUploadFolder`
 *   3. Fallback: Ordner `agent_upload/` im ersten beschreibbaren Storage
 *      des BE-Users (bevorzugt Default-Storage).
 *
 * Existiert der Ziel-Ordner nicht, wird er inklusive Zwischenordnern angelegt —
 * unabhängig von den regulären File-Permissions des BE-Users, damit auch
 * Editoren ohne fileadmin-Add-Rechte den Agent-Ordner beim ersten Zugriff
 * bekommen.
 */
class AgentUploadFolderResolver
{
    private const FALLBACK_FOLDER_NAME = 'agent_upload';

    public function __construct(
        private readonly ResourceFactory $resourceFactory,
        private readonly StorageRepository $storageRepository,
    ) {}

    public function resolve(BackendUserAuthentication $beUser, int $pageId, ?string $tableName = null): Folder|bool
    {
        $identifier = (string)($beUser->getTSConfig()['options.']['agent.']['defaultUploadFolder'] ?? '');
        $pageIdentifier = (string)(BackendUtility::getPagesTSconfig($pageId)['options.']['agent.']['defaultUploadFolder'] ?? '');
        if ($pageIdentifier !== '') {
            $identifier = $pageIdentifier;
        }

        if ($identifier !== '') {
            $folder = $this->resolveOrCreate($identifier);
            if ($folder instanceof Folder) {
                return $folder;
            }
        }

        $fallbackStorage = $this->findDefaultWritableStorage($beUser);
        if ($fallbackStorage !== null) {
            $folder = $this->resolveOrCreate($fallbackStorage->getUid() . ':/' . self::FALLBACK_FOLDER_NAME . '/');
            if ($folder instanceof Folder) {
                return $folder;
            }
        }

        return false;
    }

    private function resolveOrCreate(string $identifier): ?Folder
    {
        try {
            return $this->resourceFactory->getFolderObjectFromCombinedIdentifier($identifier);
        } catch (FolderDoesNotExistException) {
        }

        $parts = GeneralUtility::trimExplode(':', $identifier);
        if (count($parts) !== 2) {
            return null;
        }
        $storage = $this->storageRepository->findByUid((int)$parts[0]);
        $folderPath = trim($parts[1], '/');
        if ($storage === null || $folderPath === '') {
            return null;
        }

        $previousEvaluatePermissions = $storage->getEvaluatePermissions();
        $storage->setEvaluatePermissions(false);
        try {
            return $storage->createFolder($folderPath);
        } catch (\Throwable) {
            return null;
        } finally {
            $storage->setEvaluatePermissions($previousEvaluatePermissions);
        }
    }

    private function findDefaultWritableStorage(BackendUserAuthentication $beUser): ?\TYPO3\CMS\Core\Resource\ResourceStorage
    {
        foreach ($beUser->getFileStorages() as $storage) {
            if ($storage->isDefault() && $storage->isWritable()) {
                return $storage;
            }
        }
        foreach ($beUser->getFileStorages() as $storage) {
            if ($storage->isWritable()) {
                return $storage;
            }
        }
        return null;
    }
}
