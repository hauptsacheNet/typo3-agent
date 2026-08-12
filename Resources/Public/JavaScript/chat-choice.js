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
import { buildThumbnailUrl } from "./thumbnail.js";
let ChatChoice = class extends LitElement {
  constructor() {
    super(...arguments);
    this.question = "";
    this.options = [];
    this.multiselect = false;
    this.disabled = false;
    this.selected = /* @__PURE__ */ new Set();
  }
  // No Shadow DOM — reuse TYPO3 backend Bootstrap CSS (matches the other
  // agent chat components).
  createRenderRoot() {
    return this;
  }
  /** Image mode: at least one option references an image (sys_file uid or direct URL). */
  get isImageMode() {
    return this.options.some((o) => typeof o.uid === "number" || typeof o.url === "string");
  }
  toggle(index) {
    if (this.disabled) return;
    if (!this.multiselect) {
      this.submit([index]);
      return;
    }
    const next = new Set(this.selected);
    if (next.has(index)) {
      next.delete(index);
    } else {
      next.add(index);
    }
    this.selected = next;
  }
  onSubmitClick() {
    if (this.disabled || this.selected.size === 0) return;
    this.submit([...this.selected].sort((a, b) => a - b));
  }
  submit(indices) {
    const chosen = indices.map((i) => this.options[i]).filter((o) => !!o && o.label !== "");
    if (chosen.length === 0) return;
    const message = this.isImageMode ? "Ausgew\xE4hlte Bilder: " + chosen.map((o) => o.uid ? `${o.label} (sys_file:${o.uid})` : o.url ? `${o.label} (${o.url})` : o.label).join(", ") : chosen.map((o) => o.label).join(", ");
    this.dispatchEvent(new CustomEvent("choice-submit", {
      detail: { message },
      bubbles: true,
      composed: true
    }));
  }
  render() {
    const imageMode = this.isImageMode;
    return html`
            <div class="chat-choice card card--chat-choice ${imageMode ? "chat-choice--images" : ""}">
                ${this.question ? html`<div class="chat-choice-question card-header">${this.question}</div>` : nothing}
                ${imageMode ? html`<div class="chat-choice-grid">
                        ${this.options.map((opt, i) => this.renderImageOption(opt, i))}
                    </div>` : html`<div class="chat-choice-options list-group list-group-flush">
                        ${this.options.map((opt, i) => this.renderOption(opt, i))}
                    </div>`}
                ${this.multiselect ? html`
                        <div class="chat-choice-actions card-footer">
                            <button type="button"
                                    class="btn btn-sm btn-primary"
                                    ?disabled=${this.disabled || this.selected.size === 0}
                                    @click=${this.onSubmitClick}>
                                <typo3-backend-icon identifier="actions-arrow-down-start-alt" size="small"></typo3-backend-icon>
                                Auswahl senden
                            </button>
                        </div>` : nothing}
            </div>
        `;
  }
  renderOption(opt, index) {
    const isSelected = this.selected.has(index);
    const markerIcon = this.multiselect ? isSelected ? "actions-check-square" : "actions-square" : isSelected ? "actions-check-circle" : "actions-circle";
    return html`
            <button type="button"
                    class="chat-choice-option list-group-item list-group-item-action ${isSelected ? "active" : ""}"
                    ?disabled=${this.disabled}
                    aria-pressed=${isSelected ? "true" : "false"}
                    @click=${() => this.toggle(index)}>
                <span class="chat-choice-marker">
                    <typo3-backend-icon identifier=${markerIcon} size="small"></typo3-backend-icon>
                </span>
                <span class="chat-choice-body">
                    <span class="chat-choice-label">${opt.label}</span>
                    ${opt.description ? html`<span class="chat-choice-description text-body-secondary">${opt.description}</span>` : nothing}
                </span>
            </button>
        `;
  }
  renderImageOption(opt, index) {
    const isSelected = this.selected.has(index);
    const thumbUrl = opt.url ?? buildThumbnailUrl(opt.uid);
    const onThumbError = (e) => {
      const img = e.target;
      img.style.display = "none";
      const fallback = img.parentElement?.querySelector(".chat-choice-tile-fallback");
      if (fallback) fallback.style.display = "";
    };
    return html`
            <button type="button"
                    class="chat-choice-tile ${isSelected ? "active" : ""}"
                    ?disabled=${this.disabled}
                    aria-pressed=${isSelected ? "true" : "false"}
                    title=${opt.description ?? opt.label}
                    @click=${() => this.toggle(index)}>
                <span class="chat-choice-tile-thumb">
                    ${thumbUrl ? html`<img src=${thumbUrl} alt=${opt.label} @error=${onThumbError}/>` : nothing}
                    <span class="chat-choice-tile-fallback" style=${thumbUrl ? "display:none" : ""}>
                        <typo3-backend-icon identifier="mimetypes-media-image" size="large"></typo3-backend-icon>
                    </span>
                    <span class="chat-choice-tile-check">
                        <typo3-backend-icon identifier=${isSelected ? "actions-check-circle" : "actions-circle"} size="small"></typo3-backend-icon>
                    </span>
                </span>
                <span class="chat-choice-tile-label">${opt.label}</span>
            </button>
        `;
  }
};
__decorateClass([
  property({ attribute: false })
], ChatChoice.prototype, "question", 2);
__decorateClass([
  property({ attribute: false })
], ChatChoice.prototype, "options", 2);
__decorateClass([
  property({ type: Boolean })
], ChatChoice.prototype, "multiselect", 2);
__decorateClass([
  property({ type: Boolean })
], ChatChoice.prototype, "disabled", 2);
__decorateClass([
  state()
], ChatChoice.prototype, "selected", 2);
ChatChoice = __decorateClass([
  customElement("hn-agent-choice")
], ChatChoice);
export {
  ChatChoice
};
//# sourceMappingURL=chat-choice.js.map
