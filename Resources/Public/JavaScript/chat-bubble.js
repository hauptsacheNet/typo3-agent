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
import { customElement, property } from "lit/decorators.js";
let ChatBubble = class extends LitElement {
  constructor() {
    super(...arguments);
    this.author = "assistant";
    this.hideHeader = false;
    this.body = nothing;
    this.footer = nothing;
  }
  createRenderRoot() {
    return this;
  }
  willUpdate(changed) {
    this.classList.value = "card card-success card--chat-bubble  align-self-end";
    if (changed.has("author")) {
      this.classList.toggle("align-self-end", this.author === "user");
      this.classList.toggle("card--chat-bubble-right", this.author === "user");
      this.classList.toggle("card--chat-bubble-left", this.author !== "user");
    }
  }
  hasContent(c) {
    return c != null && c !== nothing && c !== "";
  }
  render() {
    const showBody = this.hasContent(this.body);
    const showFooter = this.hasContent(this.footer);
    return html`
                ${showBody ? html`<div class="card-body">${this.body}</div>` : nothing}
                ${showFooter ? html`<div class="card-footer">${this.footer}</div>` : nothing}
        `;
  }
};
__decorateClass([
  property({ reflect: true })
], ChatBubble.prototype, "author", 2);
__decorateClass([
  property({ attribute: "hide-header", type: Boolean })
], ChatBubble.prototype, "hideHeader", 2);
__decorateClass([
  property({ attribute: false })
], ChatBubble.prototype, "body", 2);
__decorateClass([
  property({ attribute: false })
], ChatBubble.prototype, "footer", 2);
ChatBubble = __decorateClass([
  customElement("hn-agent-chat-bubble")
], ChatBubble);
export {
  ChatBubble
};
//# sourceMappingURL=chat-bubble.js.map
