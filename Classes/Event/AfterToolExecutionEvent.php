<?php

declare(strict_types=1);

namespace Hn\Agent\Event;

final readonly class AfterToolExecutionEvent
{
    public function __construct(
        private int $taskUid,
        private int $iteration,
        private string $toolCallId,
        private string $toolName,
        private string $content,
    ) {}

    public function getTaskUid(): int
    {
        return $this->taskUid;
    }

    public function getIteration(): int
    {
        return $this->iteration;
    }

    public function getToolCallId(): string
    {
        return $this->toolCallId;
    }

    public function getToolName(): string
    {
        return $this->toolName;
    }

    public function getContent(): string
    {
        return $this->content;
    }
}
