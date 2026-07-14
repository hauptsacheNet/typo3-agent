<?php

declare(strict_types=1);

namespace Hn\Agent\Domain;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * CRUD for tx_agent_message. Messages are the source of truth for the
 * chat history — the JSON `messages` column on tx_agent_task is gone.
 *
 * Attachments are persisted as real sys_file_reference rows via the
 * TCA `file` relation on `attachments` (tablenames=tx_agent_message,
 * fieldname=attachments). Loading a message projects the references
 * back into a virtual `attachments` list of sys_file UIDs, matching
 * the in-memory shape that AgentService's loop consumes.
 */
class AgentMessageRepository
{
    private const TABLE = 'tx_agent_message';
    private const REFERENCE_TABLE = 'sys_file_reference';
    private const REFERENCE_FIELD = 'attachments';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Load all messages of a task in insertion order.
     *
     * @return list<array<string, mixed>>
     */
    public function findByTask(int $taskUid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $qb
            ->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('task', $qb->createNamedParameter($taskUid, ParameterType::INTEGER)))
            ->orderBy('sorting', 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        if ($rows === []) {
            return [];
        }

        $attachmentsByMessage = $this->loadAttachmentUidsFor(array_map(static fn(array $r) => (int)$r['uid'], $rows));

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->hydrate($row, $attachmentsByMessage[(int)$row['uid']] ?? []);
        }
        return $out;
    }

    /**
     * Append a message. `$attachmentFileUids` are sys_file UIDs that
     * should be linked as sys_file_reference rows on the new message.
     * Returns the new message UID.
     *
     * @param array<string, mixed> $message
     * @param list<int> $attachmentFileUids
     */
    public function append(int $taskUid, int $pid, array $message, array $attachmentFileUids = []): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $now = time();

        $sorting = $this->nextSorting($taskUid);
        $toolCalls = $message['tool_calls'] ?? null;
        $data = [
            'pid' => $pid,
            'tstamp' => $now,
            'crdate' => $now,
            'sorting' => $sorting,
            'task' => $taskUid,
            'role' => (string)($message['role'] ?? ''),
            'content' => $this->stringifyContent($message['content'] ?? null),
            'reasoning' => (string)($message['reasoning'] ?? ''),
            'tool_calls' => is_array($toolCalls) && $toolCalls !== [] ? $toolCalls : null,
            'tool_call_id' => (string)($message['tool_call_id'] ?? ''),
            'tool_name' => (string)($message['tool_name'] ?? ''),
            'attachments' => 0,
        ];
        $types = ['tool_calls' => Types::JSON];

        $connection->insert(self::TABLE, $data, $types);
        $messageUid = (int)$connection->lastInsertId();

        if ($attachmentFileUids !== []) {
            $count = $this->createFileReferences($messageUid, $pid, $attachmentFileUids);
            if ($count > 0) {
                $connection->update(
                    self::TABLE,
                    ['attachments' => $count],
                    ['uid' => $messageUid],
                );
            }
        }

        return $messageUid;
    }

    private function nextSorting(int $taskUid): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $row = $qb
            ->select('sorting')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('task', $qb->createNamedParameter($taskUid, ParameterType::INTEGER)))
            ->orderBy('sorting', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        $current = $row !== false ? (int)$row['sorting'] : 0;
        return $current + 256;
    }

    /**
     * @param list<int> $messageUids
     * @return array<int, list<int>>
     */
    private function loadAttachmentUidsFor(array $messageUids): array
    {
        if ($messageUids === []) {
            return [];
        }
        $qb = $this->connectionPool->getQueryBuilderForTable(self::REFERENCE_TABLE);
        $qb->getRestrictions()->removeAll();
        $rows = $qb
            ->select('uid_foreign', 'uid_local')
            ->from(self::REFERENCE_TABLE)
            ->where(
                $qb->expr()->eq('tablenames', $qb->createNamedParameter(self::TABLE)),
                $qb->expr()->eq('fieldname', $qb->createNamedParameter(self::REFERENCE_FIELD)),
                $qb->expr()->in('uid_foreign', $qb->createNamedParameter($messageUids, ArrayParameterType::INTEGER)),
                $qb->expr()->eq('deleted', 0),
            )
            ->orderBy('sorting_foreign', 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $out = [];
        foreach ($rows as $row) {
            $out[(int)$row['uid_foreign']][] = (int)$row['uid_local'];
        }
        return $out;
    }

    /**
     * @param list<int> $fileUids
     * @return int Number of references successfully created.
     */
    private function createFileReferences(int $messageUid, int $pid, array $fileUids): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::REFERENCE_TABLE);
        $now = time();
        $created = 0;
        $sorting = 0;
        foreach ($fileUids as $fileUid) {
            $fileUid = (int)$fileUid;
            if ($fileUid <= 0) {
                continue;
            }
            $sorting += 256;
            $connection->insert(
                self::REFERENCE_TABLE,
                [
                    'pid' => $pid,
                    'tstamp' => $now,
                    'crdate' => $now,
                    'uid_local' => $fileUid,
                    'uid_foreign' => $messageUid,
                    'tablenames' => self::TABLE,
                    'fieldname' => self::REFERENCE_FIELD,
                    'sorting_foreign' => $sorting,
                ],
            );
            $created++;
        }
        return $created;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<int> $attachmentUids
     * @return array<string, mixed>
     */
    private function hydrate(array $row, array $attachmentUids): array
    {
        $out = [
            'uid' => (int)$row['uid'],
            'role' => (string)($row['role'] ?? ''),
            'content' => (string)($row['content'] ?? ''),
        ];

        $reasoning = (string)($row['reasoning'] ?? '');
        if ($reasoning !== '') {
            $out['reasoning'] = $reasoning;
        }

        $toolCalls = $row['tool_calls'] ?? null;
        if (is_string($toolCalls) && $toolCalls !== '' && $toolCalls !== 'null') {
            $toolCalls = json_decode($toolCalls, true);
        }
        if (is_array($toolCalls) && $toolCalls !== []) {
            $out['tool_calls'] = $toolCalls;
        }

        $toolCallId = (string)($row['tool_call_id'] ?? '');
        if ($toolCallId !== '') {
            $out['tool_call_id'] = $toolCallId;
        }
        $toolName = (string)($row['tool_name'] ?? '');
        if ($toolName !== '') {
            $out['tool_name'] = $toolName;
        }

        if ($attachmentUids !== []) {
            $out['attachments'] = array_values(array_unique($attachmentUids));
        }
        return $out;
    }

    private function stringifyContent(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if ($content === null) {
            return '';
        }
        return (string)json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
