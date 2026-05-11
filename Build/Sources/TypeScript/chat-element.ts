import {html, LitElement, nothing, type TemplateResult} from 'lit';
import {customElement, property, query, state} from 'lit/decorators.js';
import {unsafeHTML} from 'lit/directives/unsafe-html.js';
import {marked} from 'marked';
import DOMPurify from 'dompurify';

marked.setOptions({breaks: true, gfm: true});

// ---- Types ----------------------------------------------------------------

interface ChatMessage {
  role: 'user' | 'assistant' | 'system' | 'tool';
  content?: string;
  tool_calls?: ToolCall[];
  tool_call_id?: string;
}

interface ToolCall {
  id?: string;
  function: {name: string; arguments: string};
  result?: string;
}

interface ToolProgress {
  toolName: string;
}

interface SseParsed {
  event: string;
  data: Record<string, unknown>;
}

// ---- Component -------------------------------------------------------------

@customElement('hn-agent-chat')
export class ChatElement extends LitElement {

  // No Shadow DOM — use TYPO3 backend Bootstrap CSS
  override createRenderRoot() {
    return this;
  }

  // -- Properties (HTML attributes, set by Fluid template) -------------------

  @property({attribute: 'send-uri'}) sendUri = '';
  @property({attribute: 'stream-uri'}) streamUri = '';
  @property({attribute: 'auto-start'}) autoStart = '';
  @property({attribute: 'initial-prompt'}) initialPrompt = '';

  @property({
    attribute: 'initial-messages',
    converter: {
      fromAttribute(value: string | null): ChatMessage[] {
        if (!value) return [];
        try {
          return JSON.parse(value) as ChatMessage[];
        } catch {
          return [];
        }
      },
    },
  })
  initialMessages: ChatMessage[] = [];

  // -- Internal state --------------------------------------------------------

  @state() private messages: ChatMessage[] = [];
  @state() private inputValue = '';
  @state() private loading = false;
  @state() private errorMessage = '';
  @state() private thinking = false;
  @state() private streamingBuffer = '';
  @state() private isStreaming = false;
  @state() private activeTools: Map<string, ToolProgress> = new Map();

  @query('textarea') private inputEl!: HTMLTextAreaElement;

  // -- Lifecycle -------------------------------------------------------------

  override firstUpdated(): void {
    this.messages = this.mergeToolResults(this.initialMessages);
    this.scrollToBottom();

    if ((this.autoStart === '1' || this.autoStart === 'true') && this.streamUri) {
      this.doAutoStart();
    }
  }

  override updated(): void {
    this.scrollToBottom();
  }

  // -- Render ----------------------------------------------------------------

