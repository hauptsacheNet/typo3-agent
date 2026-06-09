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
import "@typo3/backend/drag-uploader.js";
import Modal from "@typo3/backend/modal.js";
import { MessageUtility } from "@typo3/backend/utility/message-utility.js";
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
    this.uploadTriggerEl?.addEventListener("uploadSuccess", this.uploadSuccessListener);
  }
  disconnectedCallback() {
    super.disconnectedCallback();
    this.uploadTriggerEl?.removeEventListener("uploadSuccess", this.uploadSuccessListener);
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
      <div style="margin-bottom: calc(var(--typo3-spacing) * 2);">
        <div
            class="t3js-drag-uploader chat-upload-zone"
            data-target-folder=${this.defaultUploadFolder}
            data-max-file-size="0"
            data-dropzone-target=".chat-upload-anchor"
            data-dropzone-trigger=".chat-upload-trigger"
            data-default-action="rename">
          <form action=${this.actionUri} method="post"
                class="position-relative rounded-4 border bg-white overflow-hidden d-flex flex-column gap-3 p-3">
            <input type="hidden" name="table" .value=${this.table}>
            <input type="hidden" name="uid" .value=${String(this.uid)}>
            <input type="hidden" name="return_url" .value=${this.returnUrl}>
            <input type="hidden" name="attachments" .value=${this.serializeAttachments()}>

            <textarea
                class="d-block w-100 border-0 bg-white"
                name="message"
                rows="2"
                placeholder=${this.placeholder}
                .value=${this.message}
                @input=${this.onInput}
                @keydown=${this.onKeydown}
                style="outline: none; field-sizing: content; resize: none;"
            ></textarea>

            ${this.attachments.length > 0 ? html`<div class="chat-attachments d-flex flex-wrap gap-2">
                  ${this.attachments.map((a, i) => this.renderAttachmentChip(a, () => this.removeAttachment(i)))}
                </div>` : nothing}

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
                  @click=${onRemove}>×</button>` : nothing}
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
  renderLiveCallout() {
    const text = TYPO3?.lang?.["workspace.callout.selectWorkspace"] ?? "Please switch to a workspace before starting a task.";
    return html`
      <div class="alert alert-warning" style="margin-bottom: calc(var(--typo3-spacing) * 2);">
        ${text}
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
__decorateClass([
  query(".chat-upload-trigger")
], NewTaskElement.prototype, "uploadTriggerEl", 2);
NewTaskElement = __decorateClass([
  customElement("hn-agent-new-task")
], NewTaskElement);
export {
  NewTaskElement
};
//# sourceMappingURL=new-task-element.js.map
