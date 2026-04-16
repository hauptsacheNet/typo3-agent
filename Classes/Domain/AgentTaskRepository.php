<?php

declare(strict_types=1);

namespace Hn\Agent\Domain;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use TYPO3\CMS\Core\Database\ConnectionPool;

class AgentTaskRepository
{
    private const TABLE = 'tx_agent_task';

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
     * Load chat list for a backend user, ordered by last update.
     * When $pid > 0, only chats on that page are returned.
     *
     * @return array<int, array{uid: int, title: string, status: int, tstamp: int, crdate: int}>
     */
    public function findChatsForUser(int $userId, int $pid = 0, int $limit = 100): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->select('uid', 'title', 'status', 'tstamp', 'crdate')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('cruser_id', $qb->createNamedParameter($userId, ParameterType::INTEGER)))
            ->orderBy('tstamp', 'DESC')
            ->setMaxResults($limit);

        if ($pid > 0) {
            $qb->andWhere($qb->expr()->eq('pid', $qb->createNamedParameter($pid, ParameterType::INTEGER)));
        }

        return $qb->executeQuery()->fetchAllAssociative();
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
     * Atomically claim a task by setting status to InProgress only if current status matches.
     * Prevents race conditions when multiple processes run concurrently.
     */
    public function claim(int $taskUid, TaskStatus $expectedStatus): bool
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $affectedRows = $connection->update(
            self::TABLE,
            ['status' => TaskStatus::InProgress->value, 'tstamp' => time()],
            ['uid' => $taskUid, 'status' => $expectedStatus->value],
        );
        return $affectedRows > 0;
    }

    /**
     * Insert a new task and return its UID.
     */
    public function insert(int $pid, int $cruserId, string $title, string $prompt): int
    {
        $now = time();
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'pid' => $pid,
            'cruser_id' => $cruserId,
            'tstamp' => $now,
            'crdate' => $now,
            'title' => $title,
            'prompt' => $prompt,
            'status' => TaskStatus::Pending->value,
            'messages' => null,
            'result' => '',
        ]);
        return (int)$connection->lastInsertId();
    }

    /**
     * Save task state (messages, status, result) to the database.
     */
    public function saveState(int $taskUid, ?array $messages, TaskStatus $status, ?string $result = null): void
    {
        $data = [
            'status' => $status->value,
            'tstamp' => time(),
        ];

        if ($messages !== null) {
            $data['messages'] = $messages;
        }

        if ($result !== null) {
            $data['result'] = $result;
        }

        $types = [];
        if (array_key_exists('messages', $data)) {
            $types['messages'] = Types::JSON;
        }

        $this->connectionPool
            ->getConnectionForTable(self::TABLE)
            ->update(self::TABLE, $data, ['uid' => $taskUid], $types);
    }
}
