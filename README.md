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

**OpenAI**:
```
apiUrl: https://api.openai.com/v1/
model: gpt-5.2
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

### Editorial Instructions

Editors can maintain reusable instructions for the agent — tone of voice,
wording rules, or how to handle specific content elements or records — as
**Agent Instruction** records (`tx_agent_instruction`). Every active record is
appended to the agent's system prompt when a **new** chat/task is started.

**Note:** Instructions are baked into the conversation at creation time, so
they apply to newly started chats — chats already in progress keep the prompt
they were created with.

Each record has:

| Field | Description |
|-------|-------------|
| Title | Short label, shown in the read-only instructions panel |
| Instruction | The guidance text injected into the system prompt |
| Hidden / Start / Stop | Standard visibility controls to stage or retire instructions without deleting them |

**Where to keep them:** create a dedicated folder (SysFolder) and store the
instruction records there.

**Restricting who may edit (native TYPO3 permissions):** the extension does
*not* hard-code a group. Instead, grant editing to the desired backend group
via the standard access mechanism:

1. Edit the backend group in **Backend Users > Groups**.
2. Under **Access Lists > Tables (modify)**, enable `Agent Instruction`.
3. Give the group access to the SysFolder that holds the records (DB mounts /
   page permissions).

Groups without modify rights can still *read* the instructions, so they remain
visible in the List module and in the chat info panel.

**Viewing:** the instructions appear in a collapsible panel at the top of the
AI chat (both the chat list and individual chats), and — like any record — in
the **List module**.

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
