/**
 * Progressive enhancement for the "model" field in the extension
 * configuration form. Finds the sibling apiUrl/apiKey inputs, calls the
 * agent_fetch_models AJAX route whenever both are filled, and swaps the
 * text input for a <select> populated with the provider's model list.
 *
 * Falls back silently to a text input if the fetch fails — the text input
 * is the server-rendered default, so the field is never broken.
 */

const FIELD_SELECTOR = '[data-agent-model-field]';
const STATUS_CLASS = 'agent-model-field__status';
const DEBOUNCE_MS = 400;

const initialisedFields = new WeakSet();

function findSiblingInput(container, configKey) {
  // Extension configuration forms use input names like `extConf[apiUrl]`.
  const form = container.closest('form') || document;
  const suffix = '[' + configKey + ']';
  return form.querySelector('input[name$="' + CSS.escape(suffix) + '"]');
}

function setStatus(container, message, kind) {
  const el = container.querySelector('.' + STATUS_CLASS);
  if (!el) return;
  el.textContent = message || '';
  el.classList.toggle('text-danger', kind === 'error');
}

async function fetchModels(ajaxUrl, apiUrl, apiKey, signal) {
  const url = new URL(ajaxUrl, window.location.origin);
  url.searchParams.set('apiUrl', apiUrl);
  url.searchParams.set('apiKey', apiKey);

  const response = await fetch(url.toString(), {
    method: 'GET',
    credentials: 'same-origin',
    headers: { 'Accept': 'application/json' },
    signal,
  });
  const data = await response.json().catch(() => null);
  if (!data || data.ok !== true || !Array.isArray(data.models)) {
    const err = (data && data.error) || ('HTTP ' + response.status);
    throw new Error(err);
  }
  return data.models;
}

function replaceWithSelect(container, currentInput, models, selected) {
  const select = document.createElement('select');
  select.name = currentInput.name;
  select.id = currentInput.id;
  select.className = 'form-select';

  let foundSelected = false;
  for (const id of models) {
    const option = document.createElement('option');
    option.value = id;
    option.textContent = id;
    if (id === selected) {
      option.selected = true;
      foundSelected = true;
    }
    select.appendChild(option);
  }

  // If the currently-saved value isn't in the returned list (e.g. a
  // retired model), keep it as the selected option so nothing gets lost.
  if (selected && !foundSelected) {
    const option = document.createElement('option');
    option.value = selected;
    option.textContent = selected + ' (not in provider list)';
    option.selected = true;
    select.insertBefore(option, select.firstChild);
  }

  currentInput.replaceWith(select);
  return select;
}

function replaceWithInput(container, currentEl, value) {
  if (currentEl.tagName === 'INPUT') return currentEl;
  const input = document.createElement('input');
  input.type = 'text';
  input.name = currentEl.name;
  input.id = currentEl.id;
  input.className = 'form-control';
  input.value = value || '';
  input.autocomplete = 'off';
  input.placeholder = 'e.g. anthropic/claude-haiku-4-5';
  currentEl.replaceWith(input);
  return input;
}

function initField(container) {
  if (initialisedFields.has(container)) return;
  initialisedFields.add(container);

  const ajaxUrl = container.dataset.ajaxUrl;
  const inputId = container.dataset.inputId;
  if (!ajaxUrl || !inputId) return;

  const apiUrlInput = findSiblingInput(container, 'apiUrl');
  const apiKeyInput = findSiblingInput(container, 'apiKey');
  if (!apiUrlInput || !apiKeyInput) return;

  let debounceTimer = null;
  let inflight = null;

  const refresh = () => {
    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(run, DEBOUNCE_MS);
  };

  const currentField = () => document.getElementById(inputId);

  const run = async () => {
    const apiUrl = apiUrlInput.value.trim();
    const apiKey = apiKeyInput.value.trim();

    if (!apiUrl || !apiKey) {
      setStatus(container, 'Enter API URL and API key to load the model list.');
      return;
    }

    if (inflight) inflight.abort();
    inflight = new AbortController();
    setStatus(container, 'Loading models…');

    try {
      const models = await fetchModels(ajaxUrl, apiUrl, apiKey, inflight.signal);
      const field = currentField();
      if (!field) return;
      const selected = field.value;
      replaceWithSelect(container, field, models, selected);
      setStatus(container, models.length + ' models available.');
    } catch (err) {
      if (err.name === 'AbortError') return;
      const field = currentField();
      if (field) {
        const preserved = field.value;
        const input = replaceWithInput(container, field, preserved);
        input.focus({ preventScroll: true });
      }
      setStatus(container, 'Could not load models: ' + err.message + ' — enter the model ID manually.', 'error');
    }
  };

  apiUrlInput.addEventListener('input', refresh);
  apiUrlInput.addEventListener('change', refresh);
  apiKeyInput.addEventListener('input', refresh);
  apiKeyInput.addEventListener('change', refresh);

  // Attempt an initial load if both fields are already populated on page load.
  if (apiUrlInput.value.trim() && apiKeyInput.value.trim()) {
    run();
  } else {
    setStatus(container, 'Enter API URL and API key to load the model list.');
  }
}

function initAll() {
  document.querySelectorAll(FIELD_SELECTOR).forEach(initField);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initAll);
} else {
  initAll();
}
