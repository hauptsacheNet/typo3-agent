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
import { createRef, ref } from "lit/directives/ref.js";
import DragUploader from "@typo3/backend/drag-uploader.js";
import Modal from "@typo3/backend/modal.js";
import { MessageUtility } from "@typo3/backend/utility/message-utility.js";
import "@hn/agent/attachment-chip-elements.js";
let MessageComposerElement = class extends LitElement {
  constructor() {
    super(...arguments);
    this.placeholder = "";
    this.fieldName = "hn-agent-message-composer";
    this.disabled = false;
    this.loading = false;
    this.submitTitle = "";
    this.message = "";
    this.attachments = [];
    this.uploadTriggerRef = createRef();
    this.uploadZoneRef = createRef();
    this.elementBrowserListener = (e) => {
      if (!MessageUtility.verifyOrigin(e.origin)) return;
      const data = e.data;
      if (data.actionName !== "typo3:elementBrowser:elementAdded") return;
      if (data.fieldName !== this.fieldName) return;
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
  createRenderRoot() {
    return this;
  }
  get preflightUri() {
    const ajaxUrls = TYPO3?.settings?.ajaxUrls;
    return ajaxUrls?.["typo3_agent_tasks_attachment_preflight"] ?? "";
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
  focus() {
    this.textareaEl?.focus();
  }
  onInput(e) {
    this.message = e.target.value;
  }
  onKeydown(e) {
    if (this.disabled || this.loading) return;
    if (e.key === "Enter" && !e.shiftKey && this.canSubmit()) {
      e.preventDefault();
      this.formEl?.requestSubmit();
    }
  }
  canSubmit() {
    return !this.disabled && !this.loading && (this.message.trim() !== "" || this.attachments.length > 0);
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
  get agentSettings() {
    const settings = TYPO3?.settings?.Agent;
    return settings ?? {};
  }
  get defaultUploadFolder() {
    return this.agentSettings.defaultUploadFolder ?? "";
  }
  get fileBrowserUri() {
    const baseUri = this.agentSettings.elementBrowserUrl;
    if (!baseUri) return "";
    const bparams = `${this.fieldName}|||*|`;
    const separator = baseUri.includes("?") ? "&" : "?";
    return `${baseUri}${separator}mode=file&bparams=${encodeURIComponent(bparams)}`;
  }
  onPickClick() {
    const uri = this.fileBrowserUri;
    if (!uri || this.disabled) return;
    window.addEventListener("message", this.elementBrowserListener);
    const modal = Modal.advanced({
      type: Modal.types.iframe,
      content: uri,
      size: Modal.sizes.large
    });
    modal.addEventListener("typo3-modal-hide", () => {
      window.removeEventListener("message", this.elementBrowserListener);
    });
  }
  onSubmit(e) {
    e.preventDefault();
    if (!this.canSubmit()) return;
    const detail = {
      message: this.message.trim(),
      attachments: this.attachments
    };
    this.message = "";
    this.attachments = [];
    this.dispatchEvent(new CustomEvent("submit", {
      detail,
      bubbles: true,
      composed: true
    }));
  }
  onStopClick() {
    this.dispatchEvent(new CustomEvent("stop", { bubbles: true, composed: true }));
  }
  render() {
    const uploadEnabled = !!this.defaultUploadFolder;
    const pickEnabled = this.fileBrowserUri !== "";
    return html`
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
              placeholder=${this.placeholder}
              ?disabled=${this.disabled || this.loading}
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
              ${this.loading ? this.renderStopButton() : this.renderDefaultSubmit()}
            </div>
          </div>
        </form>
      </div>
    `;
  }
  renderDefaultSubmit() {
    const title = this.submitTitle || TYPO3?.lang?.["button.submit.title"] || "Send (Enter)";
    return html`
      <button
          type="submit"
          class="btn btn-sm"
          ?disabled=${!this.canSubmit()}
          title=${title}>
        <typo3-backend-icon
            identifier="actions-arrow-down-start-alt"
            size="small"/>
      </button>
    `;
  }
  renderStopButton() {
    return html`
      <button type="button"
              class="btn btn-sm"
              title="Antwort abbrechen"
              ?disabled=${this.disabled}
              @click=${this.onStopClick}>
        <typo3-backend-icon identifier="actions-close" size="small"/>
      </button>
    `;
  }
  renderAttachmentsBar(uploadEnabled, pickEnabled) {
    return html`
      <div>
        <button type="button"
                class="chat-upload-trigger btn btn-sm btn-default"
                ${ref(this.uploadTriggerRef)}
                ?disabled=${this.disabled || this.loading || !uploadEnabled}
                title=${uploadEnabled ? "Datei hochladen" : "Kein Upload-Ordner verf\xFCgbar"}>
          <typo3-backend-icon identifier="actions-upload" size="small"/>
          Hochladen
        </button>
        <button type="button"
                class="btn btn-sm btn-default"
                ?disabled=${this.disabled || this.loading || !pickEnabled}
                @click=${this.onPickClick}>
          <typo3-backend-icon identifier="actions-folder" size="small"/>
          Auswählen
        </button>
      </div>
    `;
  }
};
__decorateClass([
  property()
], MessageComposerElement.prototype, "placeholder", 2);
__decorateClass([
  property({ attribute: "field-name" })
], MessageComposerElement.prototype, "fieldName", 2);
__decorateClass([
  property({ type: Boolean, reflect: true })
], MessageComposerElement.prototype, "disabled", 2);
__decorateClass([
  property({ type: Boolean, reflect: true })
], MessageComposerElement.prototype, "loading", 2);
__decorateClass([
  property({ attribute: "submit-title" })
], MessageComposerElement.prototype, "submitTitle", 2);
__decorateClass([
  state()
], MessageComposerElement.prototype, "message", 2);
__decorateClass([
  state()
], MessageComposerElement.prototype, "attachments", 2);
__decorateClass([
  query("form")
], MessageComposerElement.prototype, "formEl", 2);
__decorateClass([
  query("textarea")
], MessageComposerElement.prototype, "textareaEl", 2);
MessageComposerElement = __decorateClass([
  customElement("hn-agent-message-composer")
], MessageComposerElement);
export {
  MessageComposerElement
};
//# sourceMappingURL=message-composer.js.map
