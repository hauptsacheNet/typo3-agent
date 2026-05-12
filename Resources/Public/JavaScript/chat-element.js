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
      <div class="chat-container d-flex flex-column message-fade" style="max-width:900px;">
        <div class="chat-messages d-flex flex-column gap-3 overflow-auto mx-3 pb-3"
             style="min-height:300px;max-height:60vh;">
          ${this.messages.map((msg) => this.renderMessage(msg))}
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

        ${this.errorMessage ? html`
              <div class="alert alert-danger">${this.errorMessage}</div>` : nothing}
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
  renderMessage(msg) {
    const role = msg.role || "unknown";
    if (role === "system") return nothing;
    const roleLabel = role === "user" ? "you" : role;
    if (role === "assistant") {
      return html`
        <div class="rounded-4 bg-white border p-3">
          <div class="chat-msg-role fw-bold small opacity-75 mb-1 text-uppercase">${roleLabel}</div>
          ${msg.content ? html`<div class="chat-msg-content">${unsafeHTML(this.renderMarkdown(msg.content))}</div>` : nothing}
          ${msg.tool_calls?.map((tc) => this.renderToolCall(tc)) ?? nothing}
        </div>
      `;
    }
    return html`
      <div class="rounded-4 bg-success-subtle border p-3 align-self-end">
        <div class="chat-msg-role fw-bold small opacity-75 mb-1 text-uppercase">${roleLabel}</div>
        <pre class="m-0">${msg.content ?? ""}</pre>
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
      this.thinking = true;
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
