<?php

declare(strict_types=1);

namespace Hn\Agent\Updates;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\SmallIntType;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\ChattyInterface;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

/**
 * Converts the legacy `tx_agent_task.messages` JSON column (the complete
 * conversation as one JSON array) into individual tx_agent_message records.
 *
 * The new counter column is named `chat_messages` precisely so the schema
 * migrator never touches the legacy column: it merely reports `messages` as
 * unused (or renames it to `zzz_deleted_messages` when the admin confirms
 * the cleanup). This wizard therefore looks for both names, migrates the
 * content, and NULLs the legacy value afterwards so the column can be
 * dropped safely.
 *
 * Mapping notes (old JSON message → tx_agent_message row):
 *  - `attachments` used to be a list of ref objects ({uid, identifier,
 *    name, mime_type} or {name, unresolvable}); they become real
 *    sys_file_reference rows on the message. Unresolvable refs and refs
 *    whose sys_file no longer exists are dropped (they could not be
 *    displayed before the migration either).
 *  - Tool results with multimodal block content ([{type:text},
 *    {type:image_url,…base64…}]) are flattened to their text parts; inline
 *    base64 images are replaced by a short marker since they have no
 *    sys_file identity to reference.
 *  - `tool_name` was not persisted per tool message before; it is derived
 *    from the preceding assistant message's tool_calls (id → function.name).
 */
#[UpgradeWizard('agent_migrateTaskMessages')]
final class MigrateTaskMessagesUpdateWizard implements UpgradeWizardInterface, ChattyInterface
{
    private const TASK_TABLE = 'tx_agent_task';
    private const MESSAGE_TABLE = 'tx_agent_message';
    private const REFERENCE_TABLE = 'sys_file_reference';
    private const COUNTER_COLUMN = 'chat_messages';

    /**
     * Names the legacy JSON column can have: as-is, or after the admin ran
     * the "remove unused fields" rename step of the database analyzer.
     */
    private const LEGACY_COLUMNS = ['messages', 'zzz_deleted_messages'];

    private ?OutputInterface $output = null;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function setOutput(OutputInterface $output): void
    {
        $this->output = $output;
    }

    public function getTitle(): string
    {
        return 'Migrate agent task chat histories to tx_agent_message records';
    }

    public function getDescription(): string
    {
        return 'Earlier versions stored the whole conversation of an agent task as a JSON array in '
            . 'tx_agent_task.messages. This wizard converts that column (also found as zzz_deleted_messages '
            . 'after a schema cleanup) into individual tx_agent_message records with sys_file_reference '
            . 'attachments, fills the new chat_messages counter and empties the legacy column so it can be '
            . 'removed afterwards.';
    }

    public function getPrerequisites(): array
    {
        return [
            DatabaseUpdatedPrerequisite::class,
        ];
    }

    public function updateNecessary(): bool
    {
        $column = $this->findLegacyColumn();
        return $column !== null && $this->countPendingTasks($column) > 0;
    }

