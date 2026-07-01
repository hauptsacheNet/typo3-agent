<?php

declare(strict_types=1);

namespace Hn\Agent\Domain;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Business-Status-Transitionen für Tasks. Alle Übergänge starten in
 * `TaskStatus::InProgress` (durch die Lease-Semantik von claim() garantiert)
 * und enden in einem Terminal-Status. Reine Status-Transitionen: Messages
 * und Result werden vom Aufrufer separat via
 * `AgentTaskRepository::saveMessages()` / `saveResult()` persistiert.
 *
 * Bei `TaskEvent::Cancel` liest die State Machine den aktuellen BE_USER
 * aus den Globals und schränkt Non-Admins auf ihre eigenen Tasks ein.
 */
final class TaskStateMachine
{
    private const TRANSITIONS = [
        TaskEvent::Cancel->value => TaskStatus::Cancelled,
        TaskEvent::End->value    => TaskStatus::Ended,
        TaskEvent::Fail->value   => TaskStatus::Failed,
    ];

    public function __construct(
        private readonly AgentTaskRepository $repository,
    ) {}

    public function trigger(int $taskUid, TaskEvent $event): bool
    {
        $to = self::TRANSITIONS[$event->value];
        return $this->repository->casFromInProgress($taskUid, $to, $this->extraCriteria($event));
    }

    /**
     * @return array<string, int>
     */
    private function extraCriteria(TaskEvent $event): array
    {
        if ($event !== TaskEvent::Cancel) {
            return [];
        }
        $beUser = $GLOBALS['BE_USER'] ?? null;
        if (!$beUser instanceof BackendUserAuthentication) {
            return [];
        }
        if ((bool)($beUser->user['admin'] ?? false)) {
            return [];
        }
        return ['cruser_id' => (int)($beUser->user['uid'] ?? 0)];
    }
}
