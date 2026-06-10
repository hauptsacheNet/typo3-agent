<?php

declare(strict_types=1);

namespace Hn\Agent\Tests\Functional\Service;

use Hn\Agent\Service\Llm\MessageBagAdapter;
use Hn\Agent\Service\LlmService;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;
use Symfony\AI\Platform\Result\ToolCall;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Exercises LlmService::aggregateStream() with fabricated Symfony AI deltas.
 * The method is protected, so we expose it via an anonymous subclass. No live
 * HTTP — purely the delta-aggregation logic that feeds the streaming UI and
 * builds the aggregated assistant message.
 */
class LlmServiceStreamAggregationTest extends TestCase
{
    /**
     * @return object{aggregate: callable(iterable, callable): array}
     */
    private function buildAggregator(): object
    {
        return new class(
            self::createStub(ExtensionConfiguration::class),
            new MessageBagAdapter(),
        ) extends LlmService {
            public function aggregate(iterable $deltas, callable $onDelta): array
            {
                return $this->aggregateStream($deltas, $onDelta);
            }
        };
    }

    public function testContentDeltasAreConcatenated(): void
    {
        $deltas = [
            new TextDelta('Hel'),
            new TextDelta('lo '),
            new TextDelta('world'),
        ];

        $events = [];
        $message = $this->buildAggregator()->aggregate(
            $deltas,
            function (string $type, array $p) use (&$events): void {
                $events[] = [$type, $p];
            },
        );

        self::assertSame('assistant', $message['role']);
        self::assertSame('Hello world', $message['content']);
        self::assertArrayNotHasKey('tool_calls', $message);

        $content = array_values(array_filter($events, fn($e) => $e[0] === 'content'));
        self::assertCount(3, $content);
        self::assertSame('Hel', $content[0][1]['text']);
        self::assertSame('lo ', $content[1][1]['text']);
        self::assertSame('world', $content[2][1]['text']);

        $finish = array_values(array_filter($events, fn($e) => $e[0] === 'finish'));
        self::assertCount(1, $finish);
        self::assertSame('stop', $finish[0][1]['reason']);
    }

    public function testToolCallDeltasAreStreamedIncrementallyAndAggregated(): void
    {
        // OpenAI-compatible bridge emits ToolCallStart + per-fragment
        // ToolInputDelta, then an authoritative ToolCallComplete.
        $deltas = [
            new ToolCallStart('call_42', 'GetPage'),
            new ToolInputDelta('call_42', 'GetPage', '{"u'),
            new ToolInputDelta('call_42', 'GetPage', 'id":'),
            new ToolInputDelta('call_42', 'GetPage', '5}'),
            new ToolCallComplete([new ToolCall('call_42', 'GetPage', ['uid' => 5])]),
        ];

        $events = [];
        $message = $this->buildAggregator()->aggregate(
            $deltas,
            function (string $type, array $p) use (&$events): void {
                $events[] = [$type, $p];
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

        // One start event (id + name) plus one per argument fragment = 4 total.
        $tc = array_values(array_filter($events, fn($e) => $e[0] === 'tool_call'));
        self::assertCount(4, $tc, 'expected incremental tool_call deltas (start + 3 fragments)');
        self::assertSame('call_42', $tc[0][1]['id']);
        self::assertSame('GetPage', $tc[0][1]['name']);
        self::assertSame(0, $tc[0][1]['index']);
        self::assertSame('{"u', $tc[1][1]['arguments']);
        self::assertSame('id":', $tc[2][1]['arguments']);
        self::assertSame('5}', $tc[3][1]['arguments']);

        $finish = array_values(array_filter($events, fn($e) => $e[0] === 'finish'));
        self::assertSame('tool_calls', $finish[0][1]['reason']);
    }
}
