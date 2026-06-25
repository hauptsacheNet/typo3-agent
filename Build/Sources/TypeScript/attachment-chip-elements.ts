import {html, LitElement, nothing, type TemplateResult} from 'lit';
import {customElement, property} from 'lit/decorators.js';
import {unsafeHTML} from 'lit/directives/unsafe-html.js';
import type {Attachment} from '@hn/agent/attachment.js';

@customElement('hn-agent-attachment-chips')
export class AttachmentChipElements extends LitElement {

  // No Shadow DOM — use TYPO3 backend Bootstrap CSS
  override createRenderRoot() {
    return this;
  }

  @property({attribute: false}) attachments: Attachment[] = [];
  @property({type: Boolean, reflect: true}) readonly = false;

  override render() {
    if (this.attachments.length === 0) return nothing;

    return html`
      ${this.attachments.map(this.renderAttachmentChip)}
    `;
  }

  private renderAttachmentChip = (att: Attachment, index: number) => {
    const thumbUrl = this.buildThumbnailUrl(att);
    const onThumbError = (e: Event): void => {
      const img = e.target as HTMLImageElement;
      img.style.display = 'none';
      const parent = img.parentElement;
      if (!parent) return;
      parent.style.display = 'none';
      const fallback = parent.nextElementSibling as HTMLElement | null;
      if (fallback) fallback.style.display = '';
    };
    const notReadable = att.readableByLlm === false;
    const warnTitle = notReadable
        ? `LLM kann den Inhalt nicht via ReadFile lesen — nur Metadaten${att.reason ? ` (${att.reason})` : ''}`
        : (att.unresolvable ? 'Datei nicht auflösbar' : '');

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

            ${notReadable
        ? html`<span class="badge badge-warning" title=${warnTitle}>
                  <typo3-backend-icon identifier="actions-exclamation" size="small"/>
                </span>`
        : nothing}

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
  }

  private onRemoveClick(index: number): void {
    this.dispatchEvent(new CustomEvent<{index: number}>('remove', {
      detail: {index},
      bubbles: true, composed: true,
    }));
  }

  private renderFallbackIcon(att: Attachment): TemplateResult {
    if (att.iconHtml) return html`${unsafeHTML(att.iconHtml)}`;
    return html`<typo3-backend-icon identifier="mimetypes-other-other" size="small"></typo3-backend-icon>`;
  }

  private buildThumbnailUrl(att: Attachment): string {
    const base = (window.top as unknown as {TYPO3?: {settings?: {Resource?: {thumbnailUrl?: string}}}})
      ?.TYPO3?.settings?.Resource?.thumbnailUrl;
    if (!base) return '';
    const ref = att.uid ?? att.identifier;
    if (ref === undefined || ref === null || ref === '') return '';
    const url = new URL(base, window.location.origin);
    url.searchParams.set('identifier', String(ref));
    url.searchParams.set('size', 'large');
    url.searchParams.set('keepAspectRatio', 'false');
    return url.toString();
  }
}

declare global {
  interface HTMLElementTagNameMap {
    'hn-agent-attachment-chips': AttachmentChipElements;
  }
}
