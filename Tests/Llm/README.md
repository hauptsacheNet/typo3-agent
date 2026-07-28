# LLM Tests for the Agent Extension

These tests run the **real agent loop** (`AgentService::run()`) against a
**real language model** via OpenRouter. They verify that an actual LLM
understands the tool schemas exposed by this extension and drives them the
way we intend — the control criterion for shipping new tools and workflows.

They complement two cheaper layers:

| Layer | What it proves | Cost |
|---|---|---|
| `Tests/Functional` | Loop mechanics with a scripted `LlmService` mock | free |
| `Build/tests/playwright` | Browser UI against a deterministic fake LLM | free |
| `Tests/Llm` (this) | A real model actually uses the tools correctly | ~cents/run |

## Running

```bash
export OPENROUTER_API_KEY="sk-or-v1-..."   # or put it into .env.local
composer test:llm
composer test:llm -- --filter testCreatesPageViaWriteTable
```

Without `OPENROUTER_API_KEY` the suite skips instead of failing.

## Model selection

Default model is `openai/gpt-oss-120b` — the cheapest tool-capable model
from the roster battle-tested in `hn/typo3-mcp-server`'s LLM suite
(~$0.03/M input, ~$0.15/M output tokens on OpenRouter as of 2026-07).
Override per run:

```bash
LLM_TEST_MODEL="anthropic/claude-haiku-4.5" composer test:llm
```

## Writing tests

- Extend `AgentLlmTestCase` and call `runAgentTask($prompt)` — it creates a
  task exactly like `ChatController::newAction` and runs the full loop.
- Write realistic prompts. No UIDs, no tool names, no hints.
- Assert on three levels: task ended cleanly (`assertTaskEnded`), the right
  tool was used (`assertToolCalled`), and the real-world effect happened
  (query the database / check the final answer).
- Tests auto-retry up to 3 times on assertion failure because LLM output is
  non-deterministic. A test that needs all 3 attempts regularly is a signal
  that a tool description is unclear — fix the tool, not the test.
