<?php

declare(strict_types=1);

namespace Hn\Agent\Tests\Functional\Updates;

use Hn\Agent\Domain\AgentMessageRepository;
use Hn\Agent\Updates\MigrateTaskMessagesUpdateWizard;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class MigrateTaskMessagesUpdateWizardTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
        'agent',
    ];

    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $this->dropLegacyColumns();
    }

    /**
     * The testing framework keeps the schema between the tests of a class —
     * remove leftover legacy columns so every test starts from the clean
     * post-upgrade schema.
     */
    private function dropLegacyColumns(): void
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_agent_task');
        $existing = array_keys($connection->createSchemaManager()->listTableColumns('tx_agent_task'));
        foreach (['messages', 'zzz_deleted_messages'] as $column) {
            if (in_array($column, $existing, true)) {
                $connection->executeStatement('ALTER TABLE tx_agent_task DROP COLUMN ' . $column);
            }
        }
    }

    private function createWizard(): MigrateTaskMessagesUpdateWizard
    {
        return new MigrateTaskMessagesUpdateWizard($this->connectionPool);
    }

    /**
     * Simulate the pre-upgrade schema: the JSON column still exists because
     * the schema migrator never converts it (the new counter has a fresh
     * name). `zzz_deleted_messages` covers the state after the admin ran the
     * "remove unused fields" rename in the database analyzer.
     */
    private function addLegacyColumn(string $name = 'messages'): void
    {
        $this->connectionPool
            ->getConnectionForTable('tx_agent_task')
            ->executeStatement('ALTER TABLE tx_agent_task ADD ' . $name . ' TEXT');
    }

    private function insertLegacyTask(array $messages, string $column = 'messages', int $deleted = 0): int
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_agent_task');
        $connection->insert('tx_agent_task', [
            'pid' => 0,
            'title' => 'Legacy task',
            'prompt' => 'prompt',
            'status' => 2,
            'deleted' => $deleted,
            $column => json_encode($messages, JSON_UNESCAPED_UNICODE),
        ]);
        return (int)$connection->lastInsertId();
    }

    private function insertRawLegacyValue(string $value, string $column = 'messages'): int
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_agent_task');
        $connection->insert('tx_agent_task', [
            'pid' => 0,
            'title' => 'Legacy task',
            'prompt' => 'prompt',
            'status' => 2,
            $column => $value,
        ]);
        return (int)$connection->lastInsertId();
    }

    private function insertSysFile(string $name): int
    {
        $connection = $this->connectionPool->getConnectionForTable('sys_file');
        $connection->insert('sys_file', [
            'pid' => 0,
            'storage' => 1,
            'identifier' => '/' . $name,
            'name' => $name,
        ]);
        return (int)$connection->lastInsertId();
    }

    private function getTaskRow(int $uid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_agent_task');
        $qb->getRestrictions()->removeAll();
        return $qb->select('*')->from('tx_agent_task')
            ->where($qb->expr()->eq('uid', $uid))
            ->executeQuery()->fetchAssociative() ?: [];
    }

    private function getMessageRows(int $taskUid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_agent_message');
        $qb->getRestrictions()->removeAll();
        return $qb->select('*')->from('tx_agent_message')
            ->where($qb->expr()->eq('task', $taskUid))
            ->orderBy('sorting', 'ASC')
            ->executeQuery()->fetchAllAssociative();
    }

    public function testUpdateNotNecessaryWithoutLegacyColumn(): void
    {
        self::assertFalse($this->createWizard()->updateNecessary());
    }

    public function testUpdateNotNecessaryWhenLegacyColumnIsEmpty(): void
    {
        $this->addLegacyColumn();
        $this->insertRawLegacyValue('');
        $this->insertRawLegacyValue('null');
        $this->insertRawLegacyValue('[]');
        self::assertFalse($this->createWizard()->updateNecessary());
    }

    public function testMigratesConversationIntoMessageRecords(): void
    {
        $this->addLegacyColumn();
        $fileUid = $this->insertSysFile('screenshot.png');
        $taskUid = $this->insertLegacyTask([
            ['role' => 'system', 'content' => 'You are a helpful TYPO3 CMS assistant.'],
            [
                'role' => 'user',
                'content' => 'Describe this page',
                'attachments' => [
                    ['uid' => $fileUid, 'identifier' => '1:/screenshot.png', 'name' => 'screenshot.png', 'mime_type' => 'image/png'],
                    ['name' => 'gone.pdf', 'unresolvable' => true],
                    ['uid' => 99999, 'identifier' => '1:/missing.png', 'name' => 'missing.png'],
                ],
            ],
            [
                'role' => 'assistant',
                'content' => 'Ich lade die Seite.',
                'tool_calls' => [
                    ['id' => 'call_001', 'type' => 'function', 'function' => ['name' => 'GetPage', 'arguments' => '{"uid":1}']],
                ],
            ],
            [
                'role' => 'tool',
                'tool_call_id' => 'call_001',
                'content' => [
                    ['type' => 'text', 'text' => 'Page loaded.'],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,AAAA']],
                ],
            ],
            ['role' => 'assistant', 'content' => 'Fertig.'],
        ]);

        $wizard = $this->createWizard();
        self::assertTrue($wizard->updateNecessary());
        self::assertTrue($wizard->executeUpdate());

        $messages = $this->getMessageRows($taskUid);
        self::assertCount(5, $messages);
        self::assertSame(
            ['system', 'user', 'assistant', 'tool', 'assistant'],
            array_column($messages, 'role'),
        );

        // user attachments: resolvable ref became a sys_file_reference, the rest was dropped
        self::assertSame(1, (int)$messages[1]['attachments']);
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $qb->getRestrictions()->removeAll();
        $reference = $qb->select('*')->from('sys_file_reference')
            ->where(
                $qb->expr()->eq('tablenames', $qb->createNamedParameter('tx_agent_message')),
                $qb->expr()->eq('uid_foreign', (int)$messages[1]['uid']),
            )
            ->executeQuery()->fetchAllAssociative();
        self::assertCount(1, $reference);
        self::assertSame($fileUid, (int)$reference[0]['uid_local']);
        self::assertSame('attachments', $reference[0]['fieldname']);

        // assistant tool_calls survive as JSON
        $toolCalls = json_decode((string)$messages[2]['tool_calls'], true);
        self::assertSame('GetPage', $toolCalls[0]['function']['name']);

        // tool message: tool_name derived from the assistant's tool_calls,
        // block content flattened, base64 image replaced by a marker
        self::assertSame('call_001', $messages[3]['tool_call_id']);
        self::assertSame('GetPage', $messages[3]['tool_name']);
        self::assertStringContainsString('Page loaded.', (string)$messages[3]['content']);
        self::assertStringContainsString('[inline image omitted during migration]', (string)$messages[3]['content']);
        self::assertStringNotContainsString('base64', (string)$messages[3]['content']);

        // task row: counter filled, legacy column emptied → wizard done
        $task = $this->getTaskRow($taskUid);
        self::assertSame(5, (int)$task['chat_messages']);
        self::assertNull($task['messages']);
        self::assertFalse($wizard->updateNecessary());

        // migrated history is readable through the regular repository
        $repository = new AgentMessageRepository($this->connectionPool);
        $hydrated = $repository->findByTask($taskUid);
        self::assertCount(5, $hydrated);
        self::assertSame([$fileUid], $hydrated[1]['attachments']);
    }

    public function testMigratesFromRenamedZzzDeletedColumn(): void
    {
        $this->addLegacyColumn('zzz_deleted_messages');
        $taskUid = $this->insertLegacyTask([
            ['role' => 'system', 'content' => 'sys'],
            ['role' => 'user', 'content' => 'hello'],
        ], 'zzz_deleted_messages');

        $wizard = $this->createWizard();
        self::assertTrue($wizard->updateNecessary());
        self::assertTrue($wizard->executeUpdate());

        self::assertCount(2, $this->getMessageRows($taskUid));
        $task = $this->getTaskRow($taskUid);
        self::assertSame(2, (int)$task['chat_messages']);
        self::assertNull($task['zzz_deleted_messages']);
    }

    public function testDeletedTaskGetsDeletedMessages(): void
    {
        $this->addLegacyColumn();
        $taskUid = $this->insertLegacyTask([
            ['role' => 'user', 'content' => 'hello'],
        ], deleted: 1);

        self::assertTrue($this->createWizard()->executeUpdate());

        $messages = $this->getMessageRows($taskUid);
        self::assertCount(1, $messages);
        self::assertSame(1, (int)$messages[0]['deleted']);
    }

    public function testInvalidJsonIsKeptForManualInspection(): void
    {
        $this->addLegacyColumn();
        $taskUid = $this->insertRawLegacyValue('{broken');

        self::assertTrue($this->createWizard()->executeUpdate());

        self::assertCount(0, $this->getMessageRows($taskUid));
        self::assertSame('{broken', $this->getTaskRow($taskUid)['messages']);
    }

    public function testJsonWithoutMessageObjectsIsKeptForManualInspection(): void
    {
        $this->addLegacyColumn();
        // decodes to a non-empty array, but none of its entries are message objects
        $taskUid = $this->insertRawLegacyValue('{"role":"user","content":"hi"}');

        self::assertTrue($this->createWizard()->executeUpdate());

        self::assertCount(0, $this->getMessageRows($taskUid));
        $task = $this->getTaskRow($taskUid);
        self::assertSame('{"role":"user","content":"hi"}', $task['messages']);
        self::assertSame(0, (int)$task['chat_messages']);
    }

    public function testTaskWithExistingMessagesIsNotDuplicated(): void
    {
        $this->addLegacyColumn();
        $taskUid = $this->insertLegacyTask([
            ['role' => 'user', 'content' => 'from legacy json'],
        ]);
        $repository = new AgentMessageRepository($this->connectionPool);
        $repository->append($taskUid, 0, ['role' => 'user', 'content' => 'already migrated']);

        self::assertTrue($this->createWizard()->executeUpdate());

        $messages = $this->getMessageRows($taskUid);
        self::assertCount(1, $messages);
        self::assertSame('already migrated', $messages[0]['content']);
        // legacy value stays for manual comparison
        self::assertNotNull($this->getTaskRow($taskUid)['messages']);
    }
}
