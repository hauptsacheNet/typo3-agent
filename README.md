# TYPO3 AI Agent Extension

Integrates an AI agent directly into TYPO3 by calling the MCP ToolRegistry natively from PHP and communicating with an OpenAI-compatible LLM API. Editors create task records in the List module, and a CLI command processes them through an agent loop.

## Installation

```bash
composer require hn/typo3-agent
```

Then activate the extension in TYPO3 backend or via CLI:

```bash
bin/typo3 extension:activate agent
```

## Configuration

Go to **Settings > Extension Configuration > agent** and configure:

| Setting | Default | Description |
|---------|---------|-------------|
| `apiUrl` | `https://openrouter.ai/api/v1/` | OpenAI-compatible API base URL |
| `apiKey` | *(empty)* | API key for authentication |
| `model` | `anthropic/claude-haiku-4-5` | Model identifier |
| `systemPrompt` | *(built-in)* | System prompt for the agent |
| `maxIterations` | `20` | Safety limit for the agent loop |

As soon as `apiUrl` and `apiKey` are filled in, the **Model** field turns into
a dropdown populated live from the provider's `/models` endpoint (no save
required — the backend proxies the call to avoid CORS and keep the key out of
cross-origin traffic). If the endpoint is unreachable or unsupported, the
field falls back to a free-text input so you can type the model ID manually.

### Provider Examples

**OpenRouter** (default — access to many models):
```
apiUrl: https://openrouter.ai/api/v1/
model:  anthropic/claude-haiku-4-5
```
Sign up at [openrouter.ai](https://openrouter.ai/) and create a key.

**OpenAI**:
```
apiUrl: https://api.openai.com/v1/
model:  gpt-4.1
```
Keys from [platform.openai.com](https://platform.openai.com/api-keys).

**Mittwald AI** (GDPR-compliant hosting in Germany):
```
apiUrl: https://api.openai.mittwald.de/v1/
model:  (pick from dropdown once the key is entered)
```
Available via mStudio for Mittwald customers. The endpoint is OpenAI-compatible
and data stays in the EU — a good fit when OpenRouter / OpenAI raise data
protection concerns.

**Any OpenAI-compatible API**:
```
apiUrl: https://your-provider.com/v1/
model:  your-model-id
```
The extension only requires a provider that speaks the OpenAI chat-completions
protocol (including tool calling and SSE streaming). No code changes needed.

## Usage

### Creating Tasks

1. Open the **List module** in TYPO3 backend
2. Navigate to the page you want the agent to work on
3. Create a new **Agent Task** record
4. Fill in the **Title** and **Prompt** fields
5. Save the record (status defaults to "Pending")

The task's `pid` (page ID) provides context — the agent automatically loads information about that page.
The task's `cruser_id` determines which backend user's permissions the agent uses for tool execution.

**Note:** TYPO3 13.4 no longer auto-populates `cruser_id` via DataHandler. When `cruser_id` is 0 (default), the agent falls back to admin context. To run the agent as a specific user, set `cruser_id` manually on the task record.

### Running the Agent

Process all pending tasks:

```bash
bin/typo3 agent:run
```

Process a specific task by UID:

```bash
bin/typo3 agent:run --task=42
```

Limit the number of tasks processed:

```bash
bin/typo3 agent:run --limit=5
```

### Task Lifecycle

| Status | Value | Description |
|--------|-------|-------------|
| Pending | 0 | Ready to be picked up by `agent:run` |
| In Progress | 1 | Currently being processed |
| Ended | 2 | Agent finished, result available |
| Failed | 3 | Error occurred, messages preserved |

### Resuming Failed Tasks

When a task fails, its conversation state (messages) is preserved. To resume:

1. Fix the underlying issue (API key, network, etc.)
2. Set the task status back to "Pending" in the backend
3. Run `agent:run` again — it resumes from the last saved messages

Or resume directly: `bin/typo3 agent:run --task=42`

### Scheduler

The `agent:run` command is schedulable — you can set it up as a recurring task in the TYPO3 Scheduler to automatically process pending tasks.

## Architecture

The extension uses the `ToolRegistry` from `hn/typo3-mcp-server` to access TYPO3 tools (GetPage, GetPageTree, Search, ReadTable, WriteTable, etc.) natively from PHP without MCP protocol overhead.

The `messages` JSON field in each task record stores the full OpenAI messages array — the complete conversation state. This enables resumability and future chat-like interfaces.

## Development

Run the functional tests:

```bash
composer install
composer test
```
