<?php

declare(strict_types=1);

namespace Hn\Agent\Tests\Functional\Service\Llm;

use Hn\Agent\Service\Llm\Content\DocumentContent;
use Hn\Agent\Service\Llm\MessageBagAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Pure transformation tests for the OpenAI-array <-> Symfony AI object adapter.
 * No TYPO3/DB needed, so this extends the plain PHPUnit TestCase.
 */
class MessageBagAdapterTest extends TestCase
{
    private MessageBagAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new MessageBagAdapter();
    }

    public function testToMessageBagMapsRolesToTypedMessages(): void
    {
        $bag = $this->adapter->toMessageBag([
            ['role' => 'system', 'content' => 'You are helpful.'],
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'GetPage', 'arguments' => '{"uid":5}']],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'Page #5'],
        ]);

        $messages = $bag->getMessages();
        self::assertCount(4, $messages);
        self::assertInstanceOf(SystemMessage::class, $messages[0]);
        self::assertInstanceOf(UserMessage::class, $messages[1]);
        self::assertInstanceOf(AssistantMessage::class, $messages[2]);
        self::assertInstanceOf(ToolCallMessage::class, $messages[3]);

        // User text is wrapped into a Text content part.
        $userParts = $messages[1]->getContent();
        self::assertInstanceOf(Text::class, $userParts[0]);
        self::assertSame('Hello', $userParts[0]->getText());

        // Assistant tool call round-trips id/name and decodes arguments to array.
        $toolCalls = array_values(array_filter(
            $messages[2]->getContent(),
            static fn($p) => $p instanceof ToolCall,
        ));
        self::assertCount(1, $toolCalls);
        self::assertSame('call_1', $toolCalls[0]->getId());
        self::assertSame('GetPage', $toolCalls[0]->getName());
        self::assertSame(['uid' => 5], $toolCalls[0]->getArguments());

        // Tool message carries the tool_call_id and string content.
        self::assertSame('call_1', $messages[3]->getToolCall()->getId());
        self::assertSame('Page #5', $messages[3]->getContent());
    }

    public function testUserMessageWithImageBlockBecomesImageContent(): void
    {
        $bag = $this->adapter->toMessageBag([
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'Look:'],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,AAAA']],
            ]],
        ]);

        $parts = $bag->getMessages()[0]->getContent();
        $images = array_values(array_filter($parts, static fn($p) => $p instanceof Image));
        self::assertCount(1, $images);
        self::assertSame('data:image/png;base64,AAAA', $images[0]->asDataUrl());
    }

    public function testUserMessageWithFileBlockBecomesDocumentContent(): void
    {
        $bag = $this->adapter->toMessageBag([
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'Doc:'],
                ['type' => 'file', 'file' => ['filename' => 'doc.pdf', 'file_data' => 'data:application/pdf;base64,BBBB']],
            ]],
        ]);

        $parts = $bag->getMessages()[0]->getContent();
        $docs = array_values(array_filter($parts, static fn($p) => $p instanceof DocumentContent));
        self::assertCount(1, $docs);
        self::assertSame(
            ['type' => 'file', 'file' => ['filename' => 'doc.pdf', 'file_data' => 'data:application/pdf;base64,BBBB']],
            $docs[0]->jsonSerialize(),
        );
    }

    public function testResultToAssistantArrayForText(): void
    {
        $array = $this->adapter->resultToAssistantArray(new TextResult('Final answer.'));

        self::assertSame('assistant', $array['role']);
        self::assertSame('Final answer.', $array['content']);
        self::assertArrayNotHasKey('tool_calls', $array);
    }

    public function testResultToAssistantArrayForToolCalls(): void
    {
        $array = $this->adapter->resultToAssistantArray(new ToolCallResult([
            new ToolCall('call_9', 'GetPageTree', ['depth' => 1]),
        ]));

        self::assertSame('assistant', $array['role']);
        self::assertNull($array['content']);
        self::assertCount(1, $array['tool_calls']);
        self::assertSame('call_9', $array['tool_calls'][0]['id']);
        self::assertSame('function', $array['tool_calls'][0]['type']);
        self::assertSame('GetPageTree', $array['tool_calls'][0]['function']['name']);
        self::assertSame('{"depth":1}', $array['tool_calls'][0]['function']['arguments']);
    }

    public function testToolCallToArrayEncodesEmptyArgumentsAsObject(): void
    {
        $array = $this->adapter->toolCallToArray(new ToolCall('call_0', 'Ping', []));

        self::assertSame('{}', $array['function']['arguments']);
    }

    public function testToolsToObjectsPreservesSchemaParameters(): void
    {
        $tools = $this->adapter->toolsToObjects([
            ['type' => 'function', 'function' => [
                'name' => 'GetPage',
                'description' => 'Load a page',
                'parameters' => ['type' => 'object', 'properties' => ['uid' => ['type' => 'integer']]],
            ]],
        ]);

        self::assertCount(1, $tools);
        self::assertInstanceOf(Tool::class, $tools[0]);
        self::assertSame('GetPage', $tools[0]->getName());
        self::assertSame('Load a page', $tools[0]->getDescription());
        self::assertSame(
            ['type' => 'object', 'properties' => ['uid' => ['type' => 'integer']]],
            $tools[0]->getParameters(),
        );
    }
}
