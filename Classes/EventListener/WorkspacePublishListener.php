<?php

declare(strict_types=1);

namespace Hn\Agent\EventListener;

use Hn\Agent\Domain\AgentTaskRepository;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Workspaces\Event\AfterRecordPublishedEvent;

#[AsEventListener('agent.afterRecordPublished')]
final class WorkspacePublishListener
{
    public function __construct(
        private readonly AgentTaskRepository $repository,
    ) {}

    public function __invoke(AfterRecordPublishedEvent $event): void
    {
        $this->repository->removeChangeByRecord($event->getTable(), $event->getRecordId());
    }
}
