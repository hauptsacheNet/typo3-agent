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
marked.setOptions({ breaks: true, gfm: true });
let ChatElement = class extends LitElement {
  constructor() {
    super(...arguments);
    this.sendUri = "";
    this.streamUri = "";
    this.autoStart = "";
    this.initialPrompt = "";
    this.initialMessages = [];
    this.messages = [];
    this.inputValue = "";
    this.loading = false;
    this.errorMessage = "";
    this.thinking = false;
    this.streamingBuffer = "";
    this.isStreaming = false;
    this.activeTools = /* @__PURE__ */ new Map();
  }
  // No Shadow DOM — use TYPO3 backend Bootstrap CSS
  createRenderRoot() {
    return this;
  }
  // -- Lifecycle -------------------------------------------------------------
  firstUpdated() {
    this.messages = this.mergeToolResults(this.initialMessages);
    this.scrollToBottom();
    if ((this.autoStart === "1" || this.autoStart === "true") && this.streamUri) {
      this.doAutoStart();
    }
  }
  updated() {
    this.scrollToBottom();
  }
  // -- Render ----------------------------------------------------------------
  render() {
    return html`
      <div class="chat-container" style="display:flex;flex-direction:column;gap:1rem;max-width:900px;">
        <div class="chat-messages" style="border:1px solid #ddd;border-radius:4px;padding:1rem;background:#fafafa;min-height:300px;max-height:60vh;overflow-y:auto;">
          ${this.messages.map((msg) => this.renderMessage(msg))}
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
            ${this.loading ? "Thinking\u2026" : "Send"}
          </button>
        </form>

        ${this.errorMessage ? html`<div class="alert alert-danger">${this.errorMessage}</div>` : nothing}
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
  renderMessage(msg) {
    const role = msg.role || "unknown";
    if (role === "system") return nothing;
    const roleLabel = role === "user" ? "you" : role;
    if (role === "assistant") {
      return html`
        <div class="chat-msg chat-msg-assistant">
          <div class="chat-msg-role">${roleLabel}</div>
          ${msg.content ? html`<div class="chat-msg-content">${unsafeHTML(this.renderMarkdown(msg.content))}</div>` : nothing}
          ${msg.tool_calls?.map((tc) => this.renderToolCall(tc)) ?? nothing}
        </div>
      `;
    }
    if (role === "tool") {
      return html`
        <div class="chat-msg chat-msg-tool">
          <details>
            <summary class="chat-msg-role">tool result</summary>
            <pre>${msg.content ?? ""}</pre>
          </details>
        </div>
      `;
    }
    return html`
      <div class="chat-msg chat-msg-${role}">
        <div class="chat-msg-role">${roleLabel}</div>
        <pre>${msg.content ?? ""}</pre>
      </div>
    `;
  }
  renderToolCall(tc) {
    return html`
      <details class="chat-toolcall">
        <summary>
          <typo3-backend-icon identifier="actions-rocket" size="small"></typo3-backend-icon>
          ${tc.function?.name ?? "unknown"}</summary>
        <div>
            <strong>Args</strong><br/>
            <code>${tc.function?.arguments ?? ""}</code>
        </div>
        ${tc.result !== void 0 ? html`<div><strong>Result</strong><br/>
            <pre>${tc.result}</pre></div>` : nothing}
      </details>
    `;
  }
  renderStreamingBubble() {
    return html`
      <div class="chat-msg chat-msg-assistant chat-msg-streaming">
        <div class="chat-msg-role">assistant</div>
        <div class="chat-msg-content">
          ${unsafeHTML(this.renderMarkdown(this.streamingBuffer))}
        </div>
      </div>
    `;
  }
  renderThinkingIndicator() {
    return html`
      <div class="chat-msg chat-msg-assistant">
        <div class="chat-msg-role">assistant</div>
        <div class="chat-thinking-dots">Thinking\u2026</div>
      </div>
    `;
  }
  renderActiveTools() {
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
  renderMarkdown(text) {
    return DOMPurify.sanitize(marked.parse(text ?? ""));
  }
  // -- Event handlers --------------------------------------------------------
  onSubmit(e) {
    e.preventDefault();
    const message = this.inputValue.trim();
    if (!message) return;
    this.errorMessage = "";
    this.messages = [...this.messages, { role: "user", content: message }];
    this.inputValue = "";
    this.loading = true;
    if (this.streamUri) {
      this.sendStreaming(message).then(() => this.finishSend());
    } else {
      this.sendBlocking(message).then(() => this.finishSend());
    }
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
  async sendBlocking(message) {
    try {
      const formData = new FormData();
      formData.append("message", message);
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
  async sendStreaming(message) {
    try {
      const formData = new FormData();
      formData.append("message", message);
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
  query("textarea")
], ChatElement.prototype, "inputEl", 2);
ChatElement = __decorateClass([
  customElement("hn-agent-chat")
], ChatElement);
export {
  ChatElement
};
//# sourceMappingURL=chat-element.js.map
