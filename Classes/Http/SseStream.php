<?php

declare(strict_types=1);

namespace Hn\Agent\Http;

use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\ServerEvent;
use TYPO3\CMS\Core\Http\SelfEmittableStreamInterface;

class SseStream implements SelfEmittableStreamInterface
{
    private \Closure $emitter;
    private bool $emitted = false;

    /**
     * @param \Closure(callable(string $event, array $data): void): void $emitter
     */
    public function __construct(\Closure $emitter)
    {
        $this->emitter = $emitter;
    }

    public function emit(): void
    {
        if (function_exists('apache_setenv')) {
            apache_setenv('no-gzip', '1');
        }
        ini_set('zlib.output_compression', '0');
        ini_set('output_buffering', '0');
        ob_implicit_flush(true);
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        // Keep PHP alive past a client disconnect so the agent loop can run to
        // completion and persist its final state. A disconnect is not treated
        // as a cancellation — explicit cancel goes through a separate endpoint.
        ignore_user_abort(true);

        $clientGone = false;
        $send = static function (string $event, array $data) use (&$clientGone): void {
            if ($clientGone) {
                return;
            }
            $serverEvent = new ServerEvent(
                data: json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                type: $event,
            );
            foreach ($serverEvent as $part) {
                echo $part;
            }
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
            if (connection_aborted() === 1) {
                $clientGone = true;
            }
        };

        ($this->emitter)($send);
        $this->emitted = true;
    }

    public function __toString(): string
    {
        return '';
    }

    public function close(): void {}

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return 0;
    }

    public function eof(): bool
    {
        return $this->emitted;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new \RuntimeException('Stream is not seekable');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('Stream is not seekable');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('Stream is not writable');
    }

    public function isReadable(): bool
    {
        return false;
    }

    public function read(int $length): string
    {
        return '';
    }

    public function getContents(): string
    {
        return '';
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
