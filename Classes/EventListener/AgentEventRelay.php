<?php

declare(strict_types=1);

namespace Hn\Agent\EventListener;

use Hn\Agent\Event\AfterAssistantMessageEvent;
use Hn\Agent\Event\AfterToolExecutionEvent;
use Hn\Agent\Event\BeforeLlmCallEvent;
use Hn\Agent\Event\BeforeToolExecutionEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;

/**
 * Bridge listener that forwards agent progress events to a runtime-configured callback.
 *
 * Controllers and commands inject this service and call setCallback() before
 * running the agent loop. Any other extension can register its own independent
 * PSR-14 listeners for the same events without touching this bridge.
 */
final class AgentEventRelay
{
    private ?\Closure $callback = null;

    public function setCallback(?callable $callback): void
    {
        $this->callback = $callback !== null ? $callback(...) : null;
    }

    public function clearCallback(): void
    {
        $this->callback = null;
    }

    #[AsEventListener(event: BeforeLlmCallEvent::class)]
    public function onBeforeLlmCall(BeforeLlmCallEvent $event): void
    {
        $this->forward('llm_start', [
            'iteration' => $event->getIteration(),
        ]);
    }

    #[AsEventListener(event: AfterAssistantMessageEvent::class)]
    public function onAfterAssistantMessage(AfterAssistantMessageEvent $event): void
    {
        $this->forward('assistant_message', [
            'iteration' => $event->getIteration(),
            'message' => $event->getMessage(),
        ]);
    }

    #[AsEventListener(event: BeforeToolExecutionEvent::class)]
    public function onBeforeToolExecution(BeforeToolExecutionEvent $event): void
    {
        $this->forward('tool_start', [
            'iteration' => $event->getIteration(),
            'tool_call_id' => $event->getToolCallId(),
            'tool_name' => $event->getToolName(),
            'arguments' => $event->getArguments(),
        ]);
    }

    #[AsEventListener(event: AfterToolExecutionEvent::class)]
    public function onAfterToolExecution(AfterToolExecutionEvent $event): void
    {
        $this->forward('tool_result', [
            'iteration' => $event->getIteration(),
            'tool_call_id' => $event->getToolCallId(),
            'tool_name' => $event->getToolName(),
            'content' => $event->getContent(),
        ]);
    }

    private function forward(string $event, array $data): void
    {
        if ($this->callback !== null) {
            ($this->callback)($event, $data);
        }
    }
}