    public function executeUpdate(): bool
    {
        $column = $this->findLegacyColumn();
        if ($column === null) {
            return true;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable(self::TASK_TABLE);
        $qb->getRestrictions()->removeAll();
        // Fetch all rows up front — the loop below writes on the same
        // connection, which must not happen while a result set is open.
        $rows = $qb
            ->select('uid', 'pid', 'deleted', $column)
            ->from(self::TASK_TABLE)
            ->where(...$this->legacyValueNotEmpty($qb, $column))
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $migrated = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            if ($this->migrateTask($row, $column)) {
                $migrated++;
            } else {
                $skipped++;
            }
        }

        $this->say(sprintf('Migrated %d task(s), skipped %d.', $migrated, $skipped));
        return true;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function migrateTask(array $row, string $column): bool
    {
        $taskUid = (int)$row['uid'];
        $messages = json_decode((string)$row[$column], true);
        if (!is_array($messages) || $messages === []) {
            $this->say(sprintf(
                'Task #%d: legacy messages value is not a JSON array — left untouched for manual inspection.',
                $taskUid,
            ));
            return false;
        }

        if ($this->countExistingMessages($taskUid) > 0) {
            // The task already has child records (e.g. it was used after the
            // upgrade). Do not create duplicates and keep the legacy value
            // for manual comparison.
            $this->say(sprintf(
                'Task #%d: tx_agent_message records already exist — legacy JSON kept, nothing migrated.',
                $taskUid,
            ));
            return false;
        }

        $pid = (int)$row['pid'];
        $deleted = (int)$row['deleted'];
        $toolNameByCallId = $this->buildToolNameMap($messages);

        $connection = $this->connectionPool->getConnectionForTable(self::MESSAGE_TABLE);
        $connection->beginTransaction();
        try {
            $now = time();
            $sorting = 0;
            $count = 0;
            foreach ($messages as $message) {
                if (!is_array($message)) {
                    continue;
                }
                $sorting += 256;
                $role = (string)($message['role'] ?? '');
                $toolCallId = (string)($message['tool_call_id'] ?? '');
                $toolCalls = $message['tool_calls'] ?? null;
                $toolName = (string)($message['tool_name'] ?? '');
                if ($toolName === '' && $role === 'tool' && $toolCallId !== '') {
                    $toolName = $toolNameByCallId[$toolCallId] ?? '';
                }

                $connection->insert(
                    self::MESSAGE_TABLE,
                    [
                        'pid' => $pid,
                        'tstamp' => $now,
                        'crdate' => $now,
                        'deleted' => $deleted,
                        'sorting' => $sorting,
                        'task' => $taskUid,
                        'role' => $role,
                        'content' => $this->flattenContent($message['content'] ?? null),
                        'reasoning' => (string)($message['reasoning'] ?? ''),
                        'tool_calls' => is_array($toolCalls) && $toolCalls !== [] ? $toolCalls : null,
                        'tool_call_id' => $toolCallId,
                        'tool_name' => $toolName,
                        'attachments' => 0,
                    ],
                    ['tool_calls' => Types::JSON],
                );
                $messageUid = (int)$connection->lastInsertId();
                $count++;

                $fileUids = $this->extractAttachmentFileUids($message['attachments'] ?? null);
                if ($fileUids !== []) {
                    $referenceCount = $this->createFileReferences($messageUid, $pid, $deleted, $fileUids);
                    if ($referenceCount > 0) {
                        $connection->update(
                            self::MESSAGE_TABLE,
                            ['attachments' => $referenceCount],
                            ['uid' => $messageUid],
                        );
                    }
                }
            }

            $this->connectionPool->getConnectionForTable(self::TASK_TABLE)->update(
                self::TASK_TABLE,
                [$column => null, self::COUNTER_COLUMN => $count],
                ['uid' => $taskUid],
            );

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        return true;
    }

    /**
     * Old assistant messages carry tool_calls [{id, function: {name}}];
     * old tool messages only carry tool_call_id. Rebuild the id → name map
     * so migrated tool messages get a proper tool_name.
     *
     * @param list<mixed> $messages
     * @return array<string, string>
     */
    private function buildToolNameMap(array $messages): array
    {
        $map = [];
        foreach ($messages as $message) {
            if (!is_array($message) || !is_array($message['tool_calls'] ?? null)) {
                continue;
            }
            foreach ($message['tool_calls'] as $toolCall) {
                $id = (string)($toolCall['id'] ?? '');
                $name = (string)($toolCall['function']['name'] ?? '');
                if ($id !== '' && $name !== '') {
                    $map[$id] = $name;
                }
            }
        }
        return $map;
    }

    /**
     * Old tool results with media were stored as OpenAI-style block arrays
     * ([{type: text, text}, {type: image_url, image_url: {url: data:…}}]).
     * The new schema stores plain text — keep the text blocks, replace
     * embedded base64 images with a marker.
     */
    private function flattenContent(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if ($content === null) {
            return '';
        }
        if (!is_array($content)) {
            return (string)json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $parts = [];
        foreach ($content as $block) {
            if (is_string($block)) {
                $parts[] = $block;
                continue;
            }
            if (!is_array($block)) {
                continue;
            }
            if (isset($block['text'])) {
                $parts[] = (string)$block['text'];
                continue;
            }
            $type = (string)($block['type'] ?? 'unknown');
            $parts[] = '[' . ($type === 'image_url' ? 'inline image' : $type . ' block') . ' omitted during migration]';
        }
        return trim(implode("\n\n", array_filter($parts, static fn(string $part): bool => trim($part) !== '')));
    }

    /**
     * Old attachments were ref objects; only refs with a `uid` whose
     * sys_file row still exists survive the migration.
     *
     * @return list<int>
     */
    private function extractAttachmentFileUids(mixed $attachments): array
    {
        if (!is_array($attachments) || $attachments === []) {
            return [];
        }
        $uids = [];
        foreach ($attachments as $ref) {
            if (is_array($ref) && isset($ref['uid']) && empty($ref['unresolvable']) && (int)$ref['uid'] > 0) {
                $uids[] = (int)$ref['uid'];
            }
        }
        if ($uids === []) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $qb->getRestrictions()->removeAll();
        $existing = $qb
            ->select('uid')
            ->from('sys_file')
            ->where($qb->expr()->in('uid', $qb->createNamedParameter(array_values(array_unique($uids)), ArrayParameterType::INTEGER)))
            ->executeQuery()
            ->fetchFirstColumn();
        $existing = array_map(intval(...), $existing);

        return array_values(array_intersect(array_unique($uids), $existing));
    }

    /**
     * @param list<int> $fileUids
     */
    private function createFileReferences(int $messageUid, int $pid, int $deleted, array $fileUids): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::REFERENCE_TABLE);
        $now = time();
        $sorting = 0;
        foreach ($fileUids as $fileUid) {
            $sorting += 256;
            $connection->insert(
                self::REFERENCE_TABLE,
                [
                    'pid' => $pid,
                    'tstamp' => $now,
                    'crdate' => $now,
                    'deleted' => $deleted,
                    'uid_local' => $fileUid,
                    'uid_foreign' => $messageUid,
                    'tablenames' => self::MESSAGE_TABLE,
                    'fieldname' => 'attachments',
                    'sorting_foreign' => $sorting,
                ],
            );
        }
        return count($fileUids);
    }

    private function countExistingMessages(int $taskUid): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::MESSAGE_TABLE);
        $qb->getRestrictions()->removeAll();
        return (int)$qb
            ->count('uid')
            ->from(self::MESSAGE_TABLE)
            ->where($qb->expr()->eq('task', $qb->createNamedParameter($taskUid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchOne();
    }

    private function countPendingTasks(string $column): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TASK_TABLE);
        $qb->getRestrictions()->removeAll();
        return (int)$qb
            ->count('uid')
            ->from(self::TASK_TABLE)
            ->where(...$this->legacyValueNotEmpty($qb, $column))
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return list<string>
     */
    private function legacyValueNotEmpty(\TYPO3\CMS\Core\Database\Query\QueryBuilder $qb, string $column): array
    {
        return [
            $qb->expr()->isNotNull($column),
            (string)$qb->expr()->neq($column, $qb->createNamedParameter('')),
            (string)$qb->expr()->neq($column, $qb->createNamedParameter('null')),
            (string)$qb->expr()->neq($column, $qb->createNamedParameter('[]')),
        ];
    }

    /**
     * Locate the legacy JSON column. The type check guards against the
     * (theoretical) case of a column named `messages` that already is the
     * integer counter — that one must not be treated as legacy data.
     */
    private function findLegacyColumn(): ?string
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TASK_TABLE);
        $schemaManager = $connection->createSchemaManager();
        if (!$schemaManager->tablesExist([self::TASK_TABLE])) {
            return null;
        }

        $columns = $schemaManager->listTableColumns(self::TASK_TABLE);
        foreach (self::LEGACY_COLUMNS as $candidate) {
            foreach ($columns as $column) {
                if (strtolower(trim($column->getName(), '`"[]')) !== $candidate) {
                    continue;
                }
                $type = $column->getType();
                if ($type instanceof IntegerType || $type instanceof BigIntType || $type instanceof SmallIntType) {
                    continue 2;
                }
                return $candidate;
            }
        }
        return null;
    }

    private function say(string $message): void
    {
        $this->output?->writeln($message);
    }
}
