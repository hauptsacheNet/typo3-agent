import {html, LitElement, nothing, type TemplateResult} from 'lit';
import {customElement, property, query, state} from 'lit/decorators.js';
import {createRef, ref, type Ref} from 'lit/directives/ref.js';
import DragUploader from '@typo3/backend/drag-uploader.js';
import Modal from '@typo3/backend/modal.js';
import {MessageUtility} from '@typo3/backend/utility/message-utility.js';
import type {Attachment} from '@hn/agent/attachment.js';
import '@hn/agent/attachment-chip-elements.js';

@customElement('hn-agent-message-composer')
export class MessageComposerElement extends LitElement {

  override createRenderRoot() {
    return this;
  }

  @property() placeholder = '';
  @property({attribute: 'default-upload-folder'}) defaultUploadFolder = '';
  @property({attribute: 'file-browser-uri'}) fileBrowserUri = '';
  @property({attribute: 'field-name'}) fieldName = 'hn-agent-message-composer';
  @property({type: Boolean, reflect: true}) disabled = false;
  @property({attribute: 'submit-title'}) submitTitle = '';

  @state() private message = '';
  @state() private attachments: Attachment[] = [];
  @state() private hasSlottedAction = false;

  @query('form') private formEl!: HTMLFormElement;
  @query('textarea') private textareaEl!: HTMLTextAreaElement;

  private uploadTriggerRef: Ref<HTMLElement> = createRef();
  private uploadZoneRef: Ref<HTMLElement> = createRef();

  private get preflightUri(): string {
    const ajaxUrls = (TYPO3?.settings as Record<string, unknown> | undefined)?.ajaxUrls as Record<string, string> | undefined;
    return ajaxUrls?.['typo3_agent_tasks_attachment_preflight'] ?? '';
  }

  private elementBrowserListener = (e: MessageEvent): void => {
    if (!MessageUtility.verifyOrigin(e.origin)) return;
    const data = e.data as {actionName?: string; fieldName?: string; value?: string; label?: string};
    if (data.actionName !== 'typo3:elementBrowser:elementAdded') return;
    if (data.fieldName !== this.fieldName) return;
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
    // Manual single-instance init. The auto-discovery in DragUploader.init()
    // races (MutationObserver + DocumentService.ready both pick up the same
    // .t3js-drag-uploader element when Lit's render microtask interleaves with
    // the ready() promise resolution), producing duplicate dropzones and
    // double file-picker pops. Instantiating ourselves — without the
    // `t3js-drag-uploader` marker class in the template — sidesteps both
    // paths.
    const zoneEl = this.uploadZoneRef.value;
    if (zoneEl) {
      new DragUploader(zoneEl);
    }
    this.uploadTriggerRef.value?.addEventListener('uploadSuccess', this.uploadSuccessListener);
    this.detectSlottedAction();
  }

  override updated(): void {
    this.detectSlottedAction();
  }

  private detectSlottedAction(): void {
    const slotted = this.querySelector(':scope > [slot="action"]') !== null;
    if (slotted !== this.hasSlottedAction) {
      this.hasSlottedAction = slotted;
    }
  }

  override disconnectedCallback(): void {
    super.disconnectedCallback();
    this.uploadTriggerRef.value?.removeEventListener('uploadSuccess', this.uploadSuccessListener);
    window.removeEventListener('message', this.elementBrowserListener);
  }

  override focus(): void {
    this.textareaEl?.focus();
  }

  private onInput(e: Event): void {
    this.message = (e.target as HTMLTextAreaElement).value;
  }

  private onKeydown(e: KeyboardEvent): void {
    if (this.disabled) return;
    if (e.key === 'Enter' && !e.shiftKey && this.canSubmit()) {
      e.preventDefault();
      this.formEl?.requestSubmit();
    }
  }

  private canSubmit(): boolean {
    return !this.disabled && (this.message.trim() !== '' || this.attachments.length > 0);
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
    if (!this.fileBrowserUri || this.disabled) return;
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

  private onSubmit(e: Event): void {
    e.preventDefault();
    if (!this.canSubmit()) return;

    const detail = {
      message: this.message.trim(),
      attachments: this.attachments,
    };
    this.message = '';
    this.attachments = [];

    this.dispatchEvent(new CustomEvent('submit', {
      detail,
      bubbles: true,
      composed: true,
    }));
  }

  override render() {
    const uploadEnabled = !!this.defaultUploadFolder;
    const pickEnabled = !!this.fileBrowserUri;

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
              ?disabled=${this.disabled}
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
            <div class="composer-action-slot ms-auto">
              <slot name="action"></slot>
              ${this.hasSlottedAction ? nothing : this.renderDefaultSubmit()}
            </div>
          </div>
        </form>
      </div>
    `;
  }

  private renderDefaultSubmit(): TemplateResult {
    const title = this.submitTitle
      || TYPO3?.lang?.['button.submit.title']
      || 'Send (Enter)';
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

  private renderAttachmentsBar(uploadEnabled: boolean, pickEnabled: boolean): TemplateResult {
    return html`
      <div>
        <button type="button"
                class="chat-upload-trigger btn btn-sm btn-default"
                ${ref(this.uploadTriggerRef)}
                ?disabled=${this.disabled || !uploadEnabled}
                title=${uploadEnabled ? 'Datei hochladen' : 'Kein Upload-Ordner verfügbar'}>
          <typo3-backend-icon identifier="actions-upload" size="small"/>
          Hochladen
        </button>
        <button type="button"
                class="btn btn-sm btn-default"
                ?disabled=${this.disabled || !pickEnabled}
                @click=${this.onPickClick}>
          <typo3-backend-icon identifier="actions-folder" size="small"/>
          Auswählen
        </button>
      </div>
    `;
  }
}
