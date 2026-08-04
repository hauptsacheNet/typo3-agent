<?php

declare(strict_types=1);

namespace Hn\Agent\EventListener;

use Hn\Agent\Service\ScratchFilePromotionService;
use Hn\McpServer\Event\BeforeRecordWriteEvent;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Safety net for the scratch storage: whenever a WriteTable call references
 * a sys_file that still lives in the non-public agent scratch storage
 * (chat-composer uploads, ExtractImages output), the file is promoted into
 * a public FAL folder automatically and the write is rewritten to point at
 * the promoted copy.
 *
 * The system prompt instructs the LLM to call PromoteScratchFile itself,
 * but smaller models skip that step reliably — and a sys_file_reference
 * into the scratch storage 404s in the frontend. This listener makes the
 * invariant hold regardless of model discipline (the behavior the README
 * promises: promotion "happens automatically when the agent references
 * the file in a record").
 *
 * References on tx_agent_message are exempt — those ARE the chat-history
 * previews the scratch storage exists for.
 *
 * If promotion fails (no resolvable target folder), the write is vetoed
 * with the reason so the LLM sees an actionable error instead of silently
 * shipping a broken reference.
 */
#[AsEventListener('agent/scratch-file-write-guard')]
final class ScratchFileWriteGuard implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly ScratchFilePromotionService $promotionService,
        private readonly ResourceFactory $resourceFactory,
    ) {}

    public function __invoke(BeforeRecordWriteEvent $event): void
    {
        if (!in_array($event->getAction(), ['create', 'update'], true)) {
            return;
        }

        $data = $event->getData();
        $changed = false;

        if ($event->getTable() === 'sys_file_reference') {
            // Direct reference write. tx_agent_message references are the
            // chat-history previews — scratch is exactly where they belong.
            if (($data['tablenames'] ?? '') !== 'tx_agent_message' && isset($data['uid_local'])) {
                $promoted = $this->promoteIfScratch($data['uid_local'], $event);
                if ($promoted !== null) {
                    $data['uid_local'] = $promoted;
                    $changed = true;
                }
            }
        } else {
            // Inline child records: image: [{uid_local: N, ...}], assets: […]
            foreach ($data as $field => $value) {
                if (!is_array($value)) {
                    continue;
                }
                foreach ($value as $key => $item) {
                    if (!is_array($item) || !isset($item['uid_local'])) {
                        continue;
                    }
                    $promoted = $this->promoteIfScratch($item['uid_local'], $event);
                    if ($promoted !== null) {
                        $data[$field][$key]['uid_local'] = $promoted;
                        $changed = true;
                    }
                }
            }
        }

        if ($changed && !$event->isVetoed()) {
            $event->setData($data);
        }
    }

    /**
     * Returns the promoted sys_file UID when $uidLocal points into the
     * scratch storage, null when nothing needs to change. Vetoes the event
     * when promotion is needed but impossible.
     */
    private function promoteIfScratch(mixed $uidLocal, BeforeRecordWriteEvent $event): ?int
    {
        $uid = (int)$uidLocal;
        if ($uid <= 0) {
            return null;
        }

        try {
            $file = $this->resourceFactory->getFileObject($uid);
        } catch (\Throwable) {
            // Unknown uid — leave it to WriteTable's own validation.
            return null;
        }
        if (!$file instanceof File || !$this->promotionService->isScratchFile($file)) {
            return null;
        }

        try {
            $promoted = $this->promotionService->promote($file);
        } catch (\Throwable $e) {
            $event->veto(sprintf(
                'sys_file:%d liegt im nicht-öffentlichen Agent-Scratch-Storage und wäre im Frontend nicht erreichbar. '
                    . 'Automatisches Promoten ist fehlgeschlagen (%s) — erst PromoteScratchFile mit explizitem target_folder aufrufen '
                    . 'und die zurückgegebene sys_file UID als uid_local verwenden.',
                $uid,
                $e->getMessage(),
            ));
            return null;
        }

        $this->logger?->info('Auto-promoted scratch file referenced in record write', [
            'table' => $event->getTable(),
            'scratchFile' => $uid,
            'promotedFile' => $promoted->getUid(),
            'target' => $promoted->getCombinedIdentifier(),
        ]);
        return $promoted->getUid();
    }
}
