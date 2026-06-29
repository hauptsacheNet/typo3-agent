import {html, LitElement, nothing, type TemplateResult} from 'lit';
import {customElement, property, state} from 'lit/decorators.js';
import {createRef, ref, type Ref} from 'lit/directives/ref.js';
import DragUploader from '@typo3/backend/drag-uploader.js';
import Modal from '@typo3/backend/modal.js';
import {MessageUtility} from '@typo3/backend/utility/message-utility.js';
import type {Attachment} from '@hn/agent/attachment.js';
import '@hn/agent/attachment-chip-elements.js';

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

  private get preflightUri(): string {
    const ajaxUrls = (TYPO3?.settings as Record<string, unknown> | undefined)?.ajaxUrls as Record<string, string> | undefined;
    return ajaxUrls?.['ai_agent_attachment_preflight'] ?? '';
  }

  @state() private message = '';
  @state() private attachments: Attachment[] = [];

  private uploadTriggerRef: Ref<HTMLElement> = createRef();
  private uploadZoneRef: Ref<HTMLElement> = createRef();

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
    // Manual single-instance init — see chat-element.ts for the rationale
    // (auto-discovery race between MutationObserver and DocumentService.ready
    // can produce duplicate DragUploader instances on the same element).
    const zoneEl = this.uploadZoneRef.value;
    if (zoneEl) {
      new DragUploader(zoneEl);
    }
    this.uploadTriggerRef.value?.addEventListener('uploadSuccess', this.uploadSuccessListener);
  }

  override disconnectedCallback(): void {
    super.disconnectedCallback();
    this.uploadTriggerRef.value?.removeEventListener('uploadSuccess', this.uploadSuccessListener);
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

            ${this.attachments.length > 0
              ? html`
                  <hn-agent-attachment-chips
                      .attachments=${this.attachments}
                      @remove=${(e: CustomEvent<{index: number}>) => this.removeAttachment(e.detail.index)}>
                  </hn-agent-attachment-chips>
                `
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
                ${ref(this.uploadTriggerRef)}
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

  private renderLiveCallout() {
    const text = TYPO3?.lang?.['workspace.callout.selectWorkspace']
      ?? 'Please switch to a workspace before starting a task.';
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
}
