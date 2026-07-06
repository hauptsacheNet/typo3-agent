/**
 * Custom element that renders a text input + <datalist> for the Extension
 * Configuration `model` field. Registered globally on every backend page so
 * the Install Tool can inject <hn-agent-model-input> via innerHTML (which
 * doesn't execute <script> tags but *does* upgrade custom elements).
 *
 * Attributes:
 *   name    - form field name (required)
 *   value   - current value
 *   list-id - id of the associated datalist (auto-generated if omitted)
 *
 * Model suggestions are fetched from TYPO3.settings.ajaxUrls['typo3_agent_config_models']
 * on first focus, cached in sessionStorage keyed by the resolved endpoint.
 * The parent/top window's TYPO3 global is also consulted because the
 * Extension Configuration form is rendered inside the Install Tool iframe
 * whose own TYPO3 global lacks the extension's ajax routes.
 */

interface ModelListResponse {
  models?: unknown;
}

class AgentModelInput extends HTMLElement {
  private built = false;

  connectedCallback(): void {
    if (this.built) return;
    this.built = true;

    const name = this.getAttribute('name') || 'model';
    const value = this.getAttribute('value') ?? '';
    const listId = this.getAttribute('list-id') || `em-agent-${name}-models`;

    const input = document.createElement('input');
    input.className = 'form-control t3js-agent-model-input';
    input.type = 'text';
    input.name = name;
    input.value = value;
    input.setAttribute('list', listId);
    input.autocomplete = 'off';
    input.spellcheck = false;

    const list = document.createElement('datalist');
    list.id = listId;

    this.appendChild(input);
    this.appendChild(list);

    let loaded = false;
    input.addEventListener('focus', () => {
      if (loaded) return;
      loaded = true;
      void this.loadSuggestions(list);
    });
  }

  private resolveEndpoint(): string {
    const routeId = 'typo3_agent_config_models';
    const candidates: Window[] = [];
    try { candidates.push(window); } catch { /* ignore */ }
    try { if (window.parent && window.parent !== window) candidates.push(window.parent); } catch { /* ignore */ }
    try {
      if (window.top && window.top !== window && window.top !== window.parent) {
        candidates.push(window.top);
      }
    } catch { /* ignore */ }

    for (const w of candidates) {
      try {
        const ajaxUrls = (w as unknown as { TYPO3?: { settings?: { ajaxUrls?: Record<string, string> } } })
          .TYPO3?.settings?.ajaxUrls;
        const url = ajaxUrls?.[routeId];
        if (typeof url === 'string' && url) return url;
      } catch { /* cross-origin — skip */ }
    }
    return '';
  }

  private fill(list: HTMLDataListElement, models: unknown): void {
    if (!Array.isArray(models)) return;
    list.replaceChildren();
    for (const id of models) {
      if (typeof id !== 'string' || id === '') continue;
      const opt = document.createElement('option');
      opt.value = id;
      list.appendChild(opt);
    }
  }

  private async loadSuggestions(list: HTMLDataListElement): Promise<void> {
    const endpoint = this.resolveEndpoint();
    if (!endpoint) return;
    const cacheKey = `agent-models::${endpoint}`;

    try {
      const raw = window.sessionStorage.getItem(cacheKey);
      if (raw) {
        const parsed = JSON.parse(raw) as ModelListResponse;
        this.fill(list, parsed?.models);
      }
    } catch { /* ignore cache miss / parse error */ }

    try {
      const res = await fetch(endpoint, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
      });
      if (!res.ok) return;
      const data = await res.json() as ModelListResponse;
      if (Array.isArray(data?.models)) {
        this.fill(list, data.models);
        try {
          window.sessionStorage.setItem(cacheKey, JSON.stringify({ models: data.models }));
        } catch { /* storage quota / disabled — non-fatal */ }
      }
    } catch { /* network error — datalist stays empty, input still works */ }
  }
}

if (!customElements.get('hn-agent-model-input')) {
  customElements.define('hn-agent-model-input', AgentModelInput);
}
