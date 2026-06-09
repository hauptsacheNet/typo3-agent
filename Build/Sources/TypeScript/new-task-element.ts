import {html, LitElement, nothing, type TemplateResult} from 'lit';
import {customElement, property, query, state} from 'lit/decorators.js';
import {unsafeHTML} from 'lit/directives/unsafe-html.js';
import '@typo3/backend/drag-uploader.js';
import Modal from '@typo3/backend/modal.js';
import {MessageUtility} from '@typo3/backend/utility/message-utility.js';

interface Attachment {
  uid?: number;
  identifier?: string;
  name: string;
  mime_type?: string;
  iconHtml?: string;
  unresolvable?: boolean;
  readableByLlm?: boolean;
  reason?: string;
  size?: number;
}

@customElement('hn-agent-new-task')
export class NewTaskElement extends LitElement {

  // No Shadow DOM — use TYPO3 backend Bootstrap CSS
  override createRenderRoot() {
    return this;
  }

  @property({attribute: 'action-uri'}) actionUri = '';
  @property() table = '';
  @property({type: Number}) uid = 0;
  @property() placeholder = '';
  @property({attribute: 'return-url'}) returnUrl = '';
  @property({attribute: 'workspace-id', type: Number}) workspaceId = 0;
  @property({attribute: 'workspace-title'}) workspaceTitle = '';
  @property({attribute: 'default-upload-folder'}) defaultUploadFolder = '';
  @property({attribute: 'file-browser-uri'}) fileBrowserUri = '';
  @property({attribute: 'preflight-uri'}) preflightUri = '';

  @state() private message = '';
  @state() private attachments: Attachment[] = [];

  @query('.chat-upload-trigger') private uploadTriggerEl?: HTMLElement;

  private get isLive(): boolean {
    // workspaceId === 0 means Live workspace (or attribute not set at all).
    return this.workspaceId <= 0;
  }

  private elementBrowserListener = (e: MessageEvent): void => {
    if (!MessageUtility.verifyOrigin(e.origin)) return;
    const data = e.data as {actionName?: string; fieldName?: string; value?: string; label?: string};
    if (data.actionName !== 'typo3:elementBrowser:elementAdded') return;
    if (data.fieldName !== 'hn-agent-new-task') return;
    const raw = (data.value ?? '').toString();
    if (!raw) return;
    const numericUid = /^\d+$/.test(raw) ? parseInt(raw, 10) : 0;
    if (numericUid > 0) {
      this.addAttachment({uid: numericUid, name: data.label || `sys_file:${numericUid}`});
    } else {
      this.addAttachment({identifier: raw, name: data.label || raw});
    }
  };

  private uploadSuccessListener = (e: Event): void => {
    const detail = (e as CustomEvent).detail as unknown as [unknown, {upload?: Array<{uid: number; name: string; id: string; icon?: string}>}];
    const payload = Array.isArray(detail) ? detail[1] : undefined;
    const file = payload?.upload?.[0];
    if (!file) return;
    this.addAttachment({uid: file.uid, identifier: file.id, name: file.name, iconHtml: file.icon});
  };

  override firstUpdated(): void {
    // DragUploader auto-inits via MutationObserver on `.t3js-drag-uploader`.
    this.uploadTriggerEl?.addEventListener('uploadSuccess', this.uploadSuccessListener);
  }

  override disconnectedCallback(): void {
    super.disconnectedCallback();
    this.uploadTriggerEl?.removeEventListener('uploadSuccess', this.uploadSuccessListener);
    window.removeEventListener('message', this.elementBrowserListener);
  }

  private onInput(e: Event): void {
    this.message = (e.target as HTMLTextAreaElement).value;
  }

  private onKeydown(e: KeyboardEvent): void {
    if (this.isLive) return;
    if (e.key === 'Enter' && !e.shiftKey && this.canSubmit()) {
      e.preventDefault();
      this.renderRoot.querySelector('form')?.submit();
    }
  }

  private canSubmit(): boolean {
    return this.message.trim() !== '' || this.attachments.length > 0;
  }

  private addAttachment(att: Attachment): void {
    this.attachments = [...this.attachments, att];
    if (this.preflightUri && (att.uid !== undefined || att.identifier)) {
      void this.preflightAttachment(att);
    }
  }

  private async preflightAttachment(att: Attachment): Promise<void> {
    try {
      const url = new URL(this.preflightUri, window.location.origin);
      if (att.uid !== undefined) url.searchParams.set('uid', String(att.uid));
      if (att.identifier) url.searchParams.set('identifier', att.identifier);
      const response = await fetch(url.toString(), {headers: {'Accept': 'application/json'}});
      if (!response.ok) return;
      const info = await response.json() as {
        uid?: number; identifier?: string; name?: string;
        mime?: string; size?: number;
        readableByLlm: boolean; reason?: string | null;
      };
      this.attachments = this.attachments.map(a => {
        const sameUid = info.uid !== undefined && a.uid === info.uid;
        const sameIdent = !!info.identifier && a.identifier === info.identifier;
        if (!sameUid && !sameIdent) return a;
        return {
          ...a,
          mime_type: info.mime || a.mime_type,
          size: typeof info.size === 'number' ? info.size : a.size,
          readableByLlm: info.readableByLlm,
          reason: info.reason ?? undefined,
        };
      });
    } catch {
      // silent — chip just stays without status indicator
    }
  }

