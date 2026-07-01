import {html, LitElement, nothing} from 'lit';
import {customElement, property, state} from 'lit/decorators.js';
import type {Attachment} from '@hn/agent/attachment.js';
import '@hn/agent/message-composer.js';

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

  @state() private submitting = false;
  @state() private errorMessage = '';

  private get isLive(): boolean {
    // workspaceId === 0 means Live workspace (or attribute not set at all).
    return this.workspaceId <= 0;
  }

  private async onComposerSubmit(e: CustomEvent<{message: string; attachments: Attachment[]}>): Promise<void> {
    const {message, attachments} = e.detail;
    this.submitting = true;
    this.errorMessage = '';

    const formData = new FormData();
    formData.append('message', message);
    formData.append('table', this.table);
    formData.append('uid', String(this.uid));
    formData.append('return_url', this.returnUrl);
    formData.append('attachments', JSON.stringify(attachments.map(a => ({
      uid: a.uid,
      identifier: a.identifier,
      name: a.name,
    }))));

    try {
      const response = await fetch(this.actionUri, {
        method: 'POST',
        body: formData,
        redirect: 'follow',
      });
      if (!response.ok) {
        this.errorMessage = `Request failed (${response.status})`;
        this.submitting = false;
        return;
      }
      window.location.href = response.url;
    } catch (err) {
      this.errorMessage = (err as Error).message || String(err);
      this.submitting = false;
    }
  }

  override render() {
    if (!this.actionUri) {
      return nothing;
    }

    if (this.isLive) {
      return this.renderLiveCallout();
    }

    return html`
      <div>
        ${this.errorMessage
          ? html`<div class="alert alert-danger">${this.errorMessage}</div>`
          : nothing}
        <hn-agent-message-composer
            ?disabled=${this.submitting}
            placeholder=${this.placeholder}
            field-name="hn-agent-new-task"
            @submit=${this.onComposerSubmit}>
        </hn-agent-message-composer>
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
