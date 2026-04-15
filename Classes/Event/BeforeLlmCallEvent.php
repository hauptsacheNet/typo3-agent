<?php

declare(strict_types=1);

namespace Hn\Agent\Event;

final readonly class BeforeLlmCallEvent
{
    public function __construct(
        private int $taskUid,
        private int $iteration,
    ) {}

    public function getTaskUid(): int
    {
        return $this->taskUid;
    }

    public function getIteration(): int
    {
        return $this->iteration;
    }
}
