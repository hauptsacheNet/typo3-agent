<?php

declare(strict_types=1);

namespace Hn\Agent\Hook;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Hn\Agent\Domain\AgentTaskRepository;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class DataHandlerHook
{
    /**
     * Stamp tasks created through DataHandler (List module, record forms)
     * with their creator and the creator's current workspace.
     *
     * TYPO3 >= 12 no longer fills cruser_id, and the agent refuses tasks
     * without an owner (see AgentService::run), so without this a task created
     * in the List module could never run. The chat module inserts tasks via
     * AgentTaskRepository::insert() and sets both fields itself.
     */
    public function processDatamap_afterDatabaseOperations(string $status, string $table, string|int $id, array $fieldArray, DataHandler $dataHandler): void
    {
        if ($table !== 'tx_agent_task' || $status !== 'new') {
            return;
        }
        $uid = (int)($dataHandler->substNEWwithIDs[$id] ?? 0);
        $userId = (int)($dataHandler->BE_USER->user['uid'] ?? 0);
        if ($uid <= 0 || $userId <= 0) {
            return;
        }

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_agent_task');
        $connection->update(
            'tx_agent_task',
            ['cruser_id' => $userId],
            ['uid' => $uid, 'cruser_id' => 0],
        );
        $workspaceId = (int)$dataHandler->BE_USER->workspace;
        if ($workspaceId > 0) {
            $connection->update(
                'tx_agent_task',
                ['workspace_id' => $workspaceId],
                ['uid' => $uid, 'workspace_id' => 0],
            );
        }
    }

    public function processCmdmap_discardAction(string $table, int $uid, array $record, bool &$recordWasDiscarded): void
    {
        GeneralUtility::makeInstance(AgentTaskRepository::class)
            ->removeChangeByRecord($table, $uid);
    }

    /**
     * Preemptively soft-delete sys_file_reference rows attached to a task's
     * messages before DataHandler's own IRRE cascade reaches them.
     *
     * Rationale: sys_file_reference has versioningWS=true; tx_agent_task /
     * tx_agent_message do not. Deleting a task while the BE user sits in a
     * non-Live workspace would otherwise produce Delete-Placeholder versions
     * on the refs (published only on workspace publish) while the parents are
     * already soft-deleted on Live. TCA offers no clean per-relation opt-out;
     * marking the refs deleted up-front makes DataHandler's cascade query
     * (deleted=0) skip them, so no overlays are created.
     */
    public function processCmdmap_deleteAction(string $table, int $uid, array $record, ?bool &$recordWasDeleted, DataHandler $dataHandler): void
    {
        if ($table !== 'tx_agent_task' || $uid <= 0) {
            return;
        }

        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);

        $msgQb = $connectionPool->getQueryBuilderForTable('tx_agent_message');
        $msgQb->getRestrictions()->removeAll();
        $messageUids = array_map(
            static fn(array $r) => (int)$r['uid'],
            $msgQb
                ->select('uid')
                ->from('tx_agent_message')
                ->where(
                    $msgQb->expr()->eq('task', $msgQb->createNamedParameter($uid, ParameterType::INTEGER)),
                    $msgQb->expr()->eq('deleted', 0),
                )
                ->executeQuery()
                ->fetchAllAssociative(),
        );

        if ($messageUids === []) {
            return;
        }

        $refQb = $connectionPool->getQueryBuilderForTable('sys_file_reference');
        $refQb->update('sys_file_reference')
            ->set('deleted', 1, true, ParameterType::INTEGER)
            ->set('tstamp', time(), true, ParameterType::INTEGER)
            ->where(
                $refQb->expr()->eq('tablenames', $refQb->createNamedParameter('tx_agent_message')),
                $refQb->expr()->eq('fieldname', $refQb->createNamedParameter('attachments')),
                $refQb->expr()->in('uid_foreign', $refQb->createNamedParameter($messageUids, ArrayParameterType::INTEGER)),
                $refQb->expr()->eq('deleted', 0),
            )
            ->executeStatement();
    }
}
