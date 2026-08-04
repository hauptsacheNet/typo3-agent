# TYPO3 AI Agent Extension

Integrates an AI agent directly into TYPO3 by calling the MCP `ToolRegistry` natively from PHP and talking to an OpenAI-compatible LLM API. Editors work with the agent through a dedicated backend chat module; a CLI command additionally processes pending task records for scheduled/batch runs.

## Installation

```bash
composer require hn/typo3-agent
```

Then activate the extension in TYPO3 backend or via CLI:

```bash
bin/typo3 extension:activate agent
```

For non-composer TYPO3 installations the TER package bundles the required PHP libraries (PhpOffice, `smalot/pdfparser`); a fallback autoloader in `ext_localconf.php` loads them when no composer autoloader has already provided them.

## Configuration

Go to **Settings > Extension Configuration > agent** and configure:

| Setting | Default | Description |
|---------|---------|-------------|
| `apiUrl` | `https://openrouter.ai/api/v1/` | OpenAI-compatible API base URL |
| `apiKey` | *(empty)* | API key for authentication |
| `model` | `anthropic/claude-haiku-4.5` | Model identifier (dropdown: Claude Opus 4.8 / Claude Haiku 4.5) |
| `systemPrompt` | *(built-in)* | System prompt for the agent |
| `maxIterations` | `20` | Safety limit for the agent loop |
| `reasoningEffort` | `medium` | Extended-reasoning strength (`off`, `low`, `medium`, `high`) |
| `webFetch` | `1` | Enable OpenRouter's server-side `web_fetch` tool so the agent can retrieve and read URLs (executed by OpenRouter, no local handler needed) |

### Provider Examples

The `model` field ships as a curated dropdown. To use another provider or model ID, adjust `ext_conf_template.txt` (or ship a site-specific override).

