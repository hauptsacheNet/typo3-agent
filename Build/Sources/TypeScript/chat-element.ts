import {html, LitElement, nothing, type TemplateResult} from 'lit';
import {customElement, property, query, state} from 'lit/decorators.js';
import {unsafeHTML} from 'lit/directives/unsafe-html.js';
import {marked} from 'marked';
import DOMPurify from 'dompurify';
import '@typo3/backend/drag-uploader.js';
import Modal from '@typo3/backend/modal.js';
import {MessageUtility} from '@typo3/backend/utility/message-utility.js';

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

interface TrackedChange {
  tablename: string;
  record_uid: number;
  workspace_record_uid: number;
  page_id: number;
  workspace_page_id: number;
  task_uid?: number;
}

interface SseParsed {
  event: string;
  data: Record<string, unknown>;
}

interface Attachment {
  uid?: number;
  identifier?: string;
  name: string;
  iconHtml?: string;
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
  @property({attribute: 'task-workspace-id', type: Number}) taskWorkspaceId = 0;
  @property({attribute: 'task-workspace-title'}) taskWorkspaceTitle = '';
  @property({attribute: 'active-workspace-id', type: Number}) activeWorkspaceId = 0;
  @property({attribute: 'active-workspace-title'}) activeWorkspaceTitle = '';
  @property({attribute: 'switch-workspace-uri'}) switchWorkspaceUri = '';
  @property({attribute: 'default-upload-folder'}) defaultUploadFolder = '';
  @property({attribute: 'file-browser-uri'}) fileBrowserUri = '';
  @property({attribute: 'file-info-uri'}) fileInfoUri = '';

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

  @property({
    attribute: 'initial-changes',
    converter: {
      fromAttribute(value: string | null): TrackedChange[] {
        if (!value) return [];
        try {
          return JSON.parse(value) as TrackedChange[];
        } catch {
          return [];
        }
      },
    },
  })
  initialChanges: TrackedChange[] = [];

  // -- Internal state --------------------------------------------------------

  @state() private changes: TrackedChange[] = [];
  @state() private messages: ChatMessage[] = [];
  @state() private inputValue = '';
  @state() private loading = false;
  @state() private errorMessage = '';
  @state() private thinking = false;
  @state() private streamingBuffer = '';
  @state() private isStreaming = false;
  @state() private activeTools: Map<string, ToolProgress> = new Map();
  @state() private attachments: Attachment[] = [];

  @query('textarea') private inputEl!: HTMLTextAreaElement;
  // DragUploader dispatches `uploadSuccess` on its `data-dropzone-trigger`
  // element (not the wrapper, and not bubbling) — so we have to listen on
  // the trigger button itself.
  @query('.chat-upload-trigger') private uploadTriggerEl?: HTMLElement;

  private elementBrowserListener = (e: MessageEvent): void => {
    if (!MessageUtility.verifyOrigin(e.origin)) return;
    const data = e.data as {actionName?: string; fieldName?: string; value?: string; label?: string};
    if (data.actionName !== 'typo3:elementBrowser:elementAdded') return;
    if (data.fieldName !== 'hn-agent-chat') return;
    const raw = (data.value ?? '').toString();
    if (!raw) return;
    // File-mode element browser sends the sys_file UID as a plain numeric
    // string in `value`. A combined identifier ("1:/path/file.png") would
    // contain a colon — pass that through as `identifier` instead.
    const numericUid = /^\d+$/.test(raw) ? parseInt(raw, 10) : 0;
    if (numericUid > 0) {
      this.addAttachment({uid: numericUid, name: data.label || `sys_file:${numericUid}`});
    } else {
      this.addAttachment({identifier: raw, name: data.label || raw});
    }
  };

  private uploadSuccessListener = (e: Event): void => {
    const detail = (e as CustomEvent).detail as unknown as [unknown, {upload?: Array<{uid: number; name: string; id: string; icon?: string}>}];
    const payload = Array.isArray(detail) ? detail[1] : undefined;
    const file = payload?.upload?.[0];
    if (!file) return;
    this.addAttachment({uid: file.uid, identifier: file.id, name: file.name, iconHtml: file.icon});
  };

  // DragUploader sends this postMessage in IRRE mode for BOTH the normal
  // upload path and the "use existing" override path — the only reliable
  // success signal for existing-file selections, since no uploadSuccess
  // DOM event fires there.
  private irreInsertListener = (e: MessageEvent): void => {
    if (!MessageUtility.verifyOrigin(e.origin)) return;
    const data = e.data as {actionName?: string; objectGroup?: string; table?: string; uid?: number};
    if (data.actionName !== 'typo3:foreignRelation:insert') return;
    if (data.objectGroup !== 'hn-agent-chat') return;
    if (data.table !== 'sys_file' || !data.uid) return;
    const uid = data.uid;
    this.addAttachment({uid, name: `sys_file:${uid}`});
    void this.fetchFileInfo(uid);
  };

