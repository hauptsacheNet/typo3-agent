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
      <div class="chat-container d-flex flex-column message-fade" style="max-width:900px;">
        <div class="chat-messages d-flex flex-column gap-3 overflow-auto mx-3 pb-3"
             style="min-height:300px;max-height:60vh;">
          ${this.messages.map(msg => this.renderMessage(msg))}
          ${this.renderActiveTools()}
          ${this.isStreaming ? this.renderStreamingBubble() : nothing}
          ${this.thinking && !this.isStreaming ? this.renderThinkingIndicator() : nothing}
        </div>
  
        <form class="position-relative" @submit=${this.onSubmit}>
          <textarea
              name="message"
              class="d-block w-100 rounded-4 border p-3 bg-white"
              style="outline: none;field-sizing: content;resize: none;"
              rows="2"
              placeholder="Type a follow-up message\u2026"
              .value=${this.inputValue}
              ?disabled=${this.loading}
              @input=${this.onInput}
              @keydown=${this.onKeydown}
              required
          ></textarea>
          <div class="position-absolute bottom-0 end-0 p-2">
            <button type="submit" class="btn" ?disabled=${this.loading}>
              <typo3-backend-icon
                  identifier="actions-arrow-down-start-alt"
                  size="small"/>
            </button>
          </div>
        </form>

        ${this.errorMessage
            ? html`
              <div class="alert alert-danger">${this.errorMessage}</div>`
            : nothing}
      </div>

      <style>
        .message-fade {
          position: relative;
        }

        .message-fade > div {
          padding-top: 30px !important;
        }

        .message-fade::before {
          content: ' ';
          position: absolute;
          z-index: 1;
          display: block;
          top: 0;
          left: 0;
          width: 100%;
          height: 30px;
          background: var(--bs-light);
          /*noinspection CssInvalidPropertyValue,CssInvalidFunction*/
          -webkit-mask-image: -webkit-gradient(linear, left top, left bottom, from(rgba(0, 0, 0, 1)), to(rgba(0, 0, 0, 0)));
        }
        
      </style>
    `;
  }

  private renderMessage(msg: ChatMessage): TemplateResult | typeof nothing {
    const role = msg.role || 'unknown';
    if (role === 'system') return nothing;
    const roleLabel = role === 'user' ? 'you' : role;

    if (role === 'assistant') {
      return html`
        <div class="rounded-4 bg-white border p-3">
          <div class="chat-msg-role fw-bold small opacity-75 mb-1 text-uppercase">${roleLabel}</div>
          ${msg.content
            ? html`<div class="chat-msg-content">${unsafeHTML(this.renderMarkdown(msg.content))}</div>`
            : nothing}
          ${msg.tool_calls?.map(tc => this.renderToolCall(tc)) ?? nothing}
        </div>
      `;
    }


    // user, system, unknown
    return html`
      <div class="rounded-4 bg-success-subtle border p-3 align-self-end">
        <div class="chat-msg-role fw-bold small opacity-75 mb-1 text-uppercase">${roleLabel}</div>
        <pre class="m-0">${msg.content ?? ''}</pre>
      </div>
    `;
  }

  private renderToolCall(tc: ToolCall): TemplateResult {
    return html`
      <details class="bg-warning-subtle mt-2 p-2 border-start border-3 border-warning font-monospace small">
        <summary>
          ${tc.function?.name ?? 'unknown'}
        </summary>
        <div class="py-3">

          <div class="mb-3">
            <strong>Args</strong><br/>
            <code>${tc.function?.arguments ?? ''}</code>
          </div>
          ${tc.result !== undefined
              ? html`
                <div>
                  <strong>Result</strong><br/>
                  <pre class="m-0">${tc.result}</pre>
                </div>`
              : nothing}
        </div>
      </details>
    `;
  }

  private renderStreamingBubble(): TemplateResult {
    return html`
      <div class="rounded-4 bg-white border p-3">
        <div class="chat-msg-role fw-bold small opacity-75 mb-1 text-uppercase">assistant</div>
        <div class="chat-msg-content">
          ${unsafeHTML(this.renderMarkdown(this.streamingBuffer))}
        </div>
      </div>
    `;
  }

  private renderThinkingIndicator(): TemplateResult {
    return html`
      <div class="p-3 align-self-start">
        <div class="fst-italic">Thinking\u2026</div>
      </div>
    `;
  }

  private renderActiveTools(): TemplateResult | typeof nothing {
    if (this.activeTools.size === 0) return nothing;

    return html`
      ${[...this.activeTools.entries()].map(([id, p]) => html`
        <div class="chat-msg chat-msg-tool rounded bg-light font-monospace small" data-tool-call-id=${id}>
          <div class="chat-msg-role fw-bold small opacity-75 mb-1 text-uppercase">tool</div>
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
      this.thinking = true;
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
