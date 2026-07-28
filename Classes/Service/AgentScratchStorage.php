<?php

declare(strict_types=1);

namespace Hn\Agent\Service;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Non-public FAL storage for agent-generated binary tool outputs
 * (ReadFile-style ImageContent, screenshots, etc.). Lives outside
 * public/ under var/agent-scratch/ so nothing here is ever web-reachable.
 *
 * Files are content-addressed (sha256) per task subfolder — repeated
 * tool outputs with identical bytes reuse the existing sys_file via a
 * new sys_file_reference rather than duplicating storage.
 *
 * The FAL storage record is self-provisioned on first use, so the
 * extension needs no separate setup step.
 */
class AgentScratchStorage
{
    private const STORAGE_NAME = 'Agent Scratch';
    private const BASE_PATH = 'var/agent-scratch/';

    private ?ResourceStorage $storage = null;
    private ?int $cachedStorageUid = null;

    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly ResourceFactory $resourceFactory,
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Persist binary tool output into the scratch storage and return the
     * sys_file. Content-addressed: identical bytes for the same task
     * return the existing file instead of writing a duplicate.
     */
    public function store(int $taskUid, string $binary, string $mimeType): File
    {
        $storage = $this->getStorage();
        $folder = $this->ensureTaskFolder($storage, $taskUid);

        $hash = hash('sha256', $binary);
        $extension = $this->extensionFor($mimeType);
        $filename = $hash . ($extension !== '' ? '.' . $extension : '');

        if ($storage->hasFileInFolder($filename, $folder)) {
            $existing = $storage->getFileInFolder($filename, $folder);
            if ($existing instanceof File) {
                return $existing;
            }
        }

        $tmp = GeneralUtility::tempnam('agent-scratch-');
        file_put_contents($tmp, $binary);
        try {
            return $storage->addFile($tmp, $folder, $filename, DuplicationBehavior::REPLACE, false);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * True if the file lives in the agent scratch storage. Cheap identity
     * check on the storage UID — no permission touch, no file read.
     */
    public function isScratchFile(File $file): bool
    {
        return $this->isScratchStorageUid($file->getStorage()->getUid());
    }

    /**
     * Cheap identity check on a storage UID. Used both internally and by the
     * AfterResourceStorageInitializationEvent listener that unlocks permissions
     * on this storage for every consumer (incl. Core's /typo3/file_upload).
     */
    public function isScratchStorageUid(int $storageUid): bool
    {
        // Called from AgentScratchStorageInitializer on every storage init —
        // cache the lookup so we don't hit sys_file_storage per query.
        if ($this->cachedStorageUid === null) {
            $this->cachedStorageUid = $this->findStorageUid() ?? 0;
        }
        return $this->cachedStorageUid > 0 && $this->cachedStorageUid === $storageUid;
    }

    /**
     * Combined identifier of a per-BE-user landing folder for chat-composer
     * uploads (e.g. "3:/user_42/"). The DragUploader in the composer receives
     * this via `data-target-folder` and posts uploads straight into the
     * scratch storage instead of fileadmin/user_upload/.
     *
     * We use a user-scoped folder rather than a task-scoped one because the
     * composer is rendered in contexts (chat list, embedded new-task widget)
     * where no task UID exists yet. Content-addressing in store() keeps
     * duplicate uploads from consuming extra space.
     */
    public function ensureUploadIdentifierForUser(int $beUserUid): string
    {
        $storage = $this->getStorage();
        $folder = $this->ensureUserFolder($storage, $beUserUid);
        return $folder->getCombinedIdentifier();
    }

    private function getStorage(): ResourceStorage
    {
        if ($this->storage instanceof ResourceStorage) {
            return $this->storage;
        }
        $storageUid = $this->findStorageUid();
        if ($storageUid === null) {
            $storageUid = $this->createStorageRecord();
            // Fresh record — invalidate the per-instance cache used by
            // isScratchStorageUid() so the event listener recognises it.
            $this->cachedStorageUid = $storageUid;
        }
        $storage = $this->storageRepository->findByUid($storageUid);
        if (!$storage instanceof ResourceStorage) {
            throw new \RuntimeException('Agent scratch storage #' . $storageUid . ' could not be loaded.');
        }
        // Belt-and-suspenders: AgentScratchStorageInitializer applies these
        // overrides globally via AfterResourceStorageInitializationEvent so
        // that Core's /typo3/file_upload endpoint sees the same permissionless
        // storage. Setting them here as well guards against a stale DI cache
        // (right after deployment, before `cache:flush`) where the listener
        // isn't registered yet — the extension's own scratch-storage users
        // (ExtractImages, ReadFile tool outputs) still work.
        $storage->setEvaluatePermissions(false);
        $storage->setUserPermissions(['r' => true, 'w' => true]);
        $this->ensureBasePathExists();
        return $this->storage = $storage;
    }

    private function findStorageUid(): ?int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_storage');
        $qb->getRestrictions()->removeAll();
        $row = $qb
            ->select('uid')
            ->from('sys_file_storage')
            ->where(
                $qb->expr()->eq('name', $qb->createNamedParameter(self::STORAGE_NAME)),
                $qb->expr()->eq('deleted', 0),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        return $row ? (int)$row['uid'] : null;
    }

    private function createStorageRecord(): int
    {
        $this->ensureBasePathExists();
        // Use `pathType=relative` so the storage survives release-based
        // deployments where the project root changes on every deploy — an
        // absolute path would keep pointing at the old release directory and
        // take the storage (and everything touching it) offline. LocalDriver
        // resolves relative paths against Environment::getPublicPath(), so a
        // `../`-prefixed path reaches var/agent-scratch/ outside public/;
        // after canonicalization it is still within the project root, which
        // satisfies LocalDriver's isAllowedAbsolutePath check.
        $basePath = $this->getRelativeBasePath();
        $pathType = 'relative';
        if ($basePath === null) {
            // Public path is not inside the project path (exotic setup) — a
            // relative path cannot be computed; fall back to absolute.
            $basePath = $this->getAbsoluteBasePath();
            $pathType = 'absolute';
        }
        $configuration = <<<XML
<?xml version="1.0" encoding="utf-8" standalone="yes" ?>
<T3FlexForms>
    <data>
        <sheet index="sDEF">
            <language index="lDEF">
                <field index="basePath">
                    <value index="vDEF">{$basePath}</value>
                </field>
                <field index="pathType">
                    <value index="vDEF">{$pathType}</value>
                </field>
                <field index="caseSensitive">
                    <value index="vDEF">1</value>
                </field>
            </language>
        </sheet>
    </data>
</T3FlexForms>
XML;

        $now = time();
        $connection = $this->connectionPool->getConnectionForTable('sys_file_storage');
        $connection->insert(
            'sys_file_storage',
            [
                'pid' => 0,
                'tstamp' => $now,
                'crdate' => $now,
                'name' => self::STORAGE_NAME,
                'description' => 'Non-public storage for agent tool outputs (var/agent-scratch/).',
                'driver' => 'Local',
                'configuration' => $configuration,
                'is_default' => 0,
                // is_browsable MUST be 1 — ResourceStorage::checkFolderActionPermission()
                // treats it as a storage capability and blocks *all* folder reads
                // (hasFolderInFolder, getFolderInFolder, addFile) when it's 0,
                // regardless of setEvaluatePermissions(). is_public=0 already
                // guarantees files are not web-reachable.
                'is_browsable' => 1,
                'is_public' => 0,
                'is_writable' => 1,
                'is_online' => 1,
                'auto_extract_metadata' => 0,
                'processingfolder' => '',
            ],
        );
        return (int)$connection->lastInsertId();
    }

    private function getAbsoluteBasePath(): string
    {
        return Environment::getProjectPath() . '/' . rtrim(self::BASE_PATH, '/') . '/';
    }

    /**
     * Base path relative to Environment::getPublicPath(), e.g.
     * "../var/agent-scratch/" in composer mode. Null when public path and
     * project path share no usable common prefix.
     */
    private function getRelativeBasePath(): ?string
    {
        return PathUtility::getRelativePath(Environment::getPublicPath() . '/', $this->getAbsoluteBasePath());
    }

    private function ensureBasePathExists(): void
    {
        $absolute = $this->getAbsoluteBasePath();
        if (!is_dir($absolute)) {
            GeneralUtility::mkdir_deep($absolute);
        }
    }

    private function ensureTaskFolder(ResourceStorage $storage, int $taskUid): Folder
    {
        return $this->ensureFolder($storage, 'task_' . $taskUid);
    }

    private function ensureUserFolder(ResourceStorage $storage, int $beUserUid): Folder
    {
        return $this->ensureFolder($storage, 'user_' . $beUserUid);
    }

    private function ensureFolder(ResourceStorage $storage, string $name): Folder
    {
        // respectFileMounts=false — the agent-scratch storage is intentionally
        // outside any file mount, and the synthesized BE user has none set anyway.
        $rootFolder = $storage->getRootLevelFolder(false);
        if ($storage->hasFolderInFolder($name, $rootFolder)) {
            return $storage->getFolderInFolder($name, $rootFolder);
        }
        return $storage->createFolder($name, $rootFolder);
    }

    private function extensionFor(string $mimeType): string
    {
        $mime = strtolower(trim($mimeType));
        return match ($mime) {
            'image/png' => 'png',
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/svg+xml' => 'svg',
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            'text/html' => 'html',
            'application/json' => 'json',
            default => 'bin',
        };
    }
}