  private async fetchFileInfo(uid: number): Promise<void> {
    if (!this.fileInfoUri) return;
    try {
      const url = new URL(this.fileInfoUri, window.location.origin);
      url.searchParams.set('uid', String(uid));
      const response = await fetch(url.toString(), {headers: {'Accept': 'application/json'}});
      if (!response.ok) return;
      const info = await response.json() as {uid: number; identifier?: string; name?: string; iconHtml?: string};
      if (!info.uid) return;
      this.addAttachment({
        uid: info.uid,
        identifier: info.identifier,
        name: info.name ?? `sys_file:${info.uid}`,
        iconHtml: info.iconHtml,
      });
    } catch {
      // swallow — stub chip remains visible
    }
  }

  // -- Lifecycle -------------------------------------------------------------

  override connectedCallback(): void {
    super.connectedCallback();
    window.addEventListener('message', this.irreInsertListener);
  }

  override firstUpdated(): void {
    this.messages = this.mergeToolResults(this.initialMessages);
    this.changes = [...this.initialChanges];

    this.scrollToBottom();

    // DragUploader auto-inits via MutationObserver on `.t3js-drag-uploader`.
    this.uploadTriggerEl?.addEventListener('uploadSuccess', this.uploadSuccessListener);

    if ((this.autoStart === '1' || this.autoStart === 'true') && this.streamUri && !this.isWorkspaceMismatch()) {
      this.doAutoStart();
    }
  }

  override disconnectedCallback(): void {
    super.disconnectedCallback();
    this.uploadTriggerEl?.removeEventListener('uploadSuccess', this.uploadSuccessListener);
    window.removeEventListener('message', this.elementBrowserListener);
    window.removeEventListener('message', this.irreInsertListener);
  }

  private isWorkspaceMismatch(): boolean {
    if (!this.taskWorkspaceId) return false;
    return this.taskWorkspaceId !== this.activeWorkspaceId;
  }

  override updated(): void {
    this.scrollToBottom();
  }

  // -- Render ----------------------------------------------------------------

  override render() {
    const mismatch = this.isWorkspaceMismatch();
    const inputDisabled = this.loading || mismatch;
    const canSubmit = !inputDisabled && (this.inputValue.trim() !== '' || this.attachments.length > 0);
    const uploadEnabled = !!this.defaultUploadFolder;
    const pickEnabled = !!this.fileBrowserUri;
    return html`
      <div class="chat-container message-fade">
        <div class="chat-messages d-flex flex-column gap-3 overflow-auto mx-3 pb-3">
          ${this.messages.map(msg => this.renderMessage(msg))}
          ${this.renderActiveTools()}
          ${this.isStreaming ? this.renderStreamingBubble() : nothing}
          ${this.thinking && !this.isStreaming ? this.renderThinkingIndicator() : nothing}
        </div>

        <div
            class="t3js-drag-uploader chat-upload-zone"
            data-target-folder=${this.defaultUploadFolder}
            data-max-file-size="0"
            data-dropzone-target=".chat-upload-anchor"
            data-dropzone-trigger=".chat-upload-trigger"
            data-default-action="rename"
            data-file-irre-object="hn-agent-chat">

          

          ${mismatch ? this.renderWorkspaceMismatch() : nothing}


          <form class="position-relative rounded-4 border bg-white overflow-hidden d-flex flex-column gap-3 p-3 " @submit=${this.onSubmit}>
            <textarea
                name="message"
                class="chat-input border-0 d-block w-100 bg-white"
                rows="2"
                placeholder="Type a follow-up message\u2026"
                .value=${this.inputValue}
                ?disabled=${inputDisabled}
                @input=${this.onInput}
                @keydown=${this.onKeydown}
            ></textarea>
            ${this.attachments.length > 0
              ? html`<div class="chat-attachments d-flex flex-wrap gap-2">
                  ${this.attachments.map((a, i) => this.renderAttachmentChip(a, i))}
                </div>`
              : nothing}
            <div class="chat-upload-anchor" style="display:none"></div>
            <div class="w-100 d-flex flex-row">
              ${this.renderAttachmentsBar(uploadEnabled, pickEnabled, inputDisabled)}
              <div class="ms-auto">
                <button type="submit" class="btn btn-sm" ?disabled=${!canSubmit}>
                  <typo3-backend-icon
                      identifier="actions-arrow-down-start-alt"
                      size="small"/>
                </button>
              </div>
            </div>
          </form>

          ${this.errorMessage
              ? html`
                <div class="alert alert-danger">${this.errorMessage}</div>`
              : nothing}
        </div>

      </div>
    `;
  }

