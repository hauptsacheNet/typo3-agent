<?php

declare(strict_types=1);

namespace Hn\Agent\Http;

/**
 * Thrown by SseStream when the client has closed the connection mid-stream.
 * Signals "user cancelled" — not an error. Caller (AgentService) is expected
 * to persist whatever partial state exists and mark the task as Cancelled.
 */
class ClientDisconnectedException extends \RuntimeException
{
}
