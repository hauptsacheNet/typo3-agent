// Admin user-filter dropdown: on change, update the `filterUser` query
// parameter on the current URL and navigate to it.
//
// A plain form submit would drop the backend module token from the URL,
// forcing TYPO3 to re-authorize through `/typo3/main?redirect=…`. That
// redirect chain is loaded inside the module iframe, which then renders
// the entire backend chrome a second time (visible as nested backends).
// Rewriting only the one query parameter keeps token + id intact and
// stays inside the module.
function bind(): void {
  const select = document.querySelector<HTMLSelectElement>('select[data-agent-user-filter]');
  if (!select) {
    return;
  }
  select.addEventListener('change', () => {
    const url = new URL(window.location.href);
    if (select.value && select.value !== '0') {
      url.searchParams.set('filterUser', select.value);
    } else {
      url.searchParams.delete('filterUser');
    }
    window.location.assign(url.toString());
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bind);
} else {
  bind();
}