  private renderAttachmentsBar(uploadEnabled: boolean, pickEnabled: boolean, inputDisabled: boolean): TemplateResult {
    return html`
      <div>
        <button type="button"
                class="chat-upload-trigger btn btn-sm btn-default"
                ?disabled=${inputDisabled || !uploadEnabled}
                title=${uploadEnabled ? 'Datei hochladen' : 'Kein Upload-Ordner verf\u00fcgbar'}>
          <typo3-backend-icon identifier="actions-upload" size="small"/>
          Hochladen
        </button>
        <button type="button"
                class="btn btn-sm btn-default"
                ?disabled=${inputDisabled || !pickEnabled}
                @click=${this.onPickClick}>
          <typo3-backend-icon identifier="actions-folder" size="small"/>
          Ausw\u00e4hlen
        </button>
        
      </div>
    `;
  }

  private renderAttachmentChip(att: Attachment, index: number): TemplateResult {
    const thumbUrl = this.buildThumbnailUrl(att);
    const onThumbError = (e: Event): void => {
      const img = e.target as HTMLImageElement;
      img.style.display = 'none';
      const fallback = img.nextElementSibling as HTMLElement | null;
      if (fallback) fallback.style.display = '';
    };
    return html`
      <span class="chat-attachment-chip d-inline-flex align-items-center gap-2 border rounded bg-body p-1 pe-2">
        ${thumbUrl
          ? html`
              <img src=${thumbUrl} alt="" class="chat-attachment-thumb rounded" @error=${onThumbError}/>
              <span class="chat-attachment-icon rounded" style="display:none">${this.renderFallbackIcon(att)}</span>`
          : html`<span class="chat-attachment-icon rounded">${this.renderFallbackIcon(att)}</span>`}
        <span class="chat-attachment-name">${att.name}</span>
        <button type="button"
                class="btn btn-sm p-0 border-0 text-muted"
                title="Entfernen"
                @click=${() => this.removeAttachment(index)}>\u00d7</button>
      </span>
    `;
  }

  private renderFallbackIcon(att: Attachment): TemplateResult {
    if (att.iconHtml) return html`${unsafeHTML(att.iconHtml)}`;
    return html`<typo3-backend-icon identifier="mimetypes-other-other" size="medium"></typo3-backend-icon>`;
  }

  private buildThumbnailUrl(att: Attachment): string {
    const base = (window.top as unknown as {TYPO3?: {settings?: {Resource?: {thumbnailUrl?: string}}}})
      ?.TYPO3?.settings?.Resource?.thumbnailUrl;
    if (!base) return '';
    const ref = att.uid ?? att.identifier;
    if (ref === undefined || ref === null || ref === '') return '';
    const url = new URL(base, window.location.origin);
    url.searchParams.set('identifier', String(ref));
    url.searchParams.set('size', 'large');
    url.searchParams.set('keepAspectRatio', 'false');
    return url.toString();
  }

  private renderWorkspaceMismatch(): TemplateResult {
    const mismatchTemplate = TYPO3?.lang?.['workspace.chat.mismatch']
      ?? 'This task belongs to workspace "%s", but you are currently in "%s". Switch to "%s" to continue the conversation.';
    const buttonTemplate = TYPO3?.lang?.['workspace.chat.switchButton'] ?? 'Switch to workspace "%s"';

    const taskTitle = this.taskWorkspaceTitle || `#${this.taskWorkspaceId}`;
    const activeTitle = this.activeWorkspaceTitle
      || (this.activeWorkspaceId > 0 ? `#${this.activeWorkspaceId}` : 'Live');

    const message = mismatchTemplate
      .replace('%s', taskTitle)
      .replace('%s', activeTitle)
      .replace('%s', taskTitle);
    const buttonLabel = buttonTemplate.replace('%s', taskTitle);

    // Navigate the top-level backend window so the workspace selector in the
    // toolbar reloads. target="_top" is set on the anchor for ctrl-click /
    // accessibility, but the explicit click handler is what actually fires —
    // the BE module iframe otherwise eats the navigation.
    return html`
      <div class="alert alert-warning d-flex align-items-center justify-content-between mx-3 mb-2 gap-3">
        <div>${message}</div>
        <a href=${this.switchWorkspaceUri}
           target="_top"
           class="btn btn-warning ${this.switchWorkspaceUri ? '' : 'disabled'}"
           @click=${this.onSwitchClick}>
          ${buttonLabel}
        </a>
      </div>
    `;
  }

  private onSwitchClick(e: MouseEvent): void {
    if (!this.switchWorkspaceUri) return;
    // Allow native handling for modifier clicks (open in new tab etc.).
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
    e.preventDefault();
    const topWindow = window.top ?? window;
    topWindow.location.href = this.switchWorkspaceUri;
  }

