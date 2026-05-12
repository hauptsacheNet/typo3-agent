import '@hn/agent/new-task-element.js';

interface AgentSettings {
  newTaskUri?: string;
  table?: string;
  uid?: string;
  placeholder?: string;
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
  el.setAttribute('return-url', window.location.href);

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
