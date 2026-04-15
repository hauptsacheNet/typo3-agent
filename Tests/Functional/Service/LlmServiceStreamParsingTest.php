<?php

declare(strict_types=1);

namespace Hn\Agent\Tests\Functional\Service;

use Hn\Agent\Service\LlmService;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Exercises LlmService::parseSseStream() with fabricated SSE payloads. The
 * method is protected, so we expose it via an anonymous subclass. No live
 * HTTP or DB access — purely a parser test.
 */
class LlmServiceStreamParsingTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
        'agent',
    ];

    /**
     * Build a test-only LlmService subclass that exposes parseSseStream().
     *
     * @return object{parse: callable(StreamInterface, callable): array}
     */
    private function buildParser(): object
    {
        $service = new class(
            GeneralUtility::makeInstance(RequestFactory::class),
            GeneralUtility::makeInstance(ExtensionConfiguration::class),
        ) extends LlmService {
            public function parse(StreamInterface $body, callable $onDelta): array
            {
                return $this->parseSseStream($body, $onDelta);
            }
        };
        return $service;
    }

    /**
     * Split a canonical SSE payload into arbitrary-sized chunks to simulate
     * network fragmentation (including splits mid-line).
     */
    private function chunkedStream(string $payload, int $chunkSize): StreamInterface
    {
        $chunks = str_split($payload, $chunkSize);
        $idx = 0;
        // An in-memory stream that returns content chunk-by-chunk per read()
        return new class($chunks, $idx) implements StreamInterface {
            public function __construct(private array $chunks, private int $idx) {}
            public function read(int $length): string
            {
                if ($this->idx >= count($this->chunks)) {
                    return '';
                }
                return $this->chunks[$this->idx++];
            }
            public function eof(): bool { return $this->idx >= count($this->chunks); }
            public function __toString(): string { return ''; }
            public function close(): void {}
            public function detach() { return null; }
            public function getSize(): ?int { return null; }
            public function tell(): int { return 0; }
            public function isSeekable(): bool { return false; }
            public function seek(int $offset, int $whence = SEEK_SET): void {}
            public function rewind(): void {}
            public function isWritable(): bool { return false; }
            public function write(string $string): int { return 0; }
            public function isReadable(): bool { return true; }
            public function getContents(): string { return ''; }
            public function getMetadata(?string $key = null) { return $key === null ? [] : null; }
        };
    }

    public function testContentDeltasAreConcatenated(): void
    {
        $sse = ''
            . "data: {\"choices\":[{\"delta\":{\"role\":\"assistant\",\"content\":\"Hel\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\"lo \"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\"world\"},\"finish_reason\":\"stop\"}]}\n\n"
            . "data: [DONE]\n\n";

        $deltas = [];
        $parser = $this->buildParser();
        $message = $parser->parse(
            $this->chunkedStream($sse, 16),
            function (string $type, array $p) use (&$deltas): void {
                $deltas[] = [$type, $p];
            },
        );

        self::assertSame('assistant', $message['role']);
        self::assertSame('Hello world', $message['content']);
        self::assertArrayNotHasKey('tool_calls', $message);

        $contentDeltas = array_values(array_filter($deltas, fn($d) => $d[0] === 'content'));
        self::assertCount(3, $contentDeltas);
        self::assertSame('Hel', $contentDeltas[0][1]['text']);
        self::assertSame('lo ', $contentDeltas[1][1]['text']);
        self::assertSame('world', $contentDeltas[2][1]['text']);

        $finish = array_values(array_filter($deltas, fn($d) => $d[0] === 'finish'));
        self::assertCount(1, $finish);
        self::assertSame('stop', $finish[0][1]['reason']);
    }

    public function testToolCallArgumentsAreConcatenatedAcrossChunks(): void
    {
        // First delta has id + name; subsequent deltas carry arguments fragments.
        $sse = ''
            . "data: {\"choices\":[{\"delta\":{\"role\":\"assistant\",\"tool_calls\":[{\"index\":0,\"id\":\"call_42\",\"type\":\"function\",\"function\":{\"name\":\"GetPage\",\"arguments\":\"{\\\"u\"}}]}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"function\":{\"arguments\":\"id\\\":\"}}]}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"function\":{\"arguments\":\"5}\"}}]},\"finish_reason\":\"tool_calls\"}]}\n\n"
            . "data: [DONE]\n\n";

        $deltas = [];
        $parser = $this->buildParser();
        $message = $parser->parse(
            $this->chunkedStream($sse, 24),
            function (string $type, array $p) use (&$deltas): void {
                $deltas[] = [$type, $p];
            },
        );

        self::assertSame('assistant', $message['role']);
        self::assertNull($message['content']);
        self::assertArrayHasKey('tool_calls', $message);
        self::assertCount(1, $message['tool_calls']);

        $toolCall = $message['tool_calls'][0];
        self::assertSame('call_42', $toolCall['id']);
        self::assertSame('function', $toolCall['type']);
        self::assertSame('GetPage', $toolCall['function']['name']);
        self::assertSame('{"uid":5}', $toolCall['function']['arguments']);

        $tcDeltas = array_values(array_filter($deltas, fn($d) => $d[0] === 'tool_call'));
        self::assertCount(3, $tcDeltas, 'expected 3 tool_call deltas (one per fragment)');
        self::assertSame('call_42', $tcDeltas[0][1]['id']);
        self::assertSame('GetPage', $tcDeltas[0][1]['name']);
        self::assertSame('{"u', $tcDeltas[0][1]['arguments']);
        self::assertSame('id":', $tcDeltas[1][1]['arguments']);
        self::assertSame('5}', $tcDeltas[2][1]['arguments']);
    }

    public function testDoneTerminatesStreamEvenIfMoreDataFollows(): void
    {
        $sse = ''
            . "data: {\"choices\":[{\"delta\":{\"role\":\"assistant\",\"content\":\"ok\"}}]}\n\n"
            . "data: [DONE]\n\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\"IGNORED\"}}]}\n\n";

        $parser = $this->buildParser();
        $message = $parser->parse($this->chunkedStream($sse, 8), fn() => null);

        self::assertSame('ok', $message['content']);
    }

    public function testSseCommentsAndBlankLinesAreIgnored(): void
    {
        $sse = ''
            . ": this is a keep-alive comment\n\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\"x\"}}]}\n\n"
            . "data: [DONE]\n\n";

        $parser = $this->buildParser();
        $message = $parser->parse($this->chunkedStream($sse, 4), fn() => null);

        self::assertSame('x', $message['content']);
    }
}
