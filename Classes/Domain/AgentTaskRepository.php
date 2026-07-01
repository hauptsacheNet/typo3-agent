<?php

declare(strict_types=1);

namespace Hn\Agent\Domain;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;

class AgentTaskRepository
{
    private const TABLE = 'tx_agent_task';
    private const CHANGE_TABLE = 'tx_agent_task_change';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Find a task by UID (no restriction enforcement — caller decides access).
     */
    public function findByUid(int $uid): array|false
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid)))
            ->executeQuery()
            ->fetchAssociative();
    }

    /**
     * Find a task by UID, restricted to a specific backend user (admins see all).
     */
    public function findByUidForUser(int $uid, int $userId, bool $isAdmin): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, ParameterType::INTEGER)));

        if (!$isAdmin) {
            $qb->andWhere($qb->expr()->eq('cruser_id', $qb->createNamedParameter($userId, ParameterType::INTEGER)));
        }

        $row = $qb->executeQuery()->fetchAssociative();
        return $row !== false ? $row : null;
    }

    /**
     * Load tasks for a backend user, restricted to one or more page UIDs.
     * Empty $pageIds yields an empty result (no query is dispatched).
     *
     * @param int[] $pageIds
     * @return array<int, array{uid:int,pid:int,title:string,status:int,tstamp:int,crdate:int,pageTitle:string}>
     */
    public function findTasksForUser(int $userId, array $pageIds, int $limit = 500): array
    {
        if ($pageIds === []) {
            return [];
        }
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $tasks = $qb
            ->select('uid', 'pid', 'title', 'status', 'tstamp', 'crdate')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('cruser_id', $qb->createNamedParameter($userId, ParameterType::INTEGER)),
                $qb->expr()->in('pid', $qb->createNamedParameter($pageIds, \Doctrine\DBAL\ArrayParameterType::INTEGER)),
            )
            ->orderBy('tstamp', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        $titleCache = [];
        foreach ($tasks as &$task) {
            $pid = (int)$task['pid'];
            if (!array_key_exists($pid, $titleCache)) {
                $pageRecord = BackendUtility::getRecord('pages', $pid);
                $titleCache[$pid] = $pageRecord !== null
                    ? BackendUtility::getRecordTitle('pages', $pageRecord)
                    : 'Seite #' . $pid;
            }
            $task['pageTitle'] = $titleCache[$pid];
        }
        unset($task);
        return $tasks;
    }

    /**
     * Find pending tasks for batch processing.
     *
     * @return array<int, array{uid: int, title: string}>
     */
    public function findPendingTasks(int $limit = 10): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return $qb
            ->select('uid', 'title')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('status', $qb->createNamedParameter(TaskStatus::Pending->value)),
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->eq('hidden', 0),
            )
            ->orderBy('crdate', 'ASC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Lease-Akquise: atomar von jedem inaktiven Status nach InProgress.
     * CAS-Prädikat `status != InProgress` verhindert, dass zwei Prozesse
     * denselben Task gleichzeitig bearbeiten. Gibt true zurück, wenn die
     * Lease vergeben wurde.
     */
    public function claim(int $taskUid): bool
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $affected = $qb
            ->update(self::TABLE)
            ->set('status', TaskStatus::InProgress->value, true, ParameterType::INTEGER)
            ->set('tstamp', time(), true, ParameterType::INTEGER)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($taskUid, ParameterType::INTEGER)),
                $qb->expr()->neq('status', $qb->createNamedParameter(TaskStatus::InProgress->value, ParameterType::INTEGER)),
            )
            ->executeStatement();
        return $affected > 0;
    }

    /**
     * Insert a new task and return its UID.
     *
     * @param array<int, array<string, mixed>>|null $initialMessages
     *        Pre-built initial conversation (system + synthetic context + user).
     *        Persisted as JSON so AgentService::run() resumes from it on
     *        auto-start instead of synthesizing.
     */
    public function insert(int $pid, int $cruserId, string $title, string $prompt, string $contextTable = '', int $contextUid = 0, string $returnUrl = '', int $workspaceId = 0, ?array $initialMessages = null): int
    {
        $now = time();
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(
            self::TABLE,
            [
                'pid' => $pid,
                'cruser_id' => $cruserId,
                'tstamp' => $now,
                'crdate' => $now,
                'title' => $title,
                'prompt' => $prompt,
                'status' => TaskStatus::Pending->value,
                'messages' => $initialMessages,
                'result' => '',
                'context_table' => $contextTable,
                'context_uid' => $contextUid,
                'return_url' => $returnUrl,
                'workspace_id' => $workspaceId,
            ],
            $initialMessages !== null ? ['messages' => Types::JSON] : [],
        );
        return (int)$connection->lastInsertId();
    }

    /**
     * Persist only the messages array, without touching status. Used for the
     * mid-loop checkpoints in AgentService::runLoop — those must not clobber
     * a status transition done by another request (e.g. an external cancel
     * flipping status from InProgress to Cancelled).
     */
    public function saveMessages(int $taskUid, array $messages): void
    {
        $this->connectionPool
            ->getConnectionForTable(self::TABLE)
            ->update(
                self::TABLE,
                ['messages' => $messages, 'tstamp' => time()],
                ['uid' => $taskUid],
                ['messages' => Types::JSON],
            );
    }

    /**
     * Persistiert das Terminal-Result als Denormalisierung der letzten
     * Assistant-Nachricht (bzw. eines Fehlertexts). Peer zu saveMessages,
     * keine Statusänderung — Aufrufer schreibt vor dem State-Machine-Trigger.
     */
    public function saveResult(int $taskUid, string $result): void
    {
        $this->connectionPool
            ->getConnectionForTable(self::TABLE)
            ->update(
                self::TABLE,
                ['result' => $result, 'tstamp' => time()],
                ['uid' => $taskUid],
            );
    }

    /**
     * Atomarer Terminal-Statuswechsel: CAS von `status = InProgress` auf
     * `$to`, optional mit zusätzlichen WHERE-Kriterien (etwa `cruser_id`
     * für die Cancel-ACL). Wird ausschließlich von TaskStateMachine
     * aufgerufen. Kein Overwrite bei zwischenzeitlich extern gesetztem
     * Cancel/Ended/Failed — genau das war die Rolle des alten finalize().
     *
     * @param array<string, int|string> $extraCriteria zusätzliche WHERE-Klauseln
     */
    public function casFromInProgress(int $taskUid, TaskStatus $to, array $extraCriteria = []): bool
    {
        $criteria = [
            'uid' => $taskUid,
            'status' => TaskStatus::InProgress->value,
        ] + $extraCriteria;

        $affected = $this->connectionPool
            ->getConnectionForTable(self::TABLE)
            ->update(
                self::TABLE,
                ['status' => $to->value, 'tstamp' => time()],
                $criteria,
            );
        return $affected > 0;
    }

    /**
     * Record a workspace change associated with a task.
     */
    public function addChange(int $taskUid, string $tablename, int $recordUid, int $workspaceRecordUid, int $pageId, int $workspacePageId): void
    {
        $this->connectionPool
            ->getConnectionForTable(self::CHANGE_TABLE)
            ->insert(self::CHANGE_TABLE, [
                'task_uid' => $taskUid,
                'tablename' => $tablename,
                'record_uid' => $recordUid,
                'workspace_record_uid' => $workspaceRecordUid,
                'page_id' => $pageId,
                'workspace_page_id' => $workspacePageId,
            ]);
    }

    /**
     * Get all tracked changes for a task.
     *
     * @return array<int, array{task_uid: int, tablename: string, record_uid: int, workspace_record_uid: int}>
     */
    public function getChanges(int $taskUid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::CHANGE_TABLE);
        return $qb
            ->select('*')
            ->from(self::CHANGE_TABLE)
            ->where($qb->expr()->eq('task_uid', $qb->createNamedParameter($taskUid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Remove tracked changes matching a table and record UID.
     * Matches against both record_uid and workspace_record_uid since the
     * provided UID may refer to either the live or the workspace version.
     */
    public function removeChangeByRecord(string $tablename, int $uid): void
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::CHANGE_TABLE);
        $qb->delete(self::CHANGE_TABLE)
            ->where(
                $qb->expr()->eq('tablename', $qb->createNamedParameter($tablename)),
                $qb->expr()->or(
                    $qb->expr()->eq('record_uid', $qb->createNamedParameter($uid, ParameterType::INTEGER)),
                    $qb->expr()->eq('workspace_record_uid', $qb->createNamedParameter($uid, ParameterType::INTEGER)),
                ),
            )
            ->executeStatement();
    }
}
