import "@hn/agent/new-task-element.js";
function autoInsert() {
  const settings = TYPO3?.settings?.Agent;
  if (!settings?.newTaskUri) {
    return;
  }
  if (document.querySelector("hn-agent-new-task")) {
    return;
  }
  const el = document.createElement("hn-agent-new-task");
  el.setAttribute("action-uri", settings.newTaskUri);
  el.setAttribute("table", settings.table ?? "");
  el.setAttribute("uid", settings.uid ?? "0");
  el.setAttribute("placeholder", settings.placeholder ?? "");
  el.setAttribute("return-url", window.location.href);
  el.setAttribute("workspace-id", settings.workspaceId ?? "0");
  el.setAttribute("workspace-title", settings.workspaceTitle ?? "");
  const target = document.querySelector(".t3js-module-body");
  if (target) {
    target.insertBefore(el, target.firstChild);
  }
}
if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", autoInsert);
} else {
  autoInsert();
}
//# sourceMappingURL=auto-insert-new-task.js.map
