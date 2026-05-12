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
let NewTaskElement = class extends LitElement {
  constructor() {
    super(...arguments);
    this.actionUri = "";
    this.table = "";
    this.uid = 0;
    this.placeholder = "";
    this.returnUrl = "";
    this.message = "";
  }
  // No Shadow DOM — use TYPO3 backend Bootstrap CSS
  createRenderRoot() {
    return this;
  }
  connectedCallback() {
    super.connectedCallback();
    if (!this.returnUrl) {
      this.returnUrl = window.location.href;
    }
  }
  onInput(e) {
    this.message = e.target.value;
  }
  onKeydown(e) {
    if (e.key === "Enter" && (e.ctrlKey || e.metaKey) && this.message.trim()) {
      e.preventDefault();
      this.renderRoot.querySelector("form")?.submit();
    }
  }
  render() {
    if (!this.actionUri) {
      return nothing;
    }
    return html`
      <div style="margin-bottom: calc(var(--typo3-spacing) * 2);">
        <form action=${this.actionUri} method="post">
          <input type="hidden" name="table" .value=${this.table}>
          <input type="hidden" name="uid" .value=${String(this.uid)}>
          <input type="hidden" name="return_url" .value=${this.returnUrl}>
          <div class="">
            <div class="position-relative">
              <textarea
                  class="d-block w-100 rounded-5 border p-3 bg-white "
                  name="message"
                  rows="1"
                  placeholder=${this.placeholder}
                  .value=${this.message}
                  @input=${this.onInput}
                  @keydown=${this.onKeydown}
                  required
                  style="outline: none;field-sizing: content;resize: none;"
              ></textarea>
              <div class="position-absolute bottom-0 end-0 p-2">
                <button
                    class="btn"
                    type="submit"
                    ?disabled=${!this.message.trim()}
                    title=${TYPO3?.lang?.["button.submit.title"] ?? "Start a new AI agent task (Ctrl+Enter)"}
                >
                  <typo3-backend-icon
                      identifier="actions-arrow-down-start-alt"
                      size="small"/>
                </button>
              </div>
            </div>
          </div>
        </form>
      </div>
    `;
  }
};
__decorateClass([
  property({ attribute: "action-uri" })
], NewTaskElement.prototype, "actionUri", 2);
__decorateClass([
  property()
], NewTaskElement.prototype, "table", 2);
__decorateClass([
  property({ type: Number })
], NewTaskElement.prototype, "uid", 2);
__decorateClass([
  property()
], NewTaskElement.prototype, "placeholder", 2);
__decorateClass([
  property({ attribute: "return-url" })
], NewTaskElement.prototype, "returnUrl", 2);
__decorateClass([
  state()
], NewTaskElement.prototype, "message", 2);
NewTaskElement = __decorateClass([
  customElement("hn-agent-new-task")
], NewTaskElement);
function autoInsert() {
  const settings = TYPO3?.settings?.Agent;
  if (!settings?.newTaskUri) {
    return;
  }
  if (document.querySelector("hn-agent-new-task")) {
    return;
  }
  const el = document.createElement("hn-agent-new-task");
  el.setAttribute("action-uri", settings.newTaskUri);
  el.setAttribute("table", settings.table ?? "");
  el.setAttribute("uid", settings.uid ?? "0");
  el.setAttribute("placeholder", settings.placeholder ?? "");
  const target = document.querySelector(".t3js-module-body");
  if (target) {
    target.insertBefore(el, target.firstChild);
  }
}
if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", autoInsert);
} else {
  autoInsert();
}
export {
  NewTaskElement
};
//# sourceMappingURL=new-task-element.js.map
