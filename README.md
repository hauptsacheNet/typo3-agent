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

### Provider Examples

**OpenRouter** (default — access to many models):
```
apiUrl: https://openrouter.ai/api/v1/
model: anthropic/claude-haiku-4-5
```

**Scaleway**:
```
apiUrl: https://api.scaleway.ai/v1/
model: claude-haiku-4-5
```

**Any OpenAI-compatible API**:
```
apiUrl: https://your-provider.com/v1/
model: your-model-id
```

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
