<?php

declare(strict_types=1);

namespace Hn\Agent\Tests\Functional\Hook;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Tasks created through DataHandler (List module) must get an owner and a
 * workspace, because the agent refuses to run tasks without an owner.
 */
class DataHandlerHookTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
        'agent',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');
    }

    /**
     * @return array<string, mixed>
     */
    private function createTaskViaDataHandler(): array
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(
            ['tx_agent_task' => ['NEW1' => ['pid' => 1, 'title' => 'From list module', 'prompt' => 'Do something']]],
            [],
        );
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);

        $uid = (int)$dataHandler->substNEWwithIDs['NEW1'];
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_agent_task');
        $qb->getRestrictions()->removeAll();
        return $qb->select('*')->from('tx_agent_task')
            ->where($qb->expr()->eq('uid', $uid))
            ->executeQuery()->fetchAssociative();
    }

    public function testNewTaskGetsCreatorAsOwner(): void
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);

        $task = $this->createTaskViaDataHandler();

        self::assertSame(1, (int)$task['cruser_id']);
        self::assertSame(0, (int)$task['workspace_id'], 'Creator is in Live, so no workspace is stamped');
    }

    public function testNewTaskGetsCreatorsWorkspace(): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_workspace')
            ->insert('sys_workspace', ['uid' => 5, 'pid' => 0, 'title' => 'AI drafts', 'adminusers' => 'be_users_1']);
        $backendUser = $this->setUpBackendUser(1);
        $backendUser->setWorkspace(5);
        $GLOBALS['BE_USER'] = $backendUser;

        $task = $this->createTaskViaDataHandler();

        self::assertSame(1, (int)$task['cruser_id']);
        self::assertSame(5, (int)$task['workspace_id']);
    }
}
