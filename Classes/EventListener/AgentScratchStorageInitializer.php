<?php

declare(strict_types=1);

namespace Hn\Agent\EventListener;

use Hn\Agent\Service\AgentScratchStorage;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Resource\Event\AfterResourceStorageInitializationEvent;

/**
 * Unlocks the agent scratch storage for every consumer that loads it via
 * StorageRepository — most importantly Core's /typo3/file_upload endpoint,
 * which would otherwise refuse uploads because the storage is not part of
 * the current BE user's filemounts.
 *
 * MUST run after Core's `backend-user-permissions` listener
 * (StoragePermissionsAspect): for non-admin BE users, that Core listener
 * calls setEvaluatePermissions(true) and applies filemount restrictions,
 * which would otherwise clobber our overrides. Chaining via `after` in
 * TYPO3's AsEventListener guarantees the order.
 */
#[AsEventListener(
    identifier: 'agent.scratchStorageInitializer',
    after: 'backend-user-permissions',
)]
final class AgentScratchStorageInitializer
{
    public function __construct(
        private readonly AgentScratchStorage $scratch,
    ) {}

    public function __invoke(AfterResourceStorageInitializationEvent $event): void
    {
        $storage = $event->getStorage();
        if (!$this->scratch->isScratchStorageUid($storage->getUid())) {
            return;
        }
        $storage->setEvaluatePermissions(false);
        $storage->setUserPermissions(['r' => true, 'w' => true]);
    }
}
