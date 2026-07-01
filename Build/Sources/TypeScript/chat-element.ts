import {html, LitElement, nothing, type TemplateResult} from 'lit';
import {customElement, property, state} from 'lit/decorators.js';
import {unsafeHTML} from 'lit/directives/unsafe-html.js';
import {createRef, ref, type Ref} from 'lit/directives/ref.js';
import {marked} from 'marked';
import DOMPurify from 'dompurify';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import type {Attachment} from '@hn/agent/attachment.js';
import './thinking-indicator.js';
import './chat-bubble.js';
import '@hn/agent/attachment-chip-elements.js';
import '@hn/agent/message-composer.js';
import type {MessageComposerElement} from '@hn/agent/message-composer.js';

marked.setOptions({breaks: true, gfm: true});

// ---- Types ----------------------------------------------------------------

interface ChatMessage {
    role: 'user' | 'assistant' | 'system' | 'tool';
    content?: string | ContentBlock[];
    attachments?: Attachment[];
    tool_calls?: ToolCall[];
    tool_call_id?: string;
    reasoning?: string;
    reasoning_details?: unknown[];
}

type ContentBlock =
    | { type: 'text'; text: string }
    | { type: 'image_url'; image_url: { url: string } }
    | { type: 'file'; file: { filename: string; file_data: string } };

interface ToolCall {
    id?: string;
    function: { name: string; arguments: string };
    result?: string | ContentBlock[];
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

// ---- Component -------------------------------------------------------------

@customElement('hn-agent-chat')
export class ChatElement extends LitElement {

    // No Shadow DOM — use TYPO3 backend Bootstrap CSS
    override createRenderRoot() {
        return this;
    }

    // -- Properties (HTML attributes, set by Fluid template) -------------------

    @property({attribute: 'stream-uri'}) streamUri = '';
    @property({attribute: 'cancel-uri'}) cancelUri = '';
    @property({attribute: 'auto-start'}) autoStart = '';
    @property({attribute: 'initial-prompt'}) initialPrompt = '';
    @property({attribute: 'task-workspace-id', type: Number}) taskWorkspaceId = 0;
    @property({attribute: 'task-workspace-title'}) taskWorkspaceTitle = '';
    @property({attribute: 'active-workspace-id', type: Number}) activeWorkspaceId = 0;
    @property({attribute: 'active-workspace-title'}) activeWorkspaceTitle = '';
    @property({attribute: 'default-upload-folder'}) defaultUploadFolder = '';
    @property({attribute: 'file-browser-uri'}) fileBrowserUri = '';

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
    @state() private loading = false;
    @state() private errorMessage = '';
    @state() private thinking = false;
    @state() private streamingBuffer = '';
    @state() private reasoningBuffer = '';
    @state() private isStreaming = false;
    @state() private lastSubmission: { message: string; attachments: Attachment[] } | null = null;

    // Active SSE fetch's AbortController while a stream is in flight, null otherwise.
    // The Stop button calls .abort() on it; the reader loop in sendStreaming sees the
    // AbortError and exits cleanly. PHP-side, the disconnect is picked up by
    // SseStream's connection_aborted() check on the next flush.
    private abortController: AbortController | null = null;

    private composerRef: Ref<MessageComposerElement> = createRef();
    private messagesContainerRef: Ref<HTMLElement> = createRef();
    private latestUserBubbleRef: Ref<HTMLElement> = createRef();

    private onKeydownGlobal = (e: KeyboardEvent): void => {
        if (e.key !== 'Escape') return;
        // Already handled by another consumer (e.g. an open TYPO3 modal) — don't
        // also tear down the stream in that case.
        if (e.defaultPrevented) return;
        if (!this.loading || this.abortController === null) return;
        e.preventDefault();
        this.onStop();
    };

    // -- Lifecycle -------------------------------------------------------------

    override firstUpdated(): void {
        this.messages = this.mergeToolResults(this.initialMessages);
        this.changes = [...this.initialChanges];

        document.addEventListener('keydown', this.onKeydownGlobal);

        if ((this.autoStart === '1' || this.autoStart === 'true') && this.streamUri && !this.isWorkspaceMismatch()) {
            this.doAutoStart();
        }

        this.composerRef.value?.focus();
    }

