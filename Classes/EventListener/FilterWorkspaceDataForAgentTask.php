<?php

declare(strict_types=1);

namespace Hn\Agent\EventListener;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Workspaces\Event\AfterDataGeneratedForWorkspaceEvent;

#[AsEventListener('agent.filterWorkspaceDataForAgentTask')]
final class FilterWorkspaceDataForAgentTask
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function __invoke(AfterDataGeneratedForWorkspaceEvent $event): void
    {
        $taskUid = (int)($GLOBALS['TYPO3_REQUEST']->getQueryParams()['agentTaskUid'] ?? 0);
        if ($taskUid === 0) {
            return;
        }

        $allowed = $this->getAffectedRecords($taskUid);
        if ($allowed === []) {
            $event->setData([]);
            return;
        }

        $event->setData(array_values(array_filter(
            $event->getData(),
            static fn(array $r) => isset($allowed[$r['table'] . ':' . $r['t3ver_oid']])
        )));
    }

    /** @return array<string, true> */
    private function getAffectedRecords(int $taskUid): array
    {
        $rows = $this->connectionPool
            ->getConnectionForTable('tx_agent_task_change')
            ->select(['tablename', 'record_uid'], 'tx_agent_task_change', ['task_uid' => $taskUid])
            ->fetchAllAssociative();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['tablename'] . ':' . $row['record_uid']] = true;
        }
        return $map;
    }
}
