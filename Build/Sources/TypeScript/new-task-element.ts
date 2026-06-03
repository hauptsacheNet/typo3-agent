import {html, LitElement, nothing} from 'lit';
import {customElement, property, state} from 'lit/decorators.js';

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

  @state() private message = '';

  private get isLive(): boolean {
    // workspaceId === 0 means Live workspace (or attribute not set at all).
    return this.workspaceId <= 0;
  }

  private onInput(e: Event): void {
    this.message = (e.target as HTMLTextAreaElement).value;
  }

  private onKeydown(e: KeyboardEvent): void {
    if (this.isLive) return;
    if (e.key === 'Enter' && !e.shiftKey && this.message.trim()) {
      e.preventDefault();
      this.renderRoot.querySelector('form')?.submit();
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
      <div style="margin-bottom: calc(var(--typo3-spacing) * 2);">
        <form action=${this.actionUri} method="post">
          <input type="hidden" name="table" .value=${this.table}>
          <input type="hidden" name="uid" .value=${String(this.uid)}>
          <input type="hidden" name="return_url" .value=${this.returnUrl}>
          <div class="">
            <div class="position-relative">
              <textarea
                  class="d-block w-100 rounded-4 border p-3 bg-white "
                  name="message"
                  rows="1"
                  placeholder=${this.placeholder}
                  .value=${this.message}
                  @input=${this.onInput}
                  @keydown=${this.onKeydown}
                  required
                  style="outline: none;field-sizing: content;resize: none;"
              ></textarea>
              <div class="position-absolute bottom-0 end-0 p-2">
                <button
                    class="btn"
                    type="submit"
                    ?disabled=${!this.message.trim()}
                    title=${TYPO3?.lang?.['button.submit.title'] ?? 'Start a new AI agent task (Enter)'}
                >
                  <typo3-backend-icon
                      identifier="actions-arrow-down-start-alt"
                      size="small"/>
                </button>
              </div>
            </div>
          </div>
        </form>
      </div>
    `;
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