    override disconnectedCallback(): void {
        super.disconnectedCallback();
        document.removeEventListener('keydown', this.onKeydownGlobal);
    }

    private isWorkspaceMismatch(): boolean {
        if (!this.taskWorkspaceId) return false;
        return this.taskWorkspaceId !== this.activeWorkspaceId;
    }

    // -- Render ----------------------------------------------------------------

    override render() {
        const mismatch = this.isWorkspaceMismatch();
        const inputDisabled = this.loading || mismatch;
        const showHeader = mismatch || !!this.errorMessage
        return html`
            ${showHeader ? html`
                <div class="chat-header">
                    ${mismatch ? this.renderWorkspaceMismatch() : nothing}
                    ${this.errorMessage ? this.renderErrorMessage() : nothing}
                </div>` : nothing}
            <div class="chat-body">

                <div class="chat-messages px-2 py-4" ${ref(this.messagesContainerRef)}>
                    ${this.computeTurns().map((turn, i, all) => {
                        const isLast = i === all.length - 1;
                        return html`
                            <div class="chat-turn d-flex flex-column gap-3 ${isLast ? 'chat-turn-latest' : 'pb-3'}">
                                ${turn.map((msg, j) => this.renderMessage(msg, isLast && j === 0 && msg.role === 'user'))}
                                ${isLast && this.isStreaming ? this.renderStreamingBubble() : nothing}
                                ${isLast && this.thinking && !this.isStreaming ? this.renderThinkingIndicator() : nothing}
                            </div>
                        `;
                    })}
                </div>
            </div>
            <div class="chat-footer">
                <hn-agent-message-composer
                        ${ref(this.composerRef)}
                        ?disabled=${inputDisabled}
                        placeholder="Type a follow-up message\u2026"
                        default-upload-folder=${this.defaultUploadFolder}
                        file-browser-uri=${this.fileBrowserUri}
                        field-name="hn-agent-chat"
                        @submit=${this.onComposerSubmit}>
                    ${this.loading
                        ? html`
                            <button slot="action" type="button"
                                    class="btn btn-sm"
                                    title="Antwort abbrechen"
                                    ?disabled=${this.abortController === null}
                                    @click=${this.onStop}>
                                <typo3-backend-icon identifier="actions-close" size="small"/>
                            </button>`
                        : nothing}
                </hn-agent-message-composer>
            </div>
        `;
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

        // Switch the workspace via the core AJAX endpoint, then full reload so
        // the topbar indicator and pagetree pick up the new workspace context.
        return html`
            <div class="callout callout-warning">
                <div class="callout-icon">
                        <span class="icon-emphasized">
                          <typo3-backend-icon identifier="actions-exclamation" size="small"></typo3-backend-icon>
                        </span>
                </div>
                <div class="callout-content">
                    <div class="callout-title">Warning</div>
                    <div class="callout-body">
                        <div class="mb-2">
                            ${message}
                        </div>
                        <button type="button"
                                class="btn btn-warning"
                                ?disabled=${!this.taskWorkspaceId}
                                @click=${() => this.handleSwitchWorkspace()}>
                            <typo3-backend-icon identifier="apps-toolbar-menu-workspace" size="small"></typo3-backend-icon>
                            ${buttonLabel}
                        </button>
                    </div>
                </div>
            </div>
        `;
    }

    private async handleSwitchWorkspace(): Promise<void> {
        if (!this.taskWorkspaceId) {
            return;
        }
        try {
            await new AjaxRequest(TYPO3.settings.ajaxUrls.workspace_switch).post({
                workspaceId: this.taskWorkspaceId,
                pageId: 0,
            });
            // Reload the TOP frame, not the iframe — otherwise the workspace
            // toolbar indicator (which lives in the backend chrome) keeps
            // showing the old workspace.
            (window.top ?? window).location.reload();
        } catch (e) {
            console.error('Workspace switch failed', e);
        }
    }

