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
import { createRef, ref } from "lit/directives/ref.js";
import DragUploader from "@typo3/backend/drag-uploader.js";
import Modal from "@typo3/backend/modal.js";
import { MessageUtility } from "@typo3/backend/utility/message-utility.js";
import "@hn/agent/attachment-chip-elements.js";
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
    this.defaultUploadFolder = "";
    this.fileBrowserUri = "";
    this.preflightUri = "";
    this.message = "";
    this.attachments = [];
    this.uploadTriggerRef = createRef();
    this.uploadZoneRef = createRef();
    this.elementBrowserListener = (e) => {
      if (!MessageUtility.verifyOrigin(e.origin)) return;
      const data = e.data;
      if (data.actionName !== "typo3:elementBrowser:elementAdded") return;
      if (data.fieldName !== "hn-agent-new-task") return;
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
  get isLive() {
    return this.workspaceId <= 0;
  }
  firstUpdated() {
    const zoneEl = this.uploadZoneRef.value;
    if (zoneEl) {
      new DragUploader(zoneEl);
    }
    this.uploadTriggerRef.value?.addEventListener("uploadSuccess", this.uploadSuccessListener);
  }
  disconnectedCallback() {
    super.disconnectedCallback();
    this.uploadTriggerRef.value?.removeEventListener("uploadSuccess", this.uploadSuccessListener);
    window.removeEventListener("message", this.elementBrowserListener);
  }
  onInput(e) {
    this.message = e.target.value;
  }
  onKeydown(e) {
    if (this.isLive) return;
    if (e.key === "Enter" && !e.shiftKey && this.canSubmit()) {
      e.preventDefault();
      this.renderRoot.querySelector("form")?.submit();
    }
  }
  canSubmit() {
    return this.message.trim() !== "" || this.attachments.length > 0;
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
  serializeAttachments() {
    if (this.attachments.length === 0) return "";
    return JSON.stringify(this.attachments.map((a) => ({
      uid: a.uid,
      identifier: a.identifier,
      name: a.name
    })));
  }
  render() {
    if (!this.actionUri) {
      return nothing;
    }
    if (this.isLive) {
      return this.renderLiveCallout();
    }
    const uploadEnabled = !!this.defaultUploadFolder;
    const pickEnabled = !!this.fileBrowserUri;
    return html`
      <div>
        <div
            class="chat-upload-zone"
            ${ref(this.uploadZoneRef)}
            data-target-folder=${this.defaultUploadFolder}
            data-max-file-size="0"
            data-dropzone-target=".chat-upload-anchor"
            data-dropzone-trigger=".chat-upload-trigger"
            data-default-action="rename">
          <form action=${this.actionUri} method="post"
                class="task-form">
            <input type="hidden" name="table" .value=${this.table}>
            <input type="hidden" name="uid" .value=${String(this.uid)}>
            <input type="hidden" name="return_url" .value=${this.returnUrl}>
            <input type="hidden" name="attachments" .value=${this.serializeAttachments()}>

            <textarea
                class="message-control"
                name="message"
                placeholder=${this.placeholder}
                .value=${this.message}
                @input=${this.onInput}
                @keydown=${this.onKeydown}
                style="outline: none; field-sizing: content; resize: none;"
            ></textarea>

            ${this.attachments.length > 0 ? html`
                  <hn-agent-attachment-chips
                      .attachments=${this.attachments}
                      @remove=${(e) => this.removeAttachment(e.detail.index)}>
                  </hn-agent-attachment-chips>
                ` : nothing}

            <div class="chat-upload-anchor" style="display:none"></div>

            <div class="w-100 d-flex flex-row">
              ${this.renderAttachmentsBar(uploadEnabled, pickEnabled)}
              <div class="ms-auto">
                <button
                    type="submit"
                    class="btn btn-sm"
                    ?disabled=${!this.canSubmit()}
                    title=${TYPO3?.lang?.["button.submit.title"] ?? "Start a new AI agent task (Enter)"}>
                  <typo3-backend-icon
                      identifier="actions-arrow-down-start-alt"
                      size="small"/>
                </button>
              </div>
            </div>
          </form>
        </div>
      </div>
    `;
  }
  renderAttachmentsBar(uploadEnabled, pickEnabled) {
    return html`
      <div>
        <button type="button"
                class="chat-upload-trigger btn btn-sm btn-default"
                ${ref(this.uploadTriggerRef)}
                ?disabled=${!uploadEnabled}
                title=${uploadEnabled ? "Datei hochladen" : "Kein Upload-Ordner verf\xFCgbar"}>
          <typo3-backend-icon identifier="actions-upload" size="small"/>
          Hochladen
        </button>
        <button type="button"
                class="btn btn-sm btn-default"
                ?disabled=${!pickEnabled}
                @click=${this.onPickClick}>
          <typo3-backend-icon identifier="actions-folder" size="small"/>
          Auswählen
        </button>
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
  property({ attribute: "default-upload-folder" })
], NewTaskElement.prototype, "defaultUploadFolder", 2);
__decorateClass([
  property({ attribute: "file-browser-uri" })
], NewTaskElement.prototype, "fileBrowserUri", 2);
__decorateClass([
  property({ attribute: "preflight-uri" })
], NewTaskElement.prototype, "preflightUri", 2);
__decorateClass([
  state()
], NewTaskElement.prototype, "message", 2);
__decorateClass([
  state()
], NewTaskElement.prototype, "attachments", 2);
NewTaskElement = __decorateClass([
  customElement("hn-agent-new-task")
], NewTaskElement);
export {
  NewTaskElement
};
//# sourceMappingURL=new-task-element.js.map