  override render() {
    return html`
      <div class="chat-container" style="display:flex;flex-direction:column;gap:1rem;max-width:900px;">
        <div class="chat-messages" style="border:1px solid #ddd;border-radius:4px;padding:1rem;background:#fafafa;min-height:300px;max-height:60vh;overflow-y:auto;">
          ${this.messages.map(msg => this.renderMessage(msg))}
          ${this.renderActiveTools()}
          ${this.isStreaming ? this.renderStreamingBubble() : nothing}
          ${this.thinking && !this.isStreaming ? this.renderThinkingIndicator() : nothing}
        </div>

        <form style="display:flex;gap:0.5rem;" @submit=${this.onSubmit}>
          <textarea
            name="message"
            class="form-control"
            rows="2"
            placeholder="Type a follow-up message\u2026"
            .value=${this.inputValue}
            ?disabled=${this.loading}
            @input=${this.onInput}
            @keydown=${this.onKeydown}
            required
          ></textarea>
          <button type="submit" class="btn btn-primary" ?disabled=${this.loading}>
            ${this.loading ? 'Thinking\u2026' : 'Send'}
          </button>
        </form>

        ${this.errorMessage
          ? html`<div class="alert alert-danger">${this.errorMessage}</div>`
          : nothing}
      </div>

      <style>
        .chat-msg { margin-bottom: 0.75rem; padding: 0.5rem 0.75rem; border-radius: 6px; }
        .chat-msg-user { background: #d6e9ff; }
        .chat-msg-assistant { background: #fff; border: 1px solid #e0e0e0; }
        .chat-msg-tool { background: #f0f0f0; font-family: monospace; font-size: 0.85em; }
        .chat-msg-role { font-weight: bold; font-size: 0.8em; opacity: 0.7; margin-bottom: 0.25rem; text-transform: uppercase; }
        .chat-toolcall { margin-top: 0.5rem; padding: 0.4rem; background: #fffae6; border-left: 3px solid #f0c000; font-family: monospace; font-size: 0.85em; }
        .chat-toolcall summary { cursor: pointer; }
        .chat-msg pre { white-space: pre-wrap; margin: 0; }
        .chat-msg-content p:first-child { margin-top: 0; }
        .chat-msg-content p:last-child { margin-bottom: 0; }
        .chat-msg-content pre {
          background: #f5f5f5;
          padding: 0.5rem 0.75rem;
          border-radius: 4px;
          overflow-x: auto;
          white-space: pre-wrap;
        }
        .chat-msg-content code {
          background: #f0f0f0;
          padding: 0.1em 0.3em;
          border-radius: 3px;
          font-size: 0.9em;
        }
        .chat-msg-content pre code { background: transparent; padding: 0; }
        .chat-msg-content ul,
        .chat-msg-content ol { margin: 0.25rem 0 0.25rem 1.25rem; }
        .chat-msg-content h1,
        .chat-msg-content h2,
        .chat-msg-content h3 { margin: 0.5rem 0 0.25rem; font-size: 1.05em; }
        .chat-msg-content table { border-collapse: collapse; margin: 0.5rem 0; }
        .chat-msg-content th,
        .chat-msg-content td { border: 1px solid #ddd; padding: 0.25rem 0.5rem; }
        .chat-thinking-dots { opacity: 0.6; font-style: italic; }
      </style>
    `;
  }

  private renderMessage(msg: ChatMessage): TemplateResult | typeof nothing {
    const role = msg.role || 'unknown';
    if (role === 'system') return nothing;
    const roleLabel = role === 'user' ? 'you' : role;

    if (role === 'assistant') {
      return html`
        <div class="chat-msg chat-msg-assistant">
          <div class="chat-msg-role">${roleLabel}</div>
          ${msg.content
            ? html`<div class="chat-msg-content">${unsafeHTML(this.renderMarkdown(msg.content))}</div>`
            : nothing}
          ${msg.tool_calls?.map(tc => this.renderToolCall(tc)) ?? nothing}
        </div>
      `;
    }

    if (role === 'tool') {
      return html`
        <div class="chat-msg chat-msg-tool">
          <details>
            <summary class="chat-msg-role">tool result</summary>
            <pre>${msg.content ?? ''}</pre>
          </details>
        </div>
      `;
    }

    // user, system, unknown
    return html`
      <div class="chat-msg chat-msg-${role}">
        <div class="chat-msg-role">${roleLabel}</div>
        <pre>${msg.content ?? ''}</pre>
      </div>
    `;
  }

  private renderToolCall(tc: ToolCall): TemplateResult {
    return html`
      <details class="chat-toolcall">
        <summary>
          <typo3-backend-icon identifier="actions-rocket" size="small"></typo3-backend-icon>
          ${tc.function?.name ?? 'unknown'}</summary>
        <div>
            <strong>Args</strong><br/>
            <code>${tc.function?.arguments ?? ''}</code>
        </div>
        ${tc.result !== undefined
          ? html`<div><strong>Result</strong><br/>
            <pre>${tc.result}</pre></div>`
          : nothing}
      </details>
    `;
  }

  private renderStreamingBubble(): TemplateResult {
    return html`
      <div class="chat-msg chat-msg-assistant chat-msg-streaming">
        <div class="chat-msg-role">assistant</div>
        <div class="chat-msg-content">
          ${unsafeHTML(this.renderMarkdown(this.streamingBuffer))}
        </div>
      </div>
    `;
  }