    private renderErrorMessage(): TemplateResult {
        return html`
            <div class="alert alert-danger alert-dismissible">
                <button type="button" class="close">
          <span aria-hidden="true"><typo3-backend-icon identifier="actions-close"
                                                       size="small"></typo3-backend-icon></span>
                    <span class="visually-hidden">Close</span></button>
                <button type="button"
                        class="close"
                        aria-label="Schließen"
                        @click=${this.onDismissError}>
                    <button type="button" class="close">
            <span aria-hidden="true"><typo3-backend-icon identifier="actions-close"
                                                         size="small"></typo3-backend-icon></span>
                        <span class="visually-hidden">Close</span>
                    </button>
                    <div class="alert-inner">
                        <div class="alert-icon">
              <span class="icon-emphasized">
                <typo3-backend-icon size="small" identifier="actions-exclamation"></typo3-backend-icon>
              </span>
                        </div>
                        <div class="alert-content">
                            <div class="alert-title" id="alert-title-stp4daa23v">Warning</div>
                            <p class="alert-message mb-2" id="alert-message-stp4daa23v">${this.errorMessage}</p>
                            ${this.lastSubmission !== null
                                    ? html`
                                        <button type="button"
                                                class="btn btn-sm btn-danger"
                                                ?disabled=${this.loading}
                                                @click=${this.onRetry}>
                                            <typo3-backend-icon identifier="actions-refresh"
                                                                size="small"></typo3-backend-icon>
                                            Erneut versuchen
                                        </button>`
                                    : nothing}
                        </div>
                    </div>
            </div>
        `;
    }

    private renderMessage(msg: ChatMessage, isLatestUser = false): TemplateResult | typeof nothing {
        const role = msg.role || 'unknown';
        if (role === 'system') return nothing;

        if (role === 'assistant') {
            const assistantText = msg.content != null ? this.contentText(msg.content) : '';
            const hasFooter = !!msg.reasoning || (!!msg.tool_calls && msg.tool_calls.length > 0);
            return html`
                <hn-agent-chat-bubble
                        author="assistant"
                        .body=${assistantText
                                ? html`${unsafeHTML(this.renderMarkdown(assistantText))}`
                                : nothing}
                        .footer=${hasFooter
                                ? html`
                                    ${msg.reasoning ? this.renderReasoningBlock(msg.reasoning, msg) : nothing}
                                    ${msg.tool_calls && msg.tool_calls.length > 0 ? this.renderToolCallsGroup(msg.tool_calls) : nothing}`
                                : nothing}>
                </hn-agent-chat-bubble>
            `;
        }


        // user, system, unknown
        const attachments = msg.attachments ?? [];
        const userText = msg.content != null ? this.contentText(msg.content) : '';
        return html`
            <hn-agent-chat-bubble
                    author=${role}
                    .body=${userText ? html`<p class="card-text">${userText}</p>` : nothing}
                    .footer=${attachments.length > 0
                            ? html`
                                <hn-agent-attachment-chips
                                        readonly
                                        .attachments=${attachments}>
                                </hn-agent-attachment-chips>`
                            : nothing}
                    ${isLatestUser ? ref(this.latestUserBubbleRef) : nothing}>
            </hn-agent-chat-bubble>
        `;
    }

    private toolCallsGroupIds = new WeakMap<ToolCall[], string>();

    private getToolCallsGroupId(tcs: ToolCall[]): string {
        let id = this.toolCallsGroupIds.get(tcs);
        if (!id) {
            id = `tcg-${crypto.randomUUID()}`;
            this.toolCallsGroupIds.set(tcs, id);
        }
        return id;
    }

    private reasoningGroupIds = new WeakMap<object, string>();

    private getReasoningGroupId(key: object): string {
        let id = this.reasoningGroupIds.get(key);
        if (!id) {
            id = `rg-${crypto.randomUUID()}`;
            this.reasoningGroupIds.set(key, id);
        }
        return id;
    }

