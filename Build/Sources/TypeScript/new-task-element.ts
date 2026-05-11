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
  @property() placeholder = TYPO3?.lang?.['placeholder.default'] ?? 'Describe the task you\'d like the AI agent to perform...';
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
          <div class="px-3">
            <div style="
              display: flex;
              align-items: stretch;
              border: 1px solid var(--typo3-input-border-color, #bebebe);
              border-radius: var(--typo3-input-border-radius, 2px);
              background: var(--typo3-input-bg, #fff);
              transition: border-color 0.15s ease-in-out;
            ">
              <textarea
                name="message"
                rows="2"
                placeholder=${this.placeholder}
                .value=${this.message}
                @input=${this.onInput}
                @keydown=${this.onKeydown}
                required
                style="
                  flex: 1;
                  border: none;
                  outline: none;
                  resize: vertical;
                  padding: 0.5rem 0.75rem;
                  background: transparent;
                  font: inherit;
                  color: inherit;
                  min-height: 2.5rem;
                "
              ></textarea>
              <button
                type="submit"
                ?disabled=${!this.message.trim()}
                title=${TYPO3?.lang?.['button.submit.title'] ?? 'Start a new AI agent task (Ctrl+Enter)'}
                style="
                  border: none;
                  border-left: 1px solid var(--typo3-input-border-color, #bebebe);
                  background: transparent;
                  padding: 0.5rem 1rem;
                  cursor: pointer;
                  color: var(--typo3-text-color-base, #333);
                  font: inherit;
                  white-space: nowrap;
                  display: flex;
                  align-items: center;
                  opacity: ${this.message.trim() ? '1' : '0.4'};
                  transition: opacity 0.15s;
                "
              >
                <typo3-backend-icon identifier="actions-arrow-down-start-alt" size="small"></typo3-backend-icon>
              </button>
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