  private removeAttachment(index: number): void {
    this.attachments = this.attachments.filter((_, i) => i !== index);
  }

  private onPickClick(): void {
    if (!this.fileBrowserUri) return;
    window.addEventListener('message', this.elementBrowserListener);
    const modal = Modal.advanced({
      type: Modal.types.iframe,
      content: this.fileBrowserUri,
      size: Modal.sizes.large,
    });
    modal.addEventListener('typo3-modal-hide', () => {
      window.removeEventListener('message', this.elementBrowserListener);
    });
  }

  private serializeAttachments(): string {
    if (this.attachments.length === 0) return '';
    return JSON.stringify(this.attachments.map(a => ({
      uid: a.uid,
      identifier: a.identifier,
      name: a.name,
    })));
  }

  override render() {
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

            ${this.attachments.length > 0
              ? html`<div class="chat-attachments d-flex flex-wrap gap-2">
                  ${this.attachments.map((a, i) => this.renderAttachmentChip(a, () => this.removeAttachment(i)))}
                </div>`
              : nothing}

            <div class="chat-upload-anchor" style="display:none"></div>

            <div class="w-100 d-flex flex-row">
              ${this.renderAttachmentsBar(uploadEnabled, pickEnabled)}
              <div class="ms-auto">
                <button
                    type="submit"
                    class="btn btn-sm"
                    ?disabled=${!this.canSubmit()}
                    title=${TYPO3?.lang?.['button.submit.title'] ?? 'Start a new AI agent task (Enter)'}>
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

  private renderAttachmentsBar(uploadEnabled: boolean, pickEnabled: boolean): TemplateResult {
    return html`
      <div>
        <button type="button"
                class="chat-upload-trigger btn btn-sm btn-default"
                ?disabled=${!uploadEnabled}
                title=${uploadEnabled ? 'Datei hochladen' : 'Kein Upload-Ordner verfügbar'}>
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

  private renderAttachmentChip(att: Attachment, onRemove?: () => void): TemplateResult {
    const thumbUrl = this.buildThumbnailUrl(att);
    const onThumbError = (e: Event): void => {
      const img = e.target as HTMLImageElement;
      img.style.display = 'none';
      const fallback = img.nextElementSibling as HTMLElement | null;
      if (fallback) fallback.style.display = '';
    };
    const notReadable = att.readableByLlm === false;
    const warnTitle = notReadable
      ? `LLM kann den Inhalt nicht via ReadFile lesen — nur Metadaten${att.reason ? ` (${att.reason})` : ''}`
      : (att.unresolvable ? 'Datei nicht auflösbar' : '');
    return html`
      <span class="chat-attachment-chip d-inline-flex align-items-center gap-2 border rounded bg-body p-1 ${onRemove ? 'pe-2' : 'px-2'}"
            title=${warnTitle}>
        ${thumbUrl
          ? html`
              <img src=${thumbUrl} alt="" class="chat-attachment-thumb rounded" @error=${onThumbError}/>
              <span class="chat-attachment-icon rounded" style="display:none">${this.renderFallbackIcon(att)}</span>`
          : html`<span class="chat-attachment-icon rounded">${this.renderFallbackIcon(att)}</span>`}
        <span class="chat-attachment-name ${att.unresolvable ? 'text-decoration-line-through opacity-75' : ''}">${att.name}</span>
        ${notReadable
          ? html`<span class="chat-attachment-warn badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"
                       title=${warnTitle}>
              <typo3-backend-icon identifier="actions-exclamation" size="small"/>
            </span>`
          : nothing}
        ${onRemove
          ? html`<button type="button"
                  class="btn btn-sm p-0 border-0 text-muted"
                  title="Entfernen"
                  @click=${onRemove}>×</button>`
          : nothing}
      </span>
    `;
  }

  private renderFallbackIcon(att: Attachment): TemplateResult {
    if (att.iconHtml) return html`${unsafeHTML(att.iconHtml)}`;
    return html`<typo3-backend-icon identifier="mimetypes-other-other" size="medium"></typo3-backend-icon>`;
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

  private renderLiveCallout() {
    const text = TYPO3?.lang?.['workspace.callout.selectWorkspace']
      ?? 'Please switch to a workspace before starting a task.';
    return html`
      <div class="alert alert-warning" style="margin-bottom: calc(var(--typo3-spacing) * 2);">
        ${text}
      </div>
    `;
  }
}
