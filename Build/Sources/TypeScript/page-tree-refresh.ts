interface TrackedChange {
  tablename: string;
}

// The page-tree-element listens on `document` in the frame it lives in, which
// can be either the top frame or the current one depending on the backend
// layout. Dispatching on both is cheap and idempotent and avoids guessing the
// wrong frame.
function refreshPageTree(): void {
  console.log("refreshing page tree");
  const evt = (): CustomEvent => new CustomEvent('typo3:pagetree:refresh');
  try {
    window.top?.document.dispatchEvent(evt());
  } catch {
    // cross-origin top frame — ignore, fall through to local dispatch
  }
  if (window.top !== window) {
    document.dispatchEvent(evt());
  }
}

document.addEventListener('agent:record-changed', (e: Event) => {
  const change = (e as CustomEvent<TrackedChange>).detail;
  if (change?.tablename === 'pages') {
    refreshPageTree();
  }
});
