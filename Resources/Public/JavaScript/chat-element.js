var __defProp = Object.defineProperty;
var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
var __decorateClass = (decorators, target, key, kind) => {
  var result = kind > 1 ? void 0 : kind ? __getOwnPropDesc(target, key) : target;
  for (var i = decorators.length - 1, decorator; i >= 0; i--)
    if (decorator = decorators[i])
      result = (kind ? decorator(target, key, result) : decorator(result)) || result;
  if (kind && result) __defProp(target, key, result);
  return result;
};
import { html, LitElement, nothing } from "lit";
import { customElement, property, state } from "lit/decorators.js";
import { unsafeHTML } from "lit/directives/unsafe-html.js";
import { createRef, ref } from "lit/directives/ref.js";
import { marked } from "marked";
import DOMPurify from "dompurify";
import AjaxRequest from "@typo3/core/ajax/ajax-request.js";
import "./thinking-indicator.js";
import "./chat-bubble.js";
import "@hn/agent/attachment-chip-elements.js";
import "@hn/agent/message-composer.js";
marked.setOptions({ breaks: true, gfm: true });
let ChatElement = class extends LitElement {
  constructor() {
    super(...arguments);
    this.streamUri = "";
    this.cancelUri = "";
    this.autoStart = "";
    this.initialPrompt = "";
    this.taskWorkspaceId = 0;
    this.taskWorkspaceTitle = "";
    this.activeWorkspaceId = 0;
    this.activeWorkspaceTitle = "";
    this.defaultUploadFolder = "";
    this.fileBrowserUri = "";
    this.initialMessages = [];
    this.initialChanges = [];
    this.changes = [];
    this.messages = [];
    this.loading = false;
    this.errorMessage = "";
    this.thinking = false;
    this.streamingBuffer = "";
    this.reasoningBuffer = "";
    this.isStreaming = false;
    this.lastSubmission = null;
    // Active SSE fetch's AbortController while a stream is in flight, null otherwise.
    // The Stop button calls .abort() on it; the reader loop in sendStreaming sees the
    // AbortError and exits cleanly. PHP-side, the disconnect is picked up by
    // SseStream's connection_aborted() check on the next flush.
    this.abortController = null;
    this.composerRef = createRef();
    this.messagesContainerRef = createRef();
    this.latestUserBubbleRef = createRef();
    this.onKeydownGlobal = (e) => {
      if (e.key !== "Escape") return;
      if (e.defaultPrevented) return;
      if (!this.loading || this.abortController === null) return;
      e.preventDefault();
      this.onStop();
    };
    this.toolCallsGroupIds = /* @__PURE__ */ new WeakMap();
    this.reasoningGroupIds = /* @__PURE__ */ new WeakMap();
  }
  // No Shadow DOM — use TYPO3 backend Bootstrap CSS
  createRenderRoot() {
    return this;
  }
  // -- Lifecycle -------------------------------------------------------------
  firstUpdated() {
    this.messages = this.mergeToolResults(this.initialMessages);
    this.changes = [...this.initialChanges];
    document.addEventListener("keydown", this.onKeydownGlobal);
    if ((this.autoStart === "1" || this.autoStart === "true") && this.streamUri && !this.isWorkspaceMismatch()) {
      this.doAutoStart();
    }
    this.composerRef.value?.focus();
  }
  disconnectedCallback() {
    super.disconnectedCallback();
    document.removeEventListener("keydown", this.onKeydownGlobal);
  }
  isWorkspaceMismatch() {
    if (!this.taskWorkspaceId) return false;
    return this.taskWorkspaceId !== this.activeWorkspaceId;
  }
  // -- Render ----------------------------------------------------------------
  render() {
    const mismatch = this.isWorkspaceMismatch();
    const inputDisabled = this.loading || mismatch;
    const showHeader = mismatch || !!this.errorMessage;
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
                            <div class="chat-turn d-flex flex-column gap-3 ${isLast ? "chat-turn-latest" : "pb-3"}">
                                ${turn.map((msg, j) => this.renderMessage(msg, isLast && j === 0 && msg.role === "user"))}
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
                    ${this.loading ? html`
                            <button slot="action" type="button"
                                    class="btn btn-sm"
                                    title="Antwort abbrechen"
                                    ?disabled=${this.abortController === null}
                                    @click=${this.onStop}>
                                <typo3-backend-icon identifier="actions-close" size="small"/>
                            </button>` : nothing}
                </hn-agent-message-composer>
            </div>
        `;
  }
  renderWorkspaceMismatch() {
    const mismatchTemplate = TYPO3?.lang?.["workspace.chat.mismatch"] ?? 'This task belongs to workspace "%s", but you are currently in "%s". Switch to "%s" to continue the conversation.';
    const buttonTemplate = TYPO3?.lang?.["workspace.chat.switchButton"] ?? 'Switch to workspace "%s"';
    const taskTitle = this.taskWorkspaceTitle || `#${this.taskWorkspaceId}`;
    const activeTitle = this.activeWorkspaceTitle || (this.activeWorkspaceId > 0 ? `#${this.activeWorkspaceId}` : "Live");
    const message = mismatchTemplate.replace("%s", taskTitle).replace("%s", activeTitle).replace("%s", taskTitle);
    const buttonLabel = buttonTemplate.replace("%s", taskTitle);
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
  async handleSwitchWorkspace() {
    if (!this.taskWorkspaceId) {
      return;
    }
    try {
      await new AjaxRequest(TYPO3.settings.ajaxUrls.workspace_switch).post({
        workspaceId: this.taskWorkspaceId,
        pageId: 0
      });
      (window.top ?? window).location.reload();
    } catch (e) {
      console.error("Workspace switch failed", e);
    }
  }
  renderErrorMessage() {
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
                            ${this.lastSubmission !== null ? html`
                                        <button type="button"
                                                class="btn btn-sm btn-danger"
                                                ?disabled=${this.loading}
                                                @click=${this.onRetry}>
                                            <typo3-backend-icon identifier="actions-refresh"
                                                                size="small"></typo3-backend-icon>
                                            Erneut versuchen
                                        </button>` : nothing}
                        </div>
                    </div>
            </div>
        `;
  }
  renderMessage(msg, isLatestUser = false) {
    const role = msg.role || "unknown";
    if (role === "system") return nothing;
    if (role === "assistant") {
      const assistantText = msg.content != null ? this.contentText(msg.content) : "";
      const hasFooter = !!msg.reasoning || !!msg.tool_calls && msg.tool_calls.length > 0;
      return html`
                <hn-agent-chat-bubble
                        author="assistant"
                        .body=${assistantText ? html`${unsafeHTML(this.renderMarkdown(assistantText))}` : nothing}
                        .footer=${hasFooter ? html`
                                    ${msg.reasoning ? this.renderReasoningBlock(msg.reasoning, msg) : nothing}
                                    ${msg.tool_calls && msg.tool_calls.length > 0 ? this.renderToolCallsGroup(msg.tool_calls) : nothing}` : nothing}>
                </hn-agent-chat-bubble>
            `;
    }
    const attachments = msg.attachments ?? [];
    const userText = msg.content != null ? this.contentText(msg.content) : "";
    return html`
            <hn-agent-chat-bubble
                    author=${role}
                    .body=${userText ? html`<p class="card-text">${userText}</p>` : nothing}
                    .footer=${attachments.length > 0 ? html`
                                <hn-agent-attachment-chips
                                        readonly
                                        .attachments=${attachments}>
                                </hn-agent-attachment-chips>` : nothing}
                    ${isLatestUser ? ref(this.latestUserBubbleRef) : nothing}>
            </hn-agent-chat-bubble>
        `;
  }
  getToolCallsGroupId(tcs) {
    let id = this.toolCallsGroupIds.get(tcs);
    if (!id) {
      id = `tcg-${crypto.randomUUID()}`;
      this.toolCallsGroupIds.set(tcs, id);
    }
    return id;
  }
  getReasoningGroupId(key) {
    let id = this.reasoningGroupIds.get(key);
    if (!id) {
      id = `rg-${crypto.randomUUID()}`;
      this.reasoningGroupIds.set(key, id);
    }
    return id;
  }
  renderToolCallsGroup(tcs) {
    const count = tcs.length;
    const noun = count === 1 ? "Tool Call" : "Tool Calls";
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
                            ${tcs.map((tc) => this.renderToolCall(tc))}
                        </div>
                    </div>
                </div>
            </div>
        `;
  }
  renderToolCall(tc) {
    const hasResult = tc.result !== void 0;
    const resultText = hasResult ? this.contentText(tc.result) : "";
    const resultMedia = hasResult ? this.contentMedia(tc.result) : [];
    return html`
            <div class="panel panel-default panel-condensed">
                <div class="panel-heading">

                    <div class="panel-heading-row">
                        <button class="panel-button collapsed" type="button" data-bs-toggle="collapse"
                                data-bs-target="#panel-record-collapsed-auto6a3bd0d254d40" aria-expanded="false">
                            <div class="panel-title">${tc.function?.name ?? "unknown"}</div>
                            <span class="caret"></span>
                        </button>

                    </div>

                </div>
                <div class="panel-collapse collapse" id="panel-record-collapsed-auto6a3bd0d254d40" style="">
                    <div class="panel-body">
                        <strong>Args</strong><br/>
                        <code>${tc.function?.arguments ?? ""}</code>
                        ${hasResult ? html`
                                    <div>
                                        <strong>Result</strong><br/>
                                        <pre class="m-0">${resultText}</pre>
                                        ${resultMedia.map((b) => this.renderResultMedia(b))}
                                    </div>` : html`
                                    <div class="chat-toolcall-running text-muted">
                                        <thinking-indicator label="Executing"></thinking-indicator>
                                    </div>`}
                    </div>
                </div>
            </div>
        `;
  }
  renderResultMedia(block) {
    if (block.type === "image_url") {
      return html`<img src=${block.image_url.url} alt="" class="mt-2 d-block"
                             style="max-width:100%; max-height:240px;"/>`;
    }
    if (block.type === "file") {
      return html`
                <div class="mt-2">
                    <typo3-backend-icon identifier="mimetypes-other-other" size="small"/>
                    ${block.file.filename}
                </div>`;
    }
    return nothing;
  }
  contentText(content) {
    if (content == null) return "";
    if (typeof content === "string") return content;
    return content.filter((b) => b.type === "text").map((b) => b.text).join("\n");
  }
  contentMedia(content) {
    if (content == null || typeof content === "string") return [];
    return content.filter((b) => b.type !== "text");
  }
  renderStreamingBubble() {
    return html`
            <hn-agent-chat-bubble
                    author="assistant"
                    .body=${html`
                        ${unsafeHTML(this.renderMarkdown(this.streamingBuffer))}
                        <thinking-indicator></thinking-indicator>`}
                    .footer=${this.reasoningBuffer ? this.renderReasoningBlock(this.reasoningBuffer, this) : nothing}>
            </hn-agent-chat-bubble>
        `;
  }
  renderReasoningBlock(reasoning, key) {
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
  renderThinkingIndicator() {
    return html`
            <div class="p-3 align-self-start">
                <thinking-indicator label="Thinking"></thinking-indicator>
            </div>
        `;
  }
  // -- Markdown --------------------------------------------------------------
  renderMarkdown(text) {
    return DOMPurify.sanitize(marked.parse(text ?? ""));
  }
  // -- Event handlers --------------------------------------------------------
  onComposerSubmit(e) {
    if (this.isWorkspaceMismatch()) return;
    const { message, attachments } = e.detail;
    if (!message && attachments.length === 0) return;
    this.errorMessage = "";
    this.lastSubmission = { message, attachments };
    const optimistic = { role: "user", content: message };
    if (attachments.length > 0) optimistic.attachments = attachments;
    this.messages = [...this.messages, optimistic];
    this.loading = true;
    this.scrollLatestUserMessageToTop();
    this.composerRef.value?.focus();
    this.sendStreaming(message, attachments).then(() => this.finishSend());
  }
  onDismissError() {
    this.errorMessage = "";
    this.lastSubmission = null;
  }
  onStop() {
    if (this.isStreaming && (this.streamingBuffer !== "" || this.reasoningBuffer !== "")) {
      this.messages = [...this.messages, {
        role: "assistant",
        content: this.streamingBuffer,
        ...this.reasoningBuffer ? { reasoning: this.reasoningBuffer } : {}
      }];
      this.streamingBuffer = "";
      this.reasoningBuffer = "";
      this.isStreaming = false;
    }
    if (this.cancelUri) {
      void fetch(this.cancelUri, { method: "POST", keepalive: true }).catch(() => {
      });
    }
    this.abortController?.abort();
    this.requestUpdate();
  }
  onRetry() {
    if (!this.lastSubmission || this.loading) return;
    const { message, attachments } = this.lastSubmission;
    this.errorMessage = "";
    this.loading = true;
    this.sendStreaming(message, attachments).then(() => this.finishSend());
  }
  finishSend() {
    this.loading = false;
    if (this.errorMessage === "") {
      this.lastSubmission = null;
    }
    this.composerRef.value?.focus();
  }
  appendAttachments(formData, attachments) {
    if (attachments.length === 0) return;
    const payload = attachments.map((a) => ({
      uid: a.uid,
      identifier: a.identifier,
      name: a.name
    }));
    formData.append("attachments", JSON.stringify(payload));
  }
  // -- Auto-start ------------------------------------------------------------
  async doAutoStart() {
    this.loading = true;
    this.lastSubmission = { message: "", attachments: [] };
    await this.sendStreaming("");
    this.finishSend();
  }
  // -- Network: streaming (SSE) ----------------------------------------------
  async sendStreaming(message, attachments = []) {
    this.abortController = new AbortController();
    try {
      this.thinking = true;
      const formData = new FormData();
      formData.append("message", message);
      this.appendAttachments(formData, attachments);
      const response = await fetch(this.streamUri, {
        method: "POST",
        body: formData,
        signal: this.abortController.signal
      });
      if (!response.ok) {
        this.errorMessage = `Request failed (${response.status})`;
        return;
      }
      const reader = response.body.getReader();
      const decoder = new TextDecoder();
      let buffer = "";
      while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        buffer += decoder.decode(value, { stream: true });
        const result = this.parseSseBuffer(buffer);
        buffer = result.remainder;
        for (const evt of result.parsed) {
          this.handleSseEvent(evt.event, evt.data);
        }
      }
    } catch (err) {
      this.thinking = false;
      this.isStreaming = false;
      if (err.name !== "AbortError") {
        this.errorMessage = err.message || String(err);
      }
    } finally {
      this.abortController = null;
    }
  }
  // -- SSE parsing -----------------------------------------------------------
  parseSseBuffer(buffer) {
    const parsed = [];
    const blocks = buffer.split("\n\n");
    const remainder = blocks.pop();
    for (const block of blocks) {
      if (!block.trim()) continue;
      let event = "message";
      let data = "";
      for (const line of block.split("\n")) {
        if (line.startsWith("event: ")) {
          event = line.slice(7);
        } else if (line.startsWith("data: ")) {
          data = line.slice(6);
        }
      }
      if (data) {
        try {
          parsed.push({ event, data: JSON.parse(data) });
        } catch {
        }
      }
    }
    return { parsed, remainder };
  }
  // -- SSE event dispatch ----------------------------------------------------
  handleSseEvent(event, data) {
    switch (event) {
      case "llm_start":
        this.thinking = true;
        break;
      case "content_delta":
        this.thinking = false;
        this.isStreaming = true;
        this.streamingBuffer += data.text || "";
        break;
      case "reasoning_delta":
        this.thinking = false;
        this.isStreaming = true;
        this.reasoningBuffer += data.text || "";
        break;
      case "tool_call_delta":
        this.thinking = false;
        break;
      case "user_message": {
        const msg = data.message;
        if (msg) {
          this.messages = [...this.messages, msg];
          this.scrollLatestUserMessageToTop();
        }
        break;
      }
      case "assistant_message": {
        this.thinking = false;
        const msg = data.message;
        if (this.isStreaming) {
          if (Array.isArray(msg?.tool_calls) && msg.tool_calls.length > 0) {
            this.messages = [...this.messages, msg];
          } else {
            this.messages = [...this.messages, {
              role: "assistant",
              content: msg?.content || this.streamingBuffer,
              ...msg?.reasoning || this.reasoningBuffer ? { reasoning: msg?.reasoning || this.reasoningBuffer } : {},
              ...msg?.reasoning_details ? { reasoning_details: msg.reasoning_details } : {}
            }];
          }
          this.isStreaming = false;
          this.streamingBuffer = "";
          this.reasoningBuffer = "";
        } else if (msg) {
          this.messages = [...this.messages, msg];
        }
        break;
      }
      case "tool_start":
        break;
      case "tool_result": {
        const toolCallId = data.tool_call_id;
        const content = data.content;
        this.messages = this.messages.map((msg) => {
          if (msg.role !== "assistant" || !msg.tool_calls) return msg;
          if (!msg.tool_calls.some((tc) => tc.id === toolCallId)) return msg;
          return {
            ...msg,
            tool_calls: msg.tool_calls.map(
              (tc) => tc.id === toolCallId ? { ...tc, result: content } : tc
            )
          };
        });
        break;
      }
      case "change_tracked": {
        const change = data;
        this.changes = [...this.changes, change];
        document.dispatchEvent(new CustomEvent("agent:record-changed", { detail: change }));
        break;
      }
      case "done":
        this.thinking = false;
        this.isStreaming = false;
        this.streamingBuffer = "";
        this.reasoningBuffer = "";
        if (Array.isArray(data.messages)) {
          this.messages = this.mergeToolResults(data.messages);
        }
        document.dispatchEvent(new CustomEvent("agent:record-changed"));
        break;
      case "error":
        this.thinking = false;
        this.isStreaming = false;
        this.errorMessage = data.error || "Unknown error";
        break;
    }
  }
  // -- Helpers ---------------------------------------------------------------
  /**
   * Merge tool-role messages into the tool_calls of their parent assistant
   * message so that call + result are rendered in the same bubble.
   */
  mergeToolResults(msgs) {
    const resultMap = /* @__PURE__ */ new Map();
    for (const msg of msgs) {
      if (msg.role === "tool" && msg.tool_call_id && msg.content !== void 0) {
        resultMap.set(msg.tool_call_id, msg.content);
      }
    }
    if (resultMap.size === 0) return [...msgs];
    const merged = [];
    for (const msg of msgs) {
      if (msg.role === "tool" && msg.tool_call_id && resultMap.has(msg.tool_call_id)) {
        continue;
      }
      if (msg.role === "assistant" && msg.tool_calls) {
        merged.push({
          ...msg,
          tool_calls: msg.tool_calls.map((tc) => {
            const result = tc.id ? resultMap.get(tc.id) : void 0;
            return result !== void 0 ? { ...tc, result } : tc;
          })
        });
      } else {
        merged.push(msg);
      }
    }
    return merged;
  }
  computeTurns() {
    const turns = [];
    for (const msg of this.messages) {
      if (msg.role === "system") continue;
      if (msg.role === "user" || turns.length === 0) {
        turns.push([msg]);
      } else {
        turns[turns.length - 1].push(msg);
      }
    }
    return turns;
  }
  scrollLatestUserMessageToTop() {
    void this.updateComplete.then(() => {
      const container = this.messagesContainerRef.value;
      const latest = this.latestUserBubbleRef.value;
      if (!container || !latest) return;
      const containerTop = container.getBoundingClientRect().top;
      const latestTop = latest.getBoundingClientRect().top;
      container.scrollTo({ top: container.scrollTop + latestTop - containerTop, behavior: "smooth" });
    });
  }
};
__decorateClass([
  property({ attribute: "stream-uri" })
], ChatElement.prototype, "streamUri", 2);
__decorateClass([
  property({ attribute: "cancel-uri" })
], ChatElement.prototype, "cancelUri", 2);
__decorateClass([
  property({ attribute: "auto-start" })
], ChatElement.prototype, "autoStart", 2);
__decorateClass([
  property({ attribute: "initial-prompt" })
], ChatElement.prototype, "initialPrompt", 2);
__decorateClass([
  property({ attribute: "task-workspace-id", type: Number })
], ChatElement.prototype, "taskWorkspaceId", 2);
__decorateClass([
  property({ attribute: "task-workspace-title" })
], ChatElement.prototype, "taskWorkspaceTitle", 2);
__decorateClass([
  property({ attribute: "active-workspace-id", type: Number })
], ChatElement.prototype, "activeWorkspaceId", 2);
__decorateClass([
  property({ attribute: "active-workspace-title" })
], ChatElement.prototype, "activeWorkspaceTitle", 2);
__decorateClass([
  property({ attribute: "default-upload-folder" })
], ChatElement.prototype, "defaultUploadFolder", 2);
__decorateClass([
  property({ attribute: "file-browser-uri" })
], ChatElement.prototype, "fileBrowserUri", 2);
__decorateClass([
  property({
    attribute: "initial-messages",
    converter: {
      fromAttribute(value) {
        if (!value) return [];
        try {
          return JSON.parse(value);
        } catch {
          return [];
        }
      }
    }
  })
], ChatElement.prototype, "initialMessages", 2);
__decorateClass([
  property({
    attribute: "initial-changes",
    converter: {
      fromAttribute(value) {
        if (!value) return [];
        try {
          return JSON.parse(value);
        } catch {
          return [];
        }
      }
    }
  })
], ChatElement.prototype, "initialChanges", 2);
__decorateClass([
  state()
], ChatElement.prototype, "changes", 2);
__decorateClass([
  state()
], ChatElement.prototype, "messages", 2);
__decorateClass([
  state()
], ChatElement.prototype, "loading", 2);
__decorateClass([
  state()
], ChatElement.prototype, "errorMessage", 2);
__decorateClass([
  state()
], ChatElement.prototype, "thinking", 2);
__decorateClass([
  state()
], ChatElement.prototype, "streamingBuffer", 2);
__decorateClass([
  state()
], ChatElement.prototype, "reasoningBuffer", 2);
__decorateClass([
  state()
], ChatElement.prototype, "isStreaming", 2);
__decorateClass([
  state()
], ChatElement.prototype, "lastSubmission", 2);
ChatElement = __decorateClass([
  customElement("hn-agent-chat")
], ChatElement);
export {
  ChatElement
};
//# sourceMappingURL=chat-element.js.map
