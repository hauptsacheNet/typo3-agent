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
import "@typo3/backend/drag-uploader.js";
import Modal from "@typo3/backend/modal.js";
import { MessageUtility } from "@typo3/backend/utility/message-utility.js";
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
    this.initialMessages = [];
    this.messages = [];
    this.inputValue = "";
    this.loading = false;
    this.errorMessage = "";
    this.thinking = false;
    this.streamingBuffer = "";
    this.isStreaming = false;
    this.activeTools = /* @__PURE__ */ new Map();
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
    this.scrollToBottom();
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
          ${this.renderActiveTools()}
          ${this.isStreaming ? this.renderStreamingBubble() : nothing}
          ${this.thinking && !this.isStreaming ? this.renderThinkingIndicator() : nothing}
        </div>

        <div class="t3js-drag-uploader chat-upload-zone"
             data-target-folder=${this.defaultUploadFolder}
             data-max-file-size="0"
             data-dropzone-target=".chat-upload-anchor"
             data-dropzone-trigger=".chat-upload-trigger"
             data-default-action="rename"
             data-file-irre-object="hn-agent-chat">

          <div class="chat-upload-anchor"></div>

          ${mismatch ? this.renderWorkspaceMismatch() : nothing}

          ${this.renderAttachmentsBar(uploadEnabled, pickEnabled, inputDisabled)}

          <form class="position-relative" @submit=${this.onSubmit}>
          <textarea
              name="message"
              class="chat-input d-block w-100 rounded-4 border p-3 bg-white"
              rows="2"
              placeholder="Type a follow-up message\u2026"
              .value=${this.inputValue}
              ?disabled=${inputDisabled}
              @input=${this.onInput}
              @keydown=${this.onKeydown}
          ></textarea>
            <div class="position-absolute bottom-0 end-0 p-2">
              <button type="submit" class="btn" ?disabled=${!canSubmit}>
                <typo3-backend-icon
                    identifier="actions-arrow-down-start-alt"
                    size="small"/>
              </button>
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
      <div class="chat-attachments d-flex flex-wrap align-items-center gap-2 mb-2">
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
        ${this.attachments.map((a, i) => this.renderAttachmentChip(a, i))}
      </div>
    `;
  }
  renderAttachmentChip(att, index) {
    return html`
      <span class="badge bg-secondary d-inline-flex align-items-center gap-1">
        ${att.iconHtml ? unsafeHTML(att.iconHtml) : html`<typo3-backend-icon identifier="mimetypes-other-other" size="small"/>`}
        <span>${att.name}</span>
        <button type="button"
                class="btn btn-sm p-0 ms-1 text-white border-0"
                title="Entfernen"
                @click=${() => this.removeAttachment(index)}>\u00d7</button>
      </span>
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
      return html`
        <div class="rounded-4 bg-white border p-3 me-3">
          <div class="chat-msg-role fw-bold small opacity-75 mb-1 text-uppercase">${roleLabel}</div>
          ${msg.content ? html`<div class="chat-msg-content">${unsafeHTML(this.renderMarkdown(msg.content))}</div>` : nothing}
          ${msg.tool_calls?.map((tc) => this.renderToolCall(tc)) ?? nothing}
        </div>
      `;
    }
    return html`
      <div class="rounded-4 bg-success-subtle border p-3 ms-3 align-self-end">
        <div class="chat-msg-role fw-bold small opacity-75 mb-1 text-uppercase">${roleLabel}</div>
        <pre class="chat-msg-prewrap m-0">${msg.content ?? ""}</pre>
      </div>
    `;
  }
  renderToolCall(tc) {
    return html`
      <details class="bg-warning-subtle mt-2 p-2 border-start border-3 border-warning font-monospace small">
        <summary>
          ${tc.function?.name ?? "unknown"}
        </summary>
        <div class="py-3">

          <div class="mb-3">
            <strong>Args</strong><br/>
            <code>${tc.function?.arguments ?? ""}</code>
          </div>
          ${tc.result !== void 0 ? html`
                <div>
                  <strong>Result</strong><br/>
                  <pre class="m-0">${tc.result}</pre>
                </div>` : nothing}
        </div>
      </details>
    `;
  }
  renderStreamingBubble() {
    return html`
      <div class="rounded-4 bg-white border p-3">
        <div class="chat-msg-role fw-bold small opacity-75 mb-1 text-uppercase">assistant</div>
        <div class="chat-msg-content">
          ${unsafeHTML(this.renderMarkdown(this.streamingBuffer))}
        </div>
      </div>
    `;
  }
  renderThinkingIndicator() {
    return html`
      <div class="p-3 align-self-start">
        <div class="fst-italic">Thinking\u2026</div>
      </div>
    `;
  }
  renderActiveTools() {
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
    const optimisticContent = this.composeOptimisticUserMessage(message, attachments);
    this.messages = [...this.messages, { role: "user", content: optimisticContent }];
    this.inputValue = "";
    this.attachments = [];
    this.loading = true;
    if (this.streamUri) {
      this.sendStreaming(message, attachments).then(() => this.finishSend());
    } else {
      this.sendBlocking(message, attachments).then(() => this.finishSend());
    }
  }
  composeOptimisticUserMessage(message, attachments) {
    if (attachments.length === 0) return message;
    const lines = attachments.map((a) => {
      const ref = a.uid ? `sys_file:${a.uid}` : a.identifier || a.name;
      return `- ${ref} \u2014 ${a.name}`;
    });
    const prefix = message ? message.replace(/\s+$/, "") + "\n\n" : "";
    return prefix + "---\nAngeh\xE4ngte Dateien:\n" + lines.join("\n");
  }
  addAttachment(att) {
    const isDup = this.attachments.some(
      (existing) => att.uid && existing.uid === att.uid || att.identifier && existing.identifier === att.identifier
    );
    if (isDup) return;
    this.attachments = [...this.attachments, att];
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
    if (this.initialPrompt) {
      this.messages = [...this.messages, { role: "user", content: this.initialPrompt }];
    }
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
      case "tool_start": {
        const toolName = data.tool_name;
        const toolCallId = data.tool_call_id;
        const next = new Map(this.activeTools);
        next.set(toolCallId, { toolName });
        this.activeTools = next;
        break;
      }
      case "tool_result": {
        const toolCallId = data.tool_call_id;
        const content = data.content;
        this.messages = this.messages.map((msg) => {
          if (msg.role !== "assistant" || !msg.tool_calls) return msg;
          const match = msg.tool_calls.some((tc) => tc.id === toolCallId);
          if (!match) return msg;
          return {
            ...msg,
            tool_calls: msg.tool_calls.map(
              (tc) => tc.id === toolCallId ? { ...tc, result: content } : tc
            )
          };
        });
        const next = new Map(this.activeTools);
        next.delete(toolCallId);
        this.activeTools = next;
        break;
      }
      case "done":
        this.thinking = false;
        this.isStreaming = false;
        this.activeTools = /* @__PURE__ */ new Map();
        if (Array.isArray(data.messages)) {
          this.messages = this.mergeToolResults(data.messages);
        }
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
], ChatElement.prototype, "activeTools", 2);
__decorateClass([
  state()
], ChatElement.prototype, "attachments", 2);
__decorateClass([
  query("textarea")
], ChatElement.prototype, "inputEl", 2);
__decorateClass([
  query(".chat-upload-trigger")
], ChatElement.prototype, "uploadTriggerEl", 2);
ChatElement = __decorateClass([
  customElement("hn-agent-chat")
], ChatElement);
export {
  ChatElement
};
//# sourceMappingURL=chat-element.js.map
