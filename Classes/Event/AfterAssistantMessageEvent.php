<?php

declare(strict_types=1);

namespace Hn\Agent\Event;

final readonly class AfterAssistantMessageEvent
{
    public function __construct(
        private int $taskUid,
        private int $iteration,
        private array $message,
    ) {}

    public function getTaskUid(): int
    {
        return $this->taskUid;
    }

    public function getIteration(): int
    {
        return $this->iteration;
    }

    public function getMessage(): array
    {
        return $this->message;
    }
}
