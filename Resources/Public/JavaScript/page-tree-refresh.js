function refreshPageTree() {
  console.log("refreshing page tree");
  const evt = () => new CustomEvent("typo3:pagetree:refresh");
  try {
    window.top?.document.dispatchEvent(evt());
  } catch {
  }
  if (window.top !== window) {
    document.dispatchEvent(evt());
  }
}
document.addEventListener("agent:record-changed", (e) => {
  const change = e.detail;
  if (change?.tablename === "pages") {
    refreshPageTree();
  }
});
//# sourceMappingURL=page-tree-refresh.js.map
