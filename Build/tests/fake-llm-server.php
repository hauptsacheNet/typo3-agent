<?php

declare(strict_types=1);

/**
 * Deterministic fake OpenAI-compatible LLM server for E2E tests.
 *
 * Run as router script for the PHP built-in server:
 *
 *   php -S 127.0.0.1:8089 Build/tests/fake-llm-server.php
 *
 * Point the agent extension at it via extension configuration:
 *
 *   EXTENSIONS.agent.apiUrl = http://127.0.0.1:8089/v1/
 *
 * The response is derived from the conversation state alone, so the server
 * is stateless and safe to reuse across tests:
 *
 *  - Last user message contains "E2E-TOOL" and no tool result followed it yet
 *    → emit a GetPage tool call (exercises the full tool-execution loop).
 *  - Last user message contains "E2E-TOOL" and a tool result is present
 *    → emit the final text "FAKE-LLM-TOOL-DONE ...".
 *  - Anything else → emit "FAKE-LLM-REPLY ..." echoing the user message.
 */

if (!str_ends_with(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '', '/chat/completions')) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Only /chat/completions is implemented by the fake LLM server.']);
    return;
}

$request = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($request)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid JSON body.']);
    return;
}

$messages = is_array($request['messages'] ?? null) ? $request['messages'] : [];
$stream = (bool)($request['stream'] ?? false);
$model = (string)($request['model'] ?? 'fake-model');

// Locate the last user message and whether a tool result followed it.
$lastUserIndex = -1;
foreach ($messages as $index => $message) {
    if (($message['role'] ?? '') === 'user') {
        $lastUserIndex = $index;
    }
}
$userText = '';
if ($lastUserIndex >= 0) {
    $content = $messages[$lastUserIndex]['content'] ?? '';
    if (is_string($content)) {
        $userText = $content;
    } elseif (is_array($content)) {
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $userText .= (string)($block['text'] ?? '');
            }
        }
    }
}
$hasToolResultAfterUser = false;
foreach ($messages as $index => $message) {
    if ($index > $lastUserIndex && ($message['role'] ?? '') === 'tool') {
        $hasToolResultAfterUser = true;
        break;
    }
}

// Decide on the assistant turn.
$toolCalls = null;
if (str_contains($userText, 'E2E-TOOL') && !$hasToolResultAfterUser) {
    $toolCalls = [[
        'index' => 0,
        'id' => 'call_e2e_getpage',
        'type' => 'function',
        'function' => ['name' => 'GetPage', 'arguments' => '{"uid": 1}'],
    ]];
    $text = null;
} elseif (str_contains($userText, 'E2E-TOOL')) {
    $text = 'FAKE-LLM-TOOL-DONE: I inspected the page with the GetPage tool.';
} else {
    $text = 'FAKE-LLM-REPLY: Hello from the fake LLM. You said: ' . mb_substr($userText, 0, 200);
}
$finishReason = $toolCalls !== null ? 'tool_calls' : 'stop';

$id = 'chatcmpl-fake';
$created = 1700000000;

if (!$stream) {
    header('Content-Type: application/json');
    $assistantMessage = ['role' => 'assistant', 'content' => $text];
    if ($toolCalls !== null) {
        $assistantMessage['tool_calls'] = array_map(static function (array $tc): array {
            unset($tc['index']);
            return $tc;
        }, $toolCalls);
    }
    echo json_encode([
        'id' => $id,
        'object' => 'chat.completion',
        'created' => $created,
        'model' => $model,
        'choices' => [[
            'index' => 0,
            'message' => $assistantMessage,
            'finish_reason' => $finishReason,
        ]],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
    ]);
    return;
}

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');

$emit = static function (array $delta, ?string $finish = null) use ($id, $created, $model): void {
    echo 'data: ' . json_encode([
        'id' => $id,
        'object' => 'chat.completion.chunk',
        'created' => $created,
        'model' => $model,
        'choices' => [[
            'index' => 0,
            'delta' => $delta,
            'finish_reason' => $finish,
        ]],
    ]) . "\n\n";
    flush();
};

$emit(['role' => 'assistant']);
if ($toolCalls !== null) {
    // Split the tool call like real providers do: name first, then arguments.
    $emit(['tool_calls' => [[
        'index' => 0,
        'id' => $toolCalls[0]['id'],
        'type' => 'function',
        'function' => ['name' => $toolCalls[0]['function']['name'], 'arguments' => ''],
    ]]]);
    $emit(['tool_calls' => [[
        'index' => 0,
        'function' => ['arguments' => $toolCalls[0]['function']['arguments']],
    ]]]);
} else {
    // Split the text into a few chunks so streaming rendering is exercised.
    foreach (str_split($text, 24) as $chunk) {
        $emit(['content' => $chunk]);
    }
}
$emit([], $finishReason);
echo "data: [DONE]\n\n";
flush();
