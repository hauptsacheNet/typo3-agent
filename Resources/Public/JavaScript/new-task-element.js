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
import "@hn/agent/message-composer.js";
let NewTaskElement = class extends LitElement {
  constructor() {
    super(...arguments);
    this.actionUri = "";
    this.table = "";
    this.uid = 0;
    this.placeholder = "";
    this.returnUrl = "";
    this.workspaceId = 0;
    this.workspaceTitle = "";
    this.submitting = false;
    this.errorMessage = "";
  }
  // No Shadow DOM — use TYPO3 backend Bootstrap CSS
  createRenderRoot() {
    return this;
  }
  get isLive() {
    return this.workspaceId <= 0;
  }
  async onComposerSubmit(e) {
    const { message, attachments } = e.detail;
    this.submitting = true;
    this.errorMessage = "";
    const formData = new FormData();
    formData.append("message", message);
    formData.append("table", this.table);
    formData.append("uid", String(this.uid));
    formData.append("return_url", this.returnUrl);
    formData.append("attachments", JSON.stringify(attachments.map((a) => ({
      uid: a.uid,
      identifier: a.identifier,
      name: a.name
    }))));
    try {
      const response = await fetch(this.actionUri, {
        method: "POST",
        body: formData,
        redirect: "follow"
      });
      if (!response.ok) {
        this.errorMessage = `Request failed (${response.status})`;
        this.submitting = false;
        return;
      }
      window.location.href = response.url;
    } catch (err) {
      this.errorMessage = err.message || String(err);
      this.submitting = false;
    }
  }
  render() {
    if (!this.actionUri) {
      return nothing;
    }
    if (this.isLive) {
      return this.renderLiveCallout();
    }
    return html`
      <div>
        ${this.errorMessage ? html`<div class="alert alert-danger">${this.errorMessage}</div>` : nothing}
        <hn-agent-message-composer
            ?disabled=${this.submitting}
            placeholder=${this.placeholder}
            field-name="hn-agent-new-task"
            @submit=${this.onComposerSubmit}>
        </hn-agent-message-composer>
      </div>
    `;
  }
  renderLiveCallout() {
    const text = TYPO3?.lang?.["workspace.callout.selectWorkspace"] ?? "Please switch to a workspace before starting a task.";
    return html`
      <div class="callout callout-info">
        <div class="callout-icon"><span class="icon-emphasized">
          <typo3-backend-icon
              identifier="actions-info"
              size="small"/>
        </div>
        <div class="callout-content">
          <div class="callout-body">${text}</div>
        </div>
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
  property({ attribute: "workspace-id", type: Number })
], NewTaskElement.prototype, "workspaceId", 2);
__decorateClass([
  property({ attribute: "workspace-title" })
], NewTaskElement.prototype, "workspaceTitle", 2);
__decorateClass([
  state()
], NewTaskElement.prototype, "submitting", 2);
__decorateClass([
  state()
], NewTaskElement.prototype, "errorMessage", 2);
NewTaskElement = __decorateClass([
  customElement("hn-agent-new-task")
], NewTaskElement);
export {
  NewTaskElement
};
//# sourceMappingURL=new-task-element.js.map
