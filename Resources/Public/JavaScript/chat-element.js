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
import { marked } from "marked";
import DOMPurify from "dompurify";
import DragUploader from "@typo3/backend/drag-uploader.js";
import Modal from "@typo3/backend/modal.js";
import { MessageUtility } from "@typo3/backend/utility/message-utility.js";
import "./thinking-indicator.js";
marked.setOptions({ breaks: true, gfm: true });
let ChatElement = class extends LitElement {
  constructor() {
    super(...arguments);
    this.sendUri = "";
    this.streamUri = "";
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
    this.isStreaming = false;
    this.attachments = [];
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
    this.uploadSuccessListener = (e) => {
      const detail = e.detail;
      const payload = Array.isArray(detail) ? detail[1] : void 0;
      const file = payload?.upload?.[0];
      if (!file) return;
      this.addAttachment({ uid: file.uid, identifier: file.id, name: file.name, iconHtml: file.icon });
    };
  }
  // No Shadow DOM — use TYPO3 backend Bootstrap CSS
  createRenderRoot() {
    return this;
  }
  // -- Lifecycle -------------------------------------------------------------
  firstUpdated() {
    this.messages = this.mergeToolResults(this.initialMessages);
    this.changes = [...this.initialChanges];
    this.scrollToBottom();
    if (this.uploadZoneEl) {
      new DragUploader(this.uploadZoneEl);
    }
    this.uploadTriggerEl?.addEventListener("uploadSuccess", this.uploadSuccessListener);
    if ((this.autoStart === "1" || this.autoStart === "true") && this.streamUri && !this.isWorkspaceMismatch()) {
      this.doAutoStart();
    }
  }
  disconnectedCallback() {
    super.disconnectedCallback();
    this.uploadTriggerEl?.removeEventListener("uploadSuccess", this.uploadSuccessListener);
    window.removeEventListener("message", this.elementBrowserListener);
  }
  isWorkspaceMismatch() {
    if (!this.taskWorkspaceId) return false;
    return this.taskWorkspaceId !== this.activeWorkspaceId;
  }
  updated() {
    this.scrollToBottom();
  }
  // -- Render ----------------------------------------------------------------
  render() {
    const mismatch = this.isWorkspaceMismatch();
    const inputDisabled = this.loading || mismatch;
    const canSubmit = !inputDisabled && (this.inputValue.trim() !== "" || this.attachments.length > 0);
    const uploadEnabled = !!this.defaultUploadFolder;
    const pickEnabled = !!this.fileBrowserUri;
    return html`
      <div class="chat-container message-fade">
        <div class="chat-messages d-flex flex-column gap-3 overflow-auto mx-3 pb-3">
          ${this.messages.map((msg) => this.renderMessage(msg))}
          ${this.isStreaming ? this.renderStreamingBubble() : nothing}
          ${this.thinking && !this.isStreaming ? this.renderThinkingIndicator() : nothing}
        </div>

        <div
            class="chat-upload-zone"
            data-target-folder=${this.defaultUploadFolder}
            data-max-file-size="0"
            data-dropzone-target=".chat-upload-anchor"
            data-dropzone-trigger=".chat-upload-trigger"
            data-default-action="rename">

          

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
            ${this.attachments.length > 0 ? html`<div class="chat-attachments d-flex flex-wrap gap-2">
                  ${this.attachments.map((a, i) => this.renderAttachmentChip(a, () => this.removeAttachment(i)))}
                </div>` : nothing}
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

          ${this.errorMessage ? html`
                <div class="alert alert-danger">${this.errorMessage}</div>` : nothing}
        </div>

      </div>
    `;
  }
  renderAttachmentsBar(uploadEnabled, pickEnabled, inputDisabled) {
    return html`
      <div>
        <button type="button"
                class="chat-upload-trigger btn btn-sm btn-default"
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
  renderAttachmentChip(att, onRemove) {
    const thumbUrl = this.buildThumbnailUrl(att);
    const onThumbError = (e) => {
      const img = e.target;
      img.style.display = "none";
      const fallback = img.nextElementSibling;
      if (fallback) fallback.style.display = "";
    };
    const notReadable = att.readableByLlm === false;
    const warnTitle = notReadable ? `LLM kann den Inhalt nicht via ReadFile lesen \u2014 nur Metadaten${att.reason ? ` (${att.reason})` : ""}` : att.unresolvable ? "Datei nicht aufl\xF6sbar" : "";
    return html`
      <span class="chat-attachment-chip d-inline-flex align-items-center gap-2 border rounded bg-body p-1 ${onRemove ? "pe-2" : "px-2"}"
            title=${warnTitle}>
        ${thumbUrl ? html`
              <img src=${thumbUrl} alt="" class="chat-attachment-thumb rounded" @error=${onThumbError}/>
              <span class="chat-attachment-icon rounded" style="display:none">${this.renderFallbackIcon(att)}</span>` : html`<span class="chat-attachment-icon rounded">${this.renderFallbackIcon(att)}</span>`}
        <span class="chat-attachment-name ${att.unresolvable ? "text-decoration-line-through opacity-75" : ""}">${att.name}</span>
        ${notReadable ? html`<span class="chat-attachment-warn badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"
                       title=${warnTitle}>
              <typo3-backend-icon identifier="actions-exclamation" size="small"/>
            </span>` : nothing}
        ${onRemove ? html`<button type="button"
                  class="btn btn-sm p-0 border-0 text-muted"
                  title="Entfernen"
                  @click=${onRemove}>\u00d7</button>` : nothing}
      </span>
    `;
  }
  renderFallbackIcon(att) {
    if (att.iconHtml) return html`${unsafeHTML(att.iconHtml)}`;
    return html`<typo3-backend-icon identifier="mimetypes-other-other" size="medium"></typo3-backend-icon>`;
  }
  buildThumbnailUrl(att) {
    const base = window.top?.TYPO3?.settings?.Resource?.thumbnailUrl;
    if (!base) return "";
    const ref = att.uid ?? att.identifier;
    if (ref === void 0 || ref === null || ref === "") return "";
    const url = new URL(base, window.location.origin);
    url.searchParams.set("identifier", String(ref));
    url.searchParams.set("size", "large");
    url.searchParams.set("keepAspectRatio", "false");
    return url.toString();
  }
  renderWorkspaceMismatch() {
    const mismatchTemplate = TYPO3?.lang?.["workspace.chat.mismatch"] ?? 'This task belongs to workspace "%s", but you are currently in "%s". Switch to "%s" to continue the conversation.';
    const buttonTemplate = TYPO3?.lang?.["workspace.chat.switchButton"] ?? 'Switch to workspace "%s"';
    const taskTitle = this.taskWorkspaceTitle || `#${this.taskWorkspaceId}`;
    const activeTitle = this.activeWorkspaceTitle || (this.activeWorkspaceId > 0 ? `#${this.activeWorkspaceId}` : "Live");
    const message = mismatchTemplate.replace("%s", taskTitle).replace("%s", activeTitle).replace("%s", taskTitle);
    const buttonLabel = buttonTemplate.replace("%s", taskTitle);
    return html`
      <div class="alert alert-warning d-flex align-items-center justify-content-between mx-3 mb-2 gap-3">
        <div>${message}</div>
        <a href=${this.switchWorkspaceUri}
           target="_top"
           class="btn btn-warning ${this.switchWorkspaceUri ? "" : "disabled"}"
           @click=${this.onSwitchClick}>
          ${buttonLabel}
        </a>
      </div>
    `;
  }
  onSwitchClick(e) {
    if (!this.switchWorkspaceUri) return;
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
    e.preventDefault();
    const topWindow = window.top ?? window;
    topWindow.location.href = this.switchWorkspaceUri;
  }
  renderMessage(msg) {
    const role = msg.role || "unknown";
    if (role === "system") return nothing;
    const roleLabel = role === "user" ? "you" : role;
    if (role === "assistant") {
      const assistantText = msg.content !== void 0 ? this.contentText(msg.content) : "";
      return html`
        <div class="rounded-4 bg-white border p-3 me-3">
          <div class="chat-msg-role fw-bold small opacity-75 mb-1 text-uppercase">${roleLabel}</div>
          ${assistantText ? html`<div class="chat-msg-content">${unsafeHTML(this.renderMarkdown(assistantText))}</div>` : nothing}
          ${msg.tool_calls && msg.tool_calls.length > 0 ? this.renderToolCallsGroup(msg.tool_calls) : nothing}
        </div>
      `;
    }
    const attachments = msg.attachments ?? [];
    const userText = msg.content !== void 0 ? this.contentText(msg.content) : "";
    return html`
      <div class="rounded-4 bg-success-subtle border p-3 ms-3 align-self-end">
        <div class="chat-msg-role fw-bold small opacity-75 mb-1 text-uppercase">${roleLabel}</div>
        ${userText ? html`<pre class="chat-msg-prewrap m-0">${userText}</pre>` : nothing}
        ${attachments.length > 0 ? html`<div class="chat-attachments d-flex flex-wrap gap-2 ${msg.content ? "mt-2" : ""}">
              ${attachments.map((a) => this.renderAttachmentChip(a))}
            </div>` : nothing}
      </div>
    `;
  }
  renderToolCallsGroup(tcs) {
    const count = tcs.length;
    const noun = count === 1 ? "Tool Call" : "Tool Calls";
    const running = tcs.some((tc) => tc.result === void 0);
    return html`
      <details class="chat-toolcalls mt-2 p-2 rounded border bg-body-tertiary small" ?open=${running}>
        <summary class="d-flex align-items-center gap-2">
          <typo3-backend-icon identifier="actions-cog" size="small"></typo3-backend-icon>
          <span><strong>${count}</strong> ${noun}</span>
          ${running ? html`<thinking-indicator class="ms-1"></thinking-indicator>` : nothing}
        </summary>
        <div class="d-flex flex-column gap-2 mt-2">
          ${tcs.map((tc) => this.renderToolCall(tc))}
        </div>
      </details>
    `;
  }
  renderToolCall(tc) {
    const hasResult = tc.result !== void 0;
    const resultText = hasResult ? this.contentText(tc.result) : "";
    const resultMedia = hasResult ? this.contentMedia(tc.result) : [];
    return html`
      <details class="chat-toolcall p-2 rounded border bg-body font-monospace small" ?open=${!hasResult}>
        <summary>
          ${tc.function?.name ?? "unknown"}
        </summary>
        <div class="py-3">

          <div class="mb-3">
            <strong>Args</strong><br/>
            <code>${tc.function?.arguments ?? ""}</code>
          </div>
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
      </details>
    `;
  }
  renderResultMedia(block) {
    if (block.type === "image_url") {
      return html`<img src=${block.image_url.url} alt="" class="mt-2 d-block" style="max-width:100%; max-height:240px;"/>`;
    }
    if (block.type === "file") {
      return html`<div class="mt-2"><typo3-backend-icon identifier="mimetypes-other-other" size="small"/> ${block.file.filename}</div>`;
    }
    return nothing;
  }
  contentText(content) {
    if (typeof content === "string") return content;
    return content.filter((b) => b.type === "text").map((b) => b.text).join("\n");
  }
  contentMedia(content) {
    if (typeof content === "string") return [];
    return content.filter((b) => b.type !== "text");
  }
  renderStreamingBubble() {
    return html`
      <div class="rounded-4 bg-white border p-3">
        <div class="chat-msg-role fw-bold small opacity-75 mb-1 text-uppercase">assistant</div>
        <div class="chat-msg-content">
          ${unsafeHTML(this.renderMarkdown(this.streamingBuffer))}<thinking-indicator></thinking-indicator>
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
    const optimistic = { role: "user", content: message };
    if (attachments.length > 0) optimistic.attachments = attachments;
    this.messages = [...this.messages, optimistic];
    this.inputValue = "";
    this.attachments = [];
    this.loading = true;
    if (this.streamUri) {
      this.sendStreaming(message, attachments).then(() => this.finishSend());
    } else {
      this.sendBlocking(message, attachments).then(() => this.finishSend());
    }
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
      e.target.closest("form")?.requestSubmit();
    }
  }
  finishSend() {
    this.loading = false;
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
    try {
      this.thinking = true;
      const formData = new FormData();
      formData.append("message", message);
      this.appendAttachments(formData, attachments);
      const response = await fetch(this.streamUri, {
        method: "POST",
        body: formData
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
      this.errorMessage = err.message || String(err);
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
      case "tool_call_delta":
        this.thinking = false;
        break;
      case "user_message": {
        const msg = data.message;
        if (msg) {
          this.messages = [...this.messages, msg];
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
              content: msg?.content || this.streamingBuffer
            }];
          }
          this.isStreaming = false;
          this.streamingBuffer = "";
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
        document.dispatchEvent(new CustomEvent("agent:record-changed"));
        break;
      }
      case "done":
        this.thinking = false;
        this.isStreaming = false;
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
  scrollToBottom() {
    const el = this.renderRoot.querySelector(".chat-messages");
    if (el) {
      el.scrollTop = el.scrollHeight;
    }
  }
};
__decorateClass([
  property({ attribute: "send-uri" })
], ChatElement.prototype, "sendUri", 2);
__decorateClass([
  property({ attribute: "stream-uri" })
], ChatElement.prototype, "streamUri", 2);
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
], ChatElement.prototype, "isStreaming", 2);
__decorateClass([
  state()
], ChatElement.prototype, "attachments", 2);
__decorateClass([
  query("textarea")
], ChatElement.prototype, "inputEl", 2);
__decorateClass([
  query(".chat-upload-trigger")
], ChatElement.prototype, "uploadTriggerEl", 2);
__decorateClass([
  query(".chat-upload-zone")
], ChatElement.prototype, "uploadZoneEl", 2);
ChatElement = __decorateClass([
  customElement("hn-agent-chat")
], ChatElement);
export {
  ChatElement
};
//# sourceMappingURL=chat-element.js.map