  private renderMessage(msg: ChatMessage): TemplateResult | typeof nothing {
    const role = msg.role || 'unknown';
    if (role === 'system') return nothing;
    const roleLabel = role === 'user' ? 'you' : role;

    if (role === 'assistant') {
      return html`
        <div class="rounded-4 bg-white border p-3 me-3">
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
      <div class="rounded-4 bg-success-subtle border p-3 ms-3 align-self-end">
        <div class="chat-msg-role fw-bold small opacity-75 mb-1 text-uppercase">${roleLabel}</div>
        <pre class="chat-msg-prewrap m-0">${msg.content ?? ''}</pre>
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
    if (this.isWorkspaceMismatch()) return;
    const message = this.inputValue.trim();
    const attachments = this.attachments;
    if (!message && attachments.length === 0) return;

    this.errorMessage = '';
    const optimisticContent = this.composeOptimisticUserMessage(message, attachments);
    this.messages = [...this.messages, {role: 'user', content: optimisticContent}];
    this.inputValue = '';
    this.attachments = [];
    this.loading = true;

    if (this.streamUri) {
      this.sendStreaming(message, attachments).then(() => this.finishSend());
    } else {
      this.sendBlocking(message, attachments).then(() => this.finishSend());
    }
  }

  private composeOptimisticUserMessage(message: string, attachments: Attachment[]): string {
    if (attachments.length === 0) return message;
    const lines = attachments.map(a => {
      const ref = a.uid ? `sys_file:${a.uid}` : (a.identifier || a.name);
      return `- ${ref} — ${a.name}`;
    });
    const prefix = message ? message.replace(/\s+$/, '') + '\n\n' : '';
    return prefix + '---\nAngehängte Dateien:\n' + lines.join('\n');
  }

  private addAttachment(att: Attachment): void {
    const existingIndex = this.attachments.findIndex(existing =>
      (att.uid !== undefined && existing.uid === att.uid) ||
      (att.identifier !== undefined && existing.identifier === att.identifier),
    );
    if (existingIndex >= 0) {
      const existing = this.attachments[existingIndex];
      const merged: Attachment = {
        uid: existing.uid ?? att.uid,
        identifier: existing.identifier ?? att.identifier,
        name: this.isStubName(existing.name) && !this.isStubName(att.name) ? att.name : existing.name,
        iconHtml: existing.iconHtml ?? att.iconHtml,
      };
      this.attachments = this.attachments.map((a, i) => i === existingIndex ? merged : a);
      return;
    }
    this.attachments = [...this.attachments, att];
  }

  private isStubName(name: string): boolean {
    return /^sys_file:\d+$/.test(name);
  }

  private removeAttachment(index: number): void {
    this.attachments = this.attachments.filter((_, i) => i !== index);
  }

  private onPickClick(): void {
    if (!this.fileBrowserUri) return;
    // Listener is attached fresh each open and removed on modal close to avoid
    // duplicate handling between sessions.
    window.addEventListener('message', this.elementBrowserListener);
    const modal = Modal.advanced({
      type: Modal.types.iframe,
      content: this.fileBrowserUri,
      size: Modal.sizes.large,
    });
    modal.addEventListener('typo3-modal-hide', () => {
      window.removeEventListener('message', this.elementBrowserListener);
    });
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

  private appendAttachments(formData: FormData, attachments: Attachment[]): void {
    if (attachments.length === 0) return;
    const payload = attachments.map(a => ({
      uid: a.uid,
      identifier: a.identifier,
      name: a.name,
    }));
    formData.append('attachments', JSON.stringify(payload));
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

  private async sendBlocking(message: string, attachments: Attachment[] = []): Promise<void> {
    try {
      const formData = new FormData();
      formData.append('message', message);
      this.appendAttachments(formData, attachments);

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

  private async sendStreaming(message: string, attachments: Attachment[] = []): Promise<void> {
    try {
      this.thinking = true;
      const formData = new FormData();
      formData.append('message', message);
      this.appendAttachments(formData, attachments);

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

      case 'change_tracked': {
        const change = data as unknown as TrackedChange;
        this.changes = [...this.changes, change];
        document.dispatchEvent(new CustomEvent('agent:record-changed'));
        break;
      }

      case 'done':
        this.thinking = false;
        this.isStreaming = false;
        // Move completed tools out of activeTools — they are already rendered inline
        this.activeTools = new Map();
        // Replace optimistic messages with the persisted server state so the
        // user sees the canonical form (e.g. resolved attachment block) instead
        // of the locally-composed preview.
        if (Array.isArray((data as {messages?: unknown}).messages)) {
          this.messages = this.mergeToolResults((data as {messages: ChatMessage[]}).messages);
        }
        document.dispatchEvent(new CustomEvent('agent:record-changed'));
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