**OpenRouter** (default — access to many models):
```
apiUrl: https://openrouter.ai/api/v1/
model: anthropic/claude-haiku-4.5
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

### Backend Chat Module

The primary UX is the **Web > TYPO3 Agent Tasks** backend module. It offers:

- An **overview of the agent instructions** that shape the assistant's behavior (see the Agent Instructions section below).
- An **overview of chats scoped to the current page**, 
- An **overview of chats on any subpage** 
- An **individual chat view** that shows the full conversation together with the **associated workspace changes** — the records the agent touched in the current workspace.

### Attachments & Multimodal

Files can be dragged onto the composer or picked via the file browser. The chat UI pre-flights each attachment against an allowlist and size cap so users see up-front whether a file will be embedded as content for the LLM or only referenced by metadata.

> **Prerequisite for uploads:** the composer uses TYPO3 Core's file-upload AJAX endpoints (`/typo3/ajax/file/exists` and `/typo3/ajax/file/process`), whose access is inherited from the **file** module (`media_management`). BE groups whose members should be able to upload attachments must therefore include this module in their allowed modules. Existing attachments already stored in FAL can still be referenced without this — only the drag-drop/upload path is affected.
>
> Composer uploads are written into a non-public **agent scratch storage** (`var/agent-scratch/user_{beUserUid}/`, `is_public=0`); they are not web-reachable until the agent promotes them into a regular FAL folder via the `PromoteScratchFile` tool (which happens automatically when the agent references the file in a record).

| Kind | Formats | Size cap |
|------|---------|----------|
| Image | PNG, JPEG, WebP, GIF | 5 MB |
| PDF | PDF | 50 MB |
| Spreadsheet | XLSX, ODS, XLS, CSV | 25 MB |
| Document | DOCX, ODT, RTF, TXT, Markdown, HTML | 25 MB |
| Presentation | PPTX, ODP | 25 MB |

Attachments enter the conversation as multimodal tool messages via the `ReadFile` tool listed under **Architecture** below (image bytes inline, extracted text/pages for PDF and Office files).

### Creating Tasks Directly (List Module / CLI)

For scheduled or automated runs — batch seeding, cron-driven workflows, external triggers — you can also create task records directly:

1. Open the **List module** in TYPO3 backend
2. Navigate to the page you want the agent to work on
3. Create a new **Agent Task** record
4. Fill in the **Title** and **Prompt** fields
5. Save the record (status defaults to "Pending")

The task's `pid` (page ID) provides context — the agent automatically loads information about that page.
The task's `cruser_id` determines which backend user's permissions the agent uses for tool execution.

**Note:** TYPO3 13.4 no longer auto-populates `cruser_id` via DataHandler. When `cruser_id` is 0 (default), the agent falls back to admin context. To run the agent as a specific user, set `cruser_id` manually on the task record.

### Agent Instructions

Editors can maintain reusable **instructions** for the agent — tone of voice,
wording rules, or how to handle specific content elements or records — as
**Agent Instruction** records (`tx_agent_instruction`). This follows the
progressive-disclosure idea of the [SKILL.md open standard](https://agentskills.io):
the agent is its own harness, so the extension implements the pattern itself
(provider-neutral — the OpenAI-compatible chat API has no native skills parameter).

Each instruction has a **mode**:

- **Always** — the body is appended to the agent's system prompt for every new
  chat (global base rules). Use sparingly for guidance that always applies.
- **On demand** — only the name and the "when to use" hint go into the prompt as
  a short index; the agent loads the full body via the `GetInstruction` tool when
  it is about to produce the relevant kind of content. This keeps the prompt
  small and scales to many instructions.

**Note:** Instructions are baked into the conversation at creation time, so they
apply to newly started chats — chats already in progress keep the prompt they
were created with.

Each record has:

| Field | Description |
|-------|-------------|
| Name | Short label, shown in the prompt index and the read-only panel |
| Mode | `Always` (in every prompt) or `On demand` (loaded via `GetInstruction`) |
| When to use | Short hint shown in the prompt index so the agent knows when an on-demand instruction applies |
| Instruction content | The guidance itself — authored as rich text; converted to plain text/Markdown before it reaches the LLM |
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

**Viewing:** the instructions appear in a collapsible panel at the top of the AI
chat (both the chat list and individual chats), and — like any record — in the
**List module**.

### Running the Agent from the CLI

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

State transitions run through `Classes/Domain/TaskStateMachine.php`.

| Status | Value | Description |
|--------|-------|-------------|
| Pending | 0 | Ready to be picked up by `agent:run` |
| In Progress | 1 | Currently being processed |
| Ended | 2 | Agent finished, result available |
| Failed | 3 | Error occurred, messages preserved |
| Cancelled | 4 | Run was canceled before completion |

### Resuming Failed Tasks

When a task fails, its conversation state (messages) is preserved. To resume:

1. Fix the underlying issue (API key, network, etc.)
2. Set the task status back to "Pending" in the backend
3. Run `agent:run` again — it resumes from the last saved messages

Or resume directly: `bin/typo3 agent:run --task=42`

### Scheduler

The `agent:run` command is schedulable — you can set it up as a recurring task in the TYPO3 Scheduler to automatically process pending tasks.

### Workspaces

Agent tasks are workspace-aware: each task record stores a `workspace_id` (set automatically) and the agent runs against that workspace's data. Two event listeners hook into the workspace lifecycle: one clears change tracking on publish, the other filters the workspace diff view to the records the task actually touched. In the chat UI, the workspace-changes drawer surfaces those tracked records inline.

## Architecture

The extension uses the `ToolRegistry` from `hn/typo3-mcp-server` to access the core TYPO3 tools (GetPage, GetPageTree, Search, ReadTable, WriteTable, …) natively from PHP without MCP protocol overhead.

On top of that it ships its own agent tools under `Classes/MCP/Tool/`:

- `ReadFileTool` — the single file-reading tool. Reads any FAL file by sys_file UID and dispatches on its MIME type: images come back as inline image content (downscaled if needed), PDFs as per-page text (or one page rendered as image via `format=image`, using `smalot/pdfparser` / the Ghostscript pipeline), spreadsheets/documents/presentations as structured text via PhpOffice, and every other file type as metadata. One tool instead of seven format-specific ones keeps tool selection trivial, especially for smaller models.
- `GetInstructionTool` — progressive disclosure of on-demand instruction bodies.
- `ExtractImagesTool` — pulls raster images embedded in Office/PDF files into the scratch storage.
- `PromoteScratchFileTool` — copies scratch files into a public FAL folder for use in records.

These agent tools are registered with a dedicated `agent.tool` DI tag and merged with the MCP core tools in `Hn\Agent\MCP\AgentToolRegistry`, which only the agent loop consumes. They are deliberately **not** tagged `mcp.tool`, so they do not appear in the `ToolRegistry` that the `mcp_server` extension exposes to external MCP clients — they are designed around this extension's chat internals (scratch storage, multimodal tool messages, instruction records) and external agents bring their own file handling. To expose an individual tool over MCP anyway, tag its service with `mcp.tool` in `Configuration/Services.yaml`.

Each conversation turn is persisted as a `tx_agent_message` record (role, content, tool calls, attachments as `sys_file_reference` rows), linked to its task via the inline `chat_messages` relation. This enables resumability, the SSE streaming loop, and the chat UI's message history.

### Upgrading from the JSON-column era

Versions that stored the whole conversation in a `tx_agent_task.messages` JSON column are migrated by the **"Migrate agent task chat histories to tx_agent_message records"** upgrade wizard (`agent_migrateTaskMessages`). Run the database schema update first (it leaves the legacy `messages` column untouched — the new counter is deliberately named `chat_messages`), then execute the wizard in the Install Tool or via `bin/typo3 upgrade:run agent_migrateTaskMessages`. Afterwards the emptied `messages` column can be removed via the database analyzer.

## Development

Run the functional tests (SQLite via `typo3/testing-framework`):

```bash
composer install
composer test
```

The `bin/typo3 agent:repro-multimodal` command (see `Classes/Command/ReproMultimodalCommand.php`) probes which LLM providers accept multimodal tool results — useful when adding or debugging providers.

TER release builds run through `composer build:ter` (see `Build/build-ter.sh`) and a matching GitHub Actions workflow. Functional tests also run in CI across the supported PHP and TYPO3 matrix.
