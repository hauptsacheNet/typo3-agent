import {html, LitElement, nothing} from 'lit';
import {customElement, property, state} from 'lit/decorators.js';

interface AgentSettings {
  newTaskUri?: string;
  table?: string;
  uid?: string;
  placeholder?: string;
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

  @state() private message = '';

  override connectedCallback(): void {
    super.connectedCallback();
    if (!this.returnUrl) {
      this.returnUrl = window.location.href;
    }
  }

  private onInput(e: Event): void {
    this.message = (e.target as HTMLTextAreaElement).value;
  }

  private onKeydown(e: KeyboardEvent): void {
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey) && this.message.trim()) {
      e.preventDefault();
      this.renderRoot.querySelector('form')?.submit();
    }
  }

  override render() {
    if (!this.actionUri) {
      return nothing;
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
                  class="d-block w-100 rounded-5 border p-3 bg-white "
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
                    title=${TYPO3?.lang?.['button.submit.title'] ?? 'Start a new AI agent task (Ctrl+Enter)'}
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
}

// Self-insertion for FormEngine context:
// When loaded via PageRenderer (no server-side HTML element), read TYPO3.settings.Agent
// and create the component programmatically.
function autoInsert(): void {
  const settings: AgentSettings | undefined =
    (TYPO3?.settings as Record<string, unknown>)?.Agent as AgentSettings | undefined;

  if (!settings?.newTaskUri) {
    return;
  }

  // Don't insert if the element already exists in the DOM (Page/List module case)
  if (document.querySelector('hn-agent-new-task')) {
    return;
  }

  const el = document.createElement('hn-agent-new-task');
  el.setAttribute('action-uri', settings.newTaskUri);
  el.setAttribute('table', settings.table ?? '');
  el.setAttribute('uid', settings.uid ?? '0');
  el.setAttribute('placeholder', settings.placeholder ?? '');

  const target = document.querySelector('.t3js-module-body');
  if (target) {
    target.insertBefore(el, target.firstChild);
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', autoInsert);
} else {
  autoInsert();
}
