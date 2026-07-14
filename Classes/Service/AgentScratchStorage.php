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

/**
 * Non-public FAL storage for agent-generated binary tool outputs
 * (ViewImage-style ImageContent, screenshots, etc.). Lives outside
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

    private function getStorage(): ResourceStorage
    {
        if ($this->storage instanceof ResourceStorage) {
            return $this->storage;
        }
        $storageUid = $this->findStorageUid();
        if ($storageUid === null) {
            $storageUid = $this->createStorageRecord();
        }
        $storage = $this->storageRepository->findByUid($storageUid);
        if (!$storage instanceof ResourceStorage) {
            throw new \RuntimeException('Agent scratch storage #' . $storageUid . ' could not be loaded.');
        }
        // Bypass BE-user permission checks — the agent runs in a synthesized
        // BE user context and we want binary tool outputs to land regardless
        // of that user's filemount setup. Two independent layers:
        //  1. setEvaluatePermissions(false) — disables the ACL check inside
        //     assureFolderReadPermission()/assureFileAddPermissions().
        //  2. setUserPermissions(r+w) — permits action-level checks that
        //     evaluate the storage's own permission set irrespective of the
        //     BE user's file mounts.
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
        // LocalDriver resolves `pathType=relative` against Environment::getPublicPath()
        // — but we deliberately live OUTSIDE public/. Use an absolute path from
        // Environment::getProjectPath() instead. LocalDriver's isAllowedAbsolutePath
        // permits anything under the project root.
        $absoluteBasePath = $this->getAbsoluteBasePath();
        $configuration = <<<XML
<?xml version="1.0" encoding="utf-8" standalone="yes" ?>
<T3FlexForms>
    <data>
        <sheet index="sDEF">
            <language index="lDEF">
                <field index="basePath">
                    <value index="vDEF">{$absoluteBasePath}</value>
                </field>
                <field index="pathType">
                    <value index="vDEF">absolute</value>
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

    private function ensureBasePathExists(): void
    {
        $absolute = $this->getAbsoluteBasePath();
        if (!is_dir($absolute)) {
            GeneralUtility::mkdir_deep($absolute);
        }
    }

    private function ensureTaskFolder(ResourceStorage $storage, int $taskUid): Folder
    {
        $name = 'task_' . $taskUid;
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