  private renderThinkingIndicator(): TemplateResult {
    return html`
      <div class="chat-msg chat-msg-assistant">
        <div class="chat-msg-role">assistant</div>
        <div class="chat-thinking-dots">Thinking\u2026</div>
      </div>
    `;
  }

  private renderActiveTools(): TemplateResult | typeof nothing {
    if (this.activeTools.size === 0) return nothing;

    return html`
      ${[...this.activeTools.entries()].map(([id, p]) => html`
        <div class="chat-msg chat-msg-tool" data-tool-call-id=${id}>
          <div class="chat-msg-role">tool</div>
          <div class="chat-tool-status">\u2699\uFE0F Executing: ${p.toolName}\u2026</div>
        </div>
      `)}
    `;
  }

  // -- Markdown --------------------------------------------------------------

  private renderMarkdown(text: string): string {
    return DOMPurify.sanitize(marked.parse(text ?? '') as string);
  }

  // -- Event handlers --------------------------------------------------------

  private onSubmit(e: Event): void {
    e.preventDefault();
    const message = this.inputValue.trim();
    if (!message) return;

    this.errorMessage = '';
    this.messages = [...this.messages, {role: 'user', content: message}];
    this.inputValue = '';
    this.loading = true;

    if (this.streamUri) {
      this.sendStreaming(message).then(() => this.finishSend());
    } else {
      this.sendBlocking(message).then(() => this.finishSend());
    }
  }

  private onInput(e: Event): void {
    this.inputValue = (e.target as HTMLTextAreaElement).value;
  }

