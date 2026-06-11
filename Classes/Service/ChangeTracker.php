<?php

declare(strict_types=1);

namespace Hn\Agent\Service;

use Doctrine\DBAL\ParameterType;
use Hn\Agent\Domain\AgentTaskRepository;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;

class ChangeTracker
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly AgentTaskRepository $repository,
    ) {}

    /**
     * Track a workspace change from a tool result.
     *
     * Parses the JSON result of a write-tool call and stores the relationship
     * between the task and the workspace version record. Returns the change
     * descriptor (for progress streaming) or null when the result is not a
     * tracked write operation or the user is not in a workspace.
     */
    public function track(int $taskUid, string $toolResult): ?array
    {
        $data = json_decode($toolResult, true);
        if (!is_array($data) || !isset($data['action'], $data['table'], $data['uid'])) {
            return null;
        }

        $table = (string)$data['table'];
        $uid = (int)$data['uid'];
        $action = (string)$data['action'];
        $workspaceId = (int)($GLOBALS['BE_USER']->workspace ?? 0);

        if ($workspaceId === 0) {
            // No workspace — changes went directly to live, nothing to track
            return null;
        }

        if ($action === 'create' || $action === 'translate') {
            // For new records, the returned UID is already the workspace record UID
            // (NEW_PLACEHOLDER with t3ver_state=1)
            $workspaceRecordUid = $uid;
        } else {
            // For update/delete, look up the workspace version of the live record
            $wsVersion = BackendUtility::getWorkspaceVersionOfRecord($workspaceId, $table, $uid, 'uid');
            if ($wsVersion === false) {
                return null;
            }
            $workspaceRecordUid = (int)$wsVersion['uid'];
        }

        [$pageId, $workspacePageId] = $this->resolvePageIds($table, $uid, $workspaceRecordUid, $action);

        $this->repository->addChange($taskUid, $table, $uid, $workspaceRecordUid, $pageId, $workspacePageId);

        return [
            'tablename' => $table,
            'page_id' => $pageId,
            'record_uid' => $uid,
            'workspace_record_uid' => $workspaceRecordUid,
            'workspace_page_id' => $workspacePageId,
            'task_uid' => $taskUid,
        ];
    }

    /**
     * @return array{int, int} [pageId, workspacePageId]
     */
    private function resolvePageIds(string $table, int $recordUid, int $workspaceRecordUid, string $action): array
    {
        if ($table === 'pages') {
            return [$recordUid, $workspaceRecordUid];
        }

        if ($action === 'create' || $action === 'translate') {
            $pid = $this->getRecordPid($table, $recordUid);
            return [$pid, $pid];
        }

        $pageId = $this->getRecordPid($table, $recordUid);
        $workspacePageId = ($workspaceRecordUid !== $recordUid)
            ? $this->getRecordPid($table, $workspaceRecordUid)
            : $pageId;

        return [$pageId, $workspacePageId];
    }

    /**
     * Get the pid of a record by direct database lookup (bypasses workspace overlays).
     */
    private function getRecordPid(string $table, int $uid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('pid')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? (int)$row['pid'] : 0;
    }
}
