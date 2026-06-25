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
import { customElement, property, query, state } from "lit/decorators.js";
import { unsafeHTML } from "lit/directives/unsafe-html.js";
import { createRef, ref } from "lit/directives/ref.js";
import { marked } from "marked";
import DOMPurify from "dompurify";
import DragUploader from "@typo3/backend/drag-uploader.js";
import Modal from "@typo3/backend/modal.js";
import { MessageUtility } from "@typo3/backend/utility/message-utility.js";
import "./thinking-indicator.js";
import "@hn/agent/attachment-chip-elements.js";
marked.setOptions({ breaks: true, gfm: true });
let ChatElement = class extends LitElement {
  constructor() {
    super(...arguments);
    this.sendUri = "";
    this.streamUri = "";
    this.cancelUri = "";
    this.autoStart = "";
    this.initialPrompt = "";
    this.taskWorkspaceId = 0;
    this.taskWorkspaceTitle = "";
    this.activeWorkspaceId = 0;
    this.activeWorkspaceTitle = "";
    this.switchWorkspaceUri = "";
    this.defaultUploadFolder = "";
    this.fileBrowserUri = "";
    this.preflightUri = "";
    this.initialMessages = [];
    this.initialChanges = [];
    this.changes = [];
    this.messages = [];
    this.inputValue = "";
    this.loading = false;
    this.errorMessage = "";
    this.thinking = false;
    this.streamingBuffer = "";
    this.reasoningBuffer = "";
    this.isStreaming = false;
    this.attachments = [];
    this.lastSubmission = null;
    // Active SSE fetch's AbortController while a stream is in flight, null otherwise.
    // The Stop button calls .abort() on it; the reader loop in sendStreaming sees the
    // AbortError and exits cleanly. PHP-side, the disconnect is picked up by
    // SseStream's connection_aborted() check on the next flush.
    this.abortController = null;
    // DragUploader dispatches `uploadSuccess` on its `data-dropzone-trigger`
    // element (not the wrapper, and not bubbling) — so we have to listen on
    // the trigger button itself.
    this.uploadTriggerRef = createRef();
    this.uploadZoneRef = createRef();
    this.messagesContainerRef = createRef();
    this.latestUserBubbleRef = createRef();
    this.elementBrowserListener = (e) => {
      if (!MessageUtility.verifyOrigin(e.origin)) return;
      const data = e.data;
      if (data.actionName !== "typo3:elementBrowser:elementAdded") return;
      if (data.fieldName !== "hn-agent-chat") return;
      const raw = (data.value ?? "").toString();
      if (!raw) return;
      const numericUid = /^\d+$/.test(raw) ? parseInt(raw, 10) : 0;
      if (numericUid > 0) {
        this.addAttachment({ uid: numericUid, name: data.label || `sys_file:${numericUid}` });
      } else {
        this.addAttachment({ identifier: raw, name: data.label || raw });
      }
    };
    this.onKeydownGlobal = (e) => {
      if (e.key !== "Escape") return;
      if (e.defaultPrevented) return;
      if (!this.loading || this.abortController === null) return;
      e.preventDefault();
      this.onStop();
    };
    this.uploadSuccessListener = (e) => {
      const detail = e.detail;
      const payload = Array.isArray(detail) ? detail[1] : void 0;
      const file = payload?.upload?.[0];
      if (!file) return;
      this.addAttachment({ uid: file.uid, identifier: file.id, name: file.name, iconHtml: file.icon });
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
    const zoneEl = this.uploadZoneRef.value;
    if (zoneEl) {
      new DragUploader(zoneEl);
    }
    this.uploadTriggerRef.value?.addEventListener("uploadSuccess", this.uploadSuccessListener);
    document.addEventListener("keydown", this.onKeydownGlobal);
    if ((this.autoStart === "1" || this.autoStart === "true") && this.streamUri && !this.isWorkspaceMismatch()) {
      this.doAutoStart();
    }
    this.inputEl?.focus();
  }
  disconnectedCallback() {
    super.disconnectedCallback();
    this.uploadTriggerRef.value?.removeEventListener("uploadSuccess", this.uploadSuccessListener);
    window.removeEventListener("message", this.elementBrowserListener);
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
    const canSubmit = !inputDisabled && (this.inputValue.trim() !== "" || this.attachments.length > 0);
    const uploadEnabled = !!this.defaultUploadFolder;
    const pickEnabled = !!this.fileBrowserUri;
    const showHeader = mismatch || !!this.errorMessage;
    return html`
            ${showHeader ? html`
                <div class="chat-header">
                    ${mismatch ? this.renderWorkspaceMismatch() : nothing}
                    ${this.errorMessage ? this.renderErrorMessage() : nothing}
                </div>` : nothing}
            <div class="chat-body">

                <div class="chat-messages mx-3 py-4" ${ref(this.messagesContainerRef)}>
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


                <div
                        class="chat-upload-zone"
                        ${ref(this.uploadZoneRef)}
                        data-target-folder=${this.defaultUploadFolder}
                        data-max-file-size="0"
                        data-dropzone-target=".chat-upload-anchor"
                        data-dropzone-trigger=".chat-upload-trigger"
                        data-default-action="rename">


                    <form class="task-form" @submit=${this.onSubmit}>
            <textarea
                    class="message-control"
                    name="message"
                    rows="2"
                    placeholder="Type a follow-up message\u2026"
                    .value=${this.inputValue}
                    @input=${this.onInput}
                    @keydown=${this.onKeydown}
            ></textarea>

                        ${this.attachments.length > 0 ? html`
                                    <hn-agent-attachment-chips
                                            .attachments=${this.attachments}
                                            @remove=${(e) => this.removeAttachment(e.detail.index)}>
                                    </hn-agent-attachment-chips>
                                ` : nothing}

                        <div class="chat-upload-anchor" style="display:none"></div>
                        <div class="w-100 d-flex flex-row">
                            ${this.renderAttachmentsBar(uploadEnabled, pickEnabled, inputDisabled)}
                            <div class="ms-auto">
                                ${this.loading ? html`
                                            <button type="button"
                                                    class="btn btn-sm"
                                                    title="Antwort abbrechen"
                                                    ?disabled=${this.abortController === null}
                                                    @click=${this.onStop}>
                                                <typo3-backend-icon identifier="actions-close" size="small"/>
                                            </button>` : html`
                                            <button type="submit" class="btn btn-sm" ?disabled=${!canSubmit}>
                                                <typo3-backend-icon
                                                        identifier="actions-arrow-down-start-alt"
                                                        size="small"/>
                                            </button>`}
                            </div>
                        </div>
                    </form>


                </div>
            </div>
        `;
  }
  renderAttachmentsBar(uploadEnabled, pickEnabled, inputDisabled) {
    return html`
            <div>
                <button type="button"
                        class="chat-upload-trigger btn btn-sm btn-default"
                        ${ref(this.uploadTriggerRef)}
                        ?disabled=${inputDisabled || !uploadEnabled}
                        title=${uploadEnabled ? "Datei hochladen" : "Kein Upload-Ordner verf\xFCgbar"}>
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
                        <a href=${this.switchWorkspaceUri}
                           target="_top"
                           class="btn btn-warning text-decoration-none ${this.switchWorkspaceUri ? "" : "disabled"}"
                        <typo3-backend-icon identifier="apps-toolbar-menu-workspace" size="small"></typo3-backend-icon>
                        ${buttonLabel}
                        </a>
                    </div>
                </div>

        `;
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
    const roleLabel = role === "user" ? "you" : role;
    if (role === "assistant") {
      const assistantText = msg.content != null ? this.contentText(msg.content) : "";
      return html`
                <div class="card card-default me-3">
                    <div class="card-header">
                        <div class="card-header-body">
                            <span class="card-subtitle">${roleLabel}</span>
                        </div>
                    </div>
                    
                    ${assistantText ? html`
                                <div class="card-body">${unsafeHTML(this.renderMarkdown(assistantText))}</div>` : nothing}

                    ${msg.reasoning || msg.tool_calls && msg.tool_calls.length > 0 ? html`
                    <div class="card-footer">
                        ${msg.reasoning ? html`
                                    ${this.renderReasoningBlock(msg.reasoning, msg)}` : nothing}
    
                        ${msg.tool_calls && msg.tool_calls.length > 0 ? html`
                                    ${this.renderToolCallsGroup(msg.tool_calls)}` : nothing}
                        
                    </div>` : nothing}
                    
                </div>
            `;
    }
    const attachments = msg.attachments ?? [];
    const userText = msg.content != null ? this.contentText(msg.content) : "";
    return html`
            <div class="card card-success align-self-end ms-3" ${isLatestUser ? ref(this.latestUserBubbleRef) : nothing}>
                <div class="card-header">

                    <div class="card-header-body">
                        <span class="card-subtitle">${roleLabel}</span>
                    </div>
                </div>
                ${userText ? html`
                            <div class="card-body"><p class="card-text">${userText}</p></div>` : nothing}


                ${attachments.length > 0 ? html`
                            <div class="card-footer">
                                <hn-agent-attachment-chips
                                        readonly
                                        .attachments=${attachments}>
                                </hn-agent-attachment-chips>
                            </div>` : nothing}


            </div>
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
            <div class="card card-default me-3">
                <div class="card-header">
                    <div class="card-header-body">
                        <span class="card-subtitle">assistant</span>
                    </div>
                </div>

                <div class="card-body">
                    ${unsafeHTML(this.renderMarkdown(this.streamingBuffer))}
                    <thinking-indicator></thinking-indicator>
                </div>
                

                ${this.reasoningBuffer ? html`
                    <div class="card-footer">
                        ${this.renderReasoningBlock(this.reasoningBuffer, this)}
                    </div>` : nothing}
            </div>
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
  onSubmit(e) {
    e.preventDefault();
    if (this.isWorkspaceMismatch()) return;
    const message = this.inputValue.trim();
    const attachments = this.attachments;
    if (!message && attachments.length === 0) return;
    this.errorMessage = "";
    this.lastSubmission = { message, attachments };
    const optimistic = { role: "user", content: message };
    if (attachments.length > 0) optimistic.attachments = attachments;
    this.messages = [...this.messages, optimistic];
    this.inputValue = "";
    this.attachments = [];
    this.loading = true;
    this.scrollLatestUserMessageToTop();
    this.inputEl?.focus();
    if (this.streamUri) {
      this.sendStreaming(message, attachments).then(() => this.finishSend());
    } else {
      this.sendBlocking(message, attachments).then(() => this.finishSend());
    }
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
    const promise = this.streamUri ? this.sendStreaming(message, attachments) : this.sendBlocking(message, attachments);
    promise.then(() => this.finishSend());
  }
  addAttachment(att) {
    this.attachments = [...this.attachments, att];
    if (this.preflightUri && (att.uid !== void 0 || att.identifier)) {
      void this.preflightAttachment(att);
    }
  }
  async preflightAttachment(att) {
    try {
      const url = new URL(this.preflightUri, window.location.origin);
      if (att.uid !== void 0) url.searchParams.set("uid", String(att.uid));
      if (att.identifier) url.searchParams.set("identifier", att.identifier);
      const response = await fetch(url.toString(), { headers: { "Accept": "application/json" } });
      if (!response.ok) return;
      const info = await response.json();
      this.attachments = this.attachments.map((a) => {
        const sameUid = info.uid !== void 0 && a.uid === info.uid;
        const sameIdent = !!info.identifier && a.identifier === info.identifier;
        if (!sameUid && !sameIdent) return a;
        return {
          ...a,
          mime_type: info.mime || a.mime_type,
          size: typeof info.size === "number" ? info.size : a.size,
          readableByLlm: info.readableByLlm,
          reason: info.reason ?? void 0
        };
      });
    } catch {
    }
  }
  removeAttachment(index) {
    this.attachments = this.attachments.filter((_, i) => i !== index);
  }
  onPickClick() {
    if (!this.fileBrowserUri) return;
    window.addEventListener("message", this.elementBrowserListener);
    const modal = Modal.advanced({
      type: Modal.types.iframe,
      content: this.fileBrowserUri,
      size: Modal.sizes.large
    });
    modal.addEventListener("typo3-modal-hide", () => {
      window.removeEventListener("message", this.elementBrowserListener);
    });
  }
  onInput(e) {
    this.inputValue = e.target.value;
  }
  onKeydown(e) {
    if (e.key === "Enter" && !e.shiftKey) {
      e.preventDefault();
      if (this.loading || this.isWorkspaceMismatch()) {
        return;
      }
      e.target.closest("form")?.requestSubmit();
    }
  }
  finishSend() {
    this.loading = false;
    if (this.errorMessage === "") {
      this.lastSubmission = null;
    }
    this.inputEl?.focus();
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
  // -- Network: blocking -----------------------------------------------------
  async sendBlocking(message, attachments = []) {
    try {
      const formData = new FormData();
      formData.append("message", message);
      this.appendAttachments(formData, attachments);
      const response = await fetch(this.sendUri, {
        method: "POST",
        headers: {
          "X-Requested-With": "XMLHttpRequest",
          "Accept": "application/json"
        },
        body: formData
      });
      const data = await response.json();
      if (!response.ok || data.error) {
        this.errorMessage = data.error || `Request failed (${response.status})`;
      }
      if (Array.isArray(data.messages)) {
        this.messages = data.messages;
      }
    } catch (err) {
      this.errorMessage = err.message || String(err);
    }
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
  property({ attribute: "send-uri" })
], ChatElement.prototype, "sendUri", 2);
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
  property({ attribute: "switch-workspace-uri" })
], ChatElement.prototype, "switchWorkspaceUri", 2);
__decorateClass([
  property({ attribute: "default-upload-folder" })
], ChatElement.prototype, "defaultUploadFolder", 2);
__decorateClass([
  property({ attribute: "file-browser-uri" })
], ChatElement.prototype, "fileBrowserUri", 2);
__decorateClass([
  property({ attribute: "preflight-uri" })
], ChatElement.prototype, "preflightUri", 2);
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
], ChatElement.prototype, "inputValue", 2);
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
], ChatElement.prototype, "attachments", 2);
__decorateClass([
  state()
], ChatElement.prototype, "lastSubmission", 2);
__decorateClass([
  query("textarea")
], ChatElement.prototype, "inputEl", 2);
ChatElement = __decorateClass([
  customElement("hn-agent-chat")
], ChatElement);
export {
  ChatElement
};
//# sourceMappingURL=chat-element.js.map