  private onKeydown(e: KeyboardEvent): void {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      (e.target as HTMLTextAreaElement).closest('form')?.requestSubmit();
    }
  }

  private finishSend(): void {
    this.loading = false;
    this.inputEl?.focus();
  }

  // -- Auto-start ------------------------------------------------------------

  private async doAutoStart(): Promise<void> {
    if (this.initialPrompt) {
      this.messages = [...this.messages, {role: 'user', content: this.initialPrompt}];
    }
    this.loading = true;
    await this.sendStreaming('');
    this.finishSend();
  }

  // -- Network: blocking -----------------------------------------------------

  private async sendBlocking(message: string): Promise<void> {
    try {
      const formData = new FormData();
      formData.append('message', message);

      const response = await fetch(this.sendUri, {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json',
        },
        body: formData,
      });

      const data = await response.json();

      if (!response.ok || data.error) {
        this.errorMessage = data.error || `Request failed (${response.status})`;
      }

      if (Array.isArray(data.messages)) {
        this.messages = data.messages as ChatMessage[];
      }
    } catch (err) {
      this.errorMessage = (err as Error).message || String(err);
    }
  }

  // -- Network: streaming (SSE) ----------------------------------------------

  private async sendStreaming(message: string): Promise<void> {
    try {
      const formData = new FormData();
      formData.append('message', message);

      const response = await fetch(this.streamUri, {
        method: 'POST',
        body: formData,
      });

      if (!response.ok) {
        this.errorMessage = `Request failed (${response.status})`;
        return;
      }

      const reader = response.body!.getReader();
      const decoder = new TextDecoder();
      let buffer = '';

      while (true) {
        const {done, value} = await reader.read();
        if (done) break;

        buffer += decoder.decode(value, {stream: true});

        const result = this.parseSseBuffer(buffer);
        buffer = result.remainder;

        for (const evt of result.parsed) {
          this.handleSseEvent(evt.event, evt.data);
        }
      }
    } catch (err) {
      this.thinking = false;
      this.isStreaming = false;
      this.errorMessage = (err as Error).message || String(err);
    }
  }

  // -- SSE parsing -----------------------------------------------------------

  private parseSseBuffer(buffer: string): {parsed: SseParsed[]; remainder: string} {
    const parsed: SseParsed[] = [];
    const blocks = buffer.split('\n\n');
    const remainder = blocks.pop()!;

    for (const block of blocks) {
      if (!block.trim()) continue;
      let event = 'message';
      let data = '';
      for (const line of block.split('\n')) {
        if (line.startsWith('event: ')) {
          event = line.slice(7);
        } else if (line.startsWith('data: ')) {
          data = line.slice(6);
        }
      }
      if (data) {
        try {
          parsed.push({event, data: JSON.parse(data) as Record<string, unknown>});
        } catch {
          // skip malformed
        }
      }
    }
    return {parsed, remainder};
  }

  // -- SSE event dispatch ----------------------------------------------------

  private handleSseEvent(event: string, data: Record<string, unknown>): void {
    switch (event) {
      case 'llm_start':
        this.thinking = true;
        break;

      case 'content_delta':
        this.thinking = false;
        this.isStreaming = true;
        this.streamingBuffer += (data.text as string) || '';
        break;

      case 'tool_call_delta':
        this.thinking = false;
        break;

      case 'assistant_message': {
        this.thinking = false;
        const msg = data.message as ChatMessage | undefined;

        if (this.isStreaming) {
          if (Array.isArray(msg?.tool_calls) && msg!.tool_calls.length > 0) {
            // Replace streaming bubble with finalized message including tool_calls
            this.messages = [...this.messages, msg!];
          } else {
            // Finalize with server content (trusted) or streamed buffer
            this.messages = [...this.messages, {
              role: 'assistant',
              content: msg?.content || this.streamingBuffer,
            }];
          }
          this.isStreaming = false;
          this.streamingBuffer = '';
        } else if (msg) {
          this.messages = [...this.messages, msg];
        }
        break;
      }

      case 'tool_start': {
        const toolName = data.tool_name as string;
        const toolCallId = data.tool_call_id as string;
        const next = new Map(this.activeTools);
        next.set(toolCallId, {toolName});
        this.activeTools = next;
        break;
      }

      case 'tool_result': {
        const toolCallId = data.tool_call_id as string;
        const content = data.content as string;

        // Attach result to the matching tool_call in the assistant message
        this.messages = this.messages.map(msg => {
          if (msg.role !== 'assistant' || !msg.tool_calls) return msg;
          const match = msg.tool_calls.some(tc => tc.id === toolCallId);
          if (!match) return msg;
          return {
            ...msg,
            tool_calls: msg.tool_calls.map(tc =>
              tc.id === toolCallId ? {...tc, result: content} : tc
            ),
          };
        });

        // Remove from active (pending) tools
        const next = new Map(this.activeTools);
        next.delete(toolCallId);
        this.activeTools = next;
        break;
      }

      case 'done':
        this.thinking = false;
        this.isStreaming = false;
        // Move completed tools out of activeTools — they are already rendered inline
        this.activeTools = new Map();
        break;

      case 'error':
        this.thinking = false;
        this.isStreaming = false;
        this.errorMessage = (data.error as string) || 'Unknown error';
        break;
    }
  }

  // -- Helpers ---------------------------------------------------------------

  /**
   * Merge tool-role messages into the tool_calls of their parent assistant
   * message so that call + result are rendered in the same bubble.
   */
  private mergeToolResults(msgs: ChatMessage[]): ChatMessage[] {
    // Collect tool results keyed by tool_call_id
    const resultMap = new Map<string, string>();
    for (const msg of msgs) {
      if (msg.role === 'tool' && msg.tool_call_id && msg.content !== undefined) {
        resultMap.set(msg.tool_call_id, msg.content);
      }
    }

    if (resultMap.size === 0) return [...msgs];

    const merged: ChatMessage[] = [];
    for (const msg of msgs) {
      if (msg.role === 'tool' && msg.tool_call_id && resultMap.has(msg.tool_call_id)) {
        // Skip — result is inlined into the assistant message's tool_call
        continue;
      }

      if (msg.role === 'assistant' && msg.tool_calls) {
        merged.push({
          ...msg,
          tool_calls: msg.tool_calls.map(tc => {
            const result = tc.id ? resultMap.get(tc.id) : undefined;
            return result !== undefined ? {...tc, result} : tc;
          }),
        });
      } else {
        merged.push(msg);
      }
    }
    return merged;
  }

  private scrollToBottom(): void {
    const el = this.renderRoot.querySelector('.chat-messages');
    if (el) {
      el.scrollTop = el.scrollHeight;
    }
  }
}