    private renderToolCallsGroup(tcs: ToolCall[]): TemplateResult {
        const count = tcs.length;
        const noun = count === 1 ? 'Tool Call' : 'Tool Calls';
        const collapseId = this.getToolCallsGroupId(tcs);
        return html`
            <div class="panel panel-default panel-condensed">
                <div class="panel-heading">
                    <div class="panel-heading-row">
                        <button class="panel-button collapsed" type="button"
                                data-bs-toggle="collapse" data-bs-target="#${collapseId}"
                                aria-expanded="false" aria-controls="${collapseId}">
                            <span class="panel-icon">
                                <typo3-backend-icon identifier="actions-cog" size="small"></typo3-backend-icon>
                            </span>
                            <div class="panel-title"><span><strong>${count}</strong> ${noun}</span></div>
                            <span class="caret"></span>
                        </button>

                    </div>

                </div>
                <div id="${collapseId}" class="panel-collapse collapse">
                    <div class="panel-body">
                        <div class="panel-group">
                            ${tcs.map(tc => this.renderToolCall(tc))}
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    private renderToolCall(tc: ToolCall): TemplateResult {
        const hasResult = tc.result !== undefined;
        const resultText = hasResult ? this.contentText(tc.result!) : '';
        const resultMedia = hasResult ? this.contentMedia(tc.result!) : [];
        return html`
            <div class="panel panel-default panel-condensed">
                <div class="panel-heading">

                    <div class="panel-heading-row">
                        <button class="panel-button collapsed" type="button" data-bs-toggle="collapse"
                                data-bs-target="#panel-record-collapsed-auto6a3bd0d254d40" aria-expanded="false">
                            <div class="panel-title">${tc.function?.name ?? 'unknown'}</div>
                            <span class="caret"></span>
                        </button>

                    </div>

                </div>
                <div class="panel-collapse collapse" id="panel-record-collapsed-auto6a3bd0d254d40" style="">
                    <div class="panel-body">
                        <strong>Args</strong><br/>
                        <code>${tc.function?.arguments ?? ''}</code>
                        ${hasResult
                                ? html`
                                    <div>
                                        <strong>Result</strong><br/>
                                        <pre class="m-0">${resultText}</pre>
                                        ${resultMedia.map(b => this.renderResultMedia(b))}
                                    </div>`
                                : html`
                                    <div class="chat-toolcall-running text-muted">
                                        <thinking-indicator label="Executing"></thinking-indicator>
                                    </div>`}
                    </div>
                </div>
            </div>
        `;
    }

    private renderResultMedia(block: ContentBlock): TemplateResult | typeof nothing {
        if (block.type === 'image_url') {
            return html`<img src=${block.image_url.url} alt="" class="mt-2 d-block"
                             style="max-width:100%; max-height:240px;"/>`;
        }
        if (block.type === 'file') {
            return html`
                <div class="mt-2">
                    <typo3-backend-icon identifier="mimetypes-other-other" size="small"/>
                    ${block.file.filename}
                </div>`;
        }
        return nothing;
    }

    private contentText(content: string | ContentBlock[] | null | undefined): string {
        if (content == null) return '';
        if (typeof content === 'string') return content;
        return content
            .filter((b): b is Extract<ContentBlock, { type: 'text' }> => b.type === 'text')
            .map(b => b.text)
            .join('\n');
    }

    private contentMedia(content: string | ContentBlock[] | null | undefined): ContentBlock[] {
        if (content == null || typeof content === 'string') return [];
        return content.filter(b => b.type !== 'text');
    }

    private renderStreamingBubble(): TemplateResult {
        return html`
            <hn-agent-chat-bubble
                    author="assistant"
                    .body=${html`
                        ${unsafeHTML(this.renderMarkdown(this.streamingBuffer))}
                        <thinking-indicator></thinking-indicator>`}
                    .footer=${this.reasoningBuffer
                            ? this.renderReasoningBlock(this.reasoningBuffer, this)
                            : nothing}>
            </hn-agent-chat-bubble>
        `;
    }

    private renderReasoningBlock(reasoning: string, key: object): TemplateResult {
        const collapseId = this.getReasoningGroupId(key);
        return html`
            <div class="panel panel-default panel-condensed">
                <div class="panel-heading">
                    <div class="panel-heading-row">
                        <button class="panel-button collapsed" type="button"
                                data-bs-toggle="collapse" data-bs-target="#${collapseId}"
                                aria-expanded="false" aria-controls="${collapseId}">
                            <span class="panel-icon">
                                <typo3-backend-icon identifier="actions-lightbulb-on" size="small"></typo3-backend-icon>
                            </span>
                            <div class="panel-title"><span>Reasoning</span></div>
                            <span class="caret"></span>
                        </button>
                    </div>
                </div>
                <div id="${collapseId}" class="panel-collapse collapse">
                    <div class="panel-body">
                        ${unsafeHTML(this.renderMarkdown(reasoning))}
                    </div>
                </div>
            </div>
        `;
    }

    private renderThinkingIndicator(): TemplateResult {
        return html`
            <div class="p-3 align-self-start">
                <thinking-indicator label="Thinking"></thinking-indicator>
            </div>
        `;
    }

    // -- Markdown --------------------------------------------------------------

    private renderMarkdown(text: string): string {
        return DOMPurify.sanitize(marked.parse(text ?? '') as string);
    }

    // -- Event handlers --------------------------------------------------------

    private onComposerSubmit(e: CustomEvent<{message: string; attachments: Attachment[]}>): void {
        if (this.isWorkspaceMismatch()) return;
        const {message, attachments} = e.detail;
        if (!message && attachments.length === 0) return;

        this.errorMessage = '';
        this.lastSubmission = {message, attachments};
        const optimistic: ChatMessage = {role: 'user', content: message};
        if (attachments.length > 0) optimistic.attachments = attachments;
        this.messages = [...this.messages, optimistic];
        this.loading = true;
        this.scrollLatestUserMessageToTop();
        this.composerRef.value?.focus();

        this.sendStreaming(message, attachments).then(() => this.finishSend());
    }

    private onDismissError(): void {
        this.errorMessage = '';
        this.lastSubmission = null;
    }

    private onStop(): void {
        // Promote any in-flight streaming bubble into a real assistant message
        // BEFORE aborting. Otherwise the partial content vanishes the moment
        // isStreaming flips to false — the user would see nothing until reload.
        if (this.isStreaming && (this.streamingBuffer !== '' || this.reasoningBuffer !== '')) {
            this.messages = [...this.messages, {
                role: 'assistant',
                content: this.streamingBuffer,
                ...(this.reasoningBuffer ? {reasoning: this.reasoningBuffer} : {}),
            }];
            this.streamingBuffer = '';
            this.reasoningBuffer = '';
            this.isStreaming = false;
        }
        // Tell the server this is an EXPLICIT cancel (not just a navigation). The
        // server-side flow no longer treats a bare connection close as a cancel —
        // it would otherwise let the task run to completion. keepalive ensures the
        // request still goes through if the user simultaneously closes the tab.
        // The task UID is already on the cancelUri as a query parameter.
        if (this.cancelUri) {
            void fetch(this.cancelUri, {method: 'POST', keepalive: true}).catch(() => {
            });
        }
        this.abortController?.abort();
        this.requestUpdate();
    }

    private onRetry(): void {
        if (!this.lastSubmission || this.loading) return;
        const {message, attachments} = this.lastSubmission;
        this.errorMessage = '';
        this.loading = true;
        this.sendStreaming(message, attachments).then(() => this.finishSend());
    }

    private finishSend(): void {
        this.loading = false;
        if (this.errorMessage === '') {
            this.lastSubmission = null;
        }
        this.composerRef.value?.focus();
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
        // Note: the user-bubble is intentionally NOT pushed optimistically here.
        // The backend streams a `user_message` event after any synthetic context
        // turns so live and reload renderings stay identical (the persisted order
        // is [system, assistant_context?, tool*, user, ...]).
        this.loading = true;
        this.lastSubmission = {message: '', attachments: []};
        await this.sendStreaming('');
        this.finishSend();
    }

    // -- Network: streaming (SSE) ----------------------------------------------

    private async sendStreaming(message: string, attachments: Attachment[] = []): Promise<void> {
        this.abortController = new AbortController();
        try {
            this.thinking = true;
            const formData = new FormData();
            formData.append('message', message);
            this.appendAttachments(formData, attachments);

            const response = await fetch(this.streamUri, {
                method: 'POST',
                body: formData,
                signal: this.abortController.signal,
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
            // AbortError is the user clicking Stop — not a failure. The streaming
            // bubble (if any) stays visible; finishSend() will flip loading off and
            // a reload would reveal the task as Cancelled with partial messages.
            if ((err as Error).name !== 'AbortError') {
                this.errorMessage = (err as Error).message || String(err);
            }
        } finally {
            this.abortController = null;
        }
    }

    // -- SSE parsing -----------------------------------------------------------

    private parseSseBuffer(buffer: string): { parsed: SseParsed[]; remainder: string } {
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

            case 'reasoning_delta':
                this.thinking = false;
                this.isStreaming = true;
                this.reasoningBuffer += (data.text as string) || '';
                break;

            case 'tool_call_delta':
                this.thinking = false;
                break;

            case 'user_message': {
                // Emitted exactly once during initial processing, after any synthetic
                // context turns. Renders the user prompt in the same slot it occupies
                // in the persisted message array.
                const msg = data.message as ChatMessage | undefined;
                if (msg) {
                    this.messages = [...this.messages, msg];
                    this.scrollLatestUserMessageToTop();
                }
                break;
            }

            case 'assistant_message': {
                this.thinking = false;
                const msg = data.message as ChatMessage | undefined;

                if (this.isStreaming) {
                    if (Array.isArray(msg?.tool_calls) && msg!.tool_calls.length > 0) {
                        // Replace streaming bubble with finalized message including tool_calls
                        this.messages = [...this.messages, msg!];
                    } else {
                        // Finalize with server content (trusted) or streamed buffer; preserve
                        // reasoning from the server message, falling back to the streamed
                        // reasoning buffer if the server didn't ship a `reasoning` field.
                        this.messages = [...this.messages, {
                            role: 'assistant',
                            content: msg?.content || this.streamingBuffer,
                            ...(msg?.reasoning || this.reasoningBuffer
                                ? {reasoning: msg?.reasoning || this.reasoningBuffer}
                                : {}),
                            ...(msg?.reasoning_details ? {reasoning_details: msg.reasoning_details} : {}),
                        }];
                    }
                    this.isStreaming = false;
                    this.streamingBuffer = '';
                    this.reasoningBuffer = '';
                } else if (msg) {
                    this.messages = [...this.messages, msg];
                }
                break;
            }

            case 'tool_start':
                // No-op: the assistant_message bubble already carries the tool_call.
                // Its "running" state is rendered inline (result === undefined).
                break;

            case 'tool_result': {
                const toolCallId = data.tool_call_id as string;
                const content = data.content as string;

                this.messages = this.messages.map(msg => {
                    if (msg.role !== 'assistant' || !msg.tool_calls) return msg;
                    if (!msg.tool_calls.some(tc => tc.id === toolCallId)) return msg;
                    return {
                        ...msg,
                        tool_calls: msg.tool_calls.map(tc =>
                            tc.id === toolCallId ? {...tc, result: content} : tc
                        ),
                    };
                });
                break;
            }

            case 'change_tracked': {
                const change = data as unknown as TrackedChange;
                this.changes = [...this.changes, change];
                document.dispatchEvent(new CustomEvent('agent:record-changed', {detail: change}));
                break;
            }

            case 'done':
                this.thinking = false;
                this.isStreaming = false;
                this.streamingBuffer = '';
                this.reasoningBuffer = '';
                // Replace optimistic messages with the persisted server state so the
                // user sees the canonical form (e.g. resolved attachment block) instead
                // of the locally-composed preview.
                if (Array.isArray((data as { messages?: unknown }).messages)) {
                    this.messages = this.mergeToolResults((data as { messages: ChatMessage[] }).messages);
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
        const resultMap = new Map<string, string | ContentBlock[]>();
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

    private computeTurns(): ChatMessage[][] {
        const turns: ChatMessage[][] = [];
        for (const msg of this.messages) {
            if (msg.role === 'system') continue;
            if (msg.role === 'user' || turns.length === 0) {
                turns.push([msg]);
            } else {
                turns[turns.length - 1].push(msg);
            }
        }
        return turns;
    }

    private scrollLatestUserMessageToTop(): void {
        void this.updateComplete.then(() => {
            const container = this.messagesContainerRef.value;
            const latest = this.latestUserBubbleRef.value;
            if (!container || !latest) return;
            const containerTop = container.getBoundingClientRect().top;
            const latestTop = latest.getBoundingClientRect().top;
            container.scrollTo({top: container.scrollTop + latestTop - containerTop, behavior: 'smooth'});
        });
    }
}
