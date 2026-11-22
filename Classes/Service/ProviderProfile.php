<?php

declare(strict_types=1);

namespace Hn\Agent\Service;

/**
 * Request quirks of a known LLM provider, derived from the configured apiUrl.
 *
 * All supported providers speak the OpenAI chat-completions protocol, but
 * they differ in two places that matter for the agent:
 *
 * - Server tools: tools the provider executes itself (e.g. OpenRouter's
 *   web_fetch). Sending one to a provider that does not know it fails the
 *   request, so they are only added for providers listed here.
 * - Reasoning: OpenRouter expects `reasoning: {effort}`, the OpenAI-style
 *   providers a top-level `reasoning_effort`.
 *
 * Unknown hosts get the plain OpenAI-compatible profile: no server tools,
 * `reasoning_effort`. Researched 2026-11 — provider docs:
 * https://openrouter.ai/docs/guides/features/server-tools,
 * https://docs.mistral.ai/studio/agents/agent-tools/websearch,
 * https://docs.cortecs.ai/routing/reasoning.
 */
final class ProviderProfile
{
    public const REASONING_OBJECT = 'reasoning_object';
    public const REASONING_EFFORT_PARAM = 'reasoning_effort';

    /**
     * @param array<string, array<string, mixed>> $serverTools Tool definitions keyed by the
     *        extension setting that enables them (e.g. 'webFetch').
     */
    private function __construct(
        public readonly string $name,
        public readonly array $serverTools,
        public readonly string $reasoningStyle,
    ) {}

    public static function forApiUrl(string $apiUrl): self
    {
        $host = strtolower((string)parse_url(trim($apiUrl), PHP_URL_HOST));

        return match (true) {
            // Regional endpoints have no web_fetch engine and reject requests containing it with HTTP 403.
            in_array($host, ['eu.openrouter.ai', 'us.openrouter.ai'], true)
                => new self('OpenRouter (regional)', [], self::REASONING_OBJECT),
            $host === 'openrouter.ai' || str_ends_with($host, '.openrouter.ai')
                => new self('OpenRouter', ['webFetch' => ['type' => 'openrouter:web_fetch']], self::REASONING_OBJECT),
            // Web search exists only in their Responses/Agents APIs, not in chat completions.
            $host === 'api.openai.com' => new self('OpenAI', [], self::REASONING_EFFORT_PARAM),
            $host === 'api.mistral.ai' => new self('Mistral', [], self::REASONING_EFFORT_PARAM),
            $host === 'api.cortecs.ai' => new self('Cortecs', [], self::REASONING_EFFORT_PARAM),
            default => new self('OpenAI-compatible', [], self::REASONING_EFFORT_PARAM),
        };
    }

    public function supportsServerTool(string $setting): bool
    {
        return isset($this->serverTools[$setting]);
    }
}
