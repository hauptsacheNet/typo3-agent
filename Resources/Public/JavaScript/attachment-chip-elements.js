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
import { unsafeHTML } from "lit/directives/unsafe-html.js";
let AttachmentChipElements = class extends LitElement {
  constructor() {
    super(...arguments);
    this.attachments = [];
    this.readonly = false;
    this.renderAttachmentChip = (att, index) => {
      const thumbUrl = this.buildThumbnailUrl(att);
      const onThumbError = (e) => {
        const img = e.target;
        img.style.display = "none";
        const parent = img.parentElement;
        if (!parent) return;
        parent.style.display = "none";
        const fallback = parent.nextElementSibling;
        if (fallback) fallback.style.display = "";
      };
      const notReadable = att.readableByLlm === false;
      const warnTitle = notReadable ? `LLM kann den Inhalt nicht via ReadFile lesen \u2014 nur Metadaten${att.reason ? ` (${att.reason})` : ""}` : att.unresolvable ? "Datei nicht aufl\xF6sbar" : "";
      return html`
      <div class="panel panel-default panel-condensed">
        <div class="panel-heading" title=${warnTitle}>
          <div class="panel-heading-row">

            ${thumbUrl ? html`
              <div class="panel-thumbnail">
                <img src=${thumbUrl} alt="" @error=${onThumbError}/>
              </div>
              <div class="panel-icon" style="display:none">
                ${this.renderFallbackIcon(att)}
              </div>
            ` : html`
              <div class="panel-icon">
                ${this.renderFallbackIcon(att)}
              </div>
            `}

            ${notReadable ? html`<span class="badge badge-warning" title=${warnTitle}>
                  <typo3-backend-icon identifier="actions-exclamation" size="small"/>
                </span>` : nothing}

            <div class="panel-title">
              ${att.name}
            </div>

            ${this.readonly ? nothing : html`
              <div class="panel-actions">
                <button type="button"
                        class="btn btn-sm btn-default"
                        title="Entfernen"
                        @click=${() => this.onRemoveClick(index)}>
                  <typo3-backend-icon identifier="actions-edit-delete" size="small"/>
                </button>
              </div>
            `}
          </div>
        </div>
      </div>
    `;
    };
  }
  // No Shadow DOM — use TYPO3 backend Bootstrap CSS
  createRenderRoot() {
    return this;
  }
  render() {
    if (this.attachments.length === 0) return nothing;
    return html`
      ${this.attachments.map(this.renderAttachmentChip)}
    `;
  }
  onRemoveClick(index) {
    this.dispatchEvent(new CustomEvent("remove", {
      detail: { index },
      bubbles: true,
      composed: true
    }));
  }
  renderFallbackIcon(att) {
    if (att.iconHtml) return html`${unsafeHTML(att.iconHtml)}`;
    return html`<typo3-backend-icon identifier="mimetypes-other-other" size="small"></typo3-backend-icon>`;
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
};
__decorateClass([
  property({ attribute: false })
], AttachmentChipElements.prototype, "attachments", 2);
__decorateClass([
  property({ type: Boolean, reflect: true })
], AttachmentChipElements.prototype, "readonly", 2);
AttachmentChipElements = __decorateClass([
  customElement("hn-agent-attachment-chips")
], AttachmentChipElements);
export {
  AttachmentChipElements
};
//# sourceMappingURL=attachment-chip-elements.js.map
