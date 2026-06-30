import backendInstance from "@typo3/workspaces/backend.js";
function init() {
  const wrapper = document.getElementById("workspace-content-wrapper");
  if (!wrapper) {
    return;
  }
  const taskUid = wrapper.dataset.taskUid;
  if (!taskUid) {
    return;
  }
  const ajaxUrls = TYPO3.settings.ajaxUrls;
  if (ajaxUrls?.workspace_dispatch) {
    ajaxUrls.workspace_dispatch += "&agentTaskUid=" + encodeURIComponent(taskUid);
  }
  const backend = backendInstance;
  backend.getWorkspaceInfos = function() {
    const settings = { ...this.settings, id: -1, depth: 99 };
    const payload = typeof this.generateRemotePayload === "function" ? this.generateRemotePayload("getWorkspaceInfos", settings) : this.generateRemotePayloadBody("getWorkspaceInfos", settings);
    this.sendRemoteRequest(payload).then(async (response) => {
      this.renderWorkspaceInfos((await response.resolve())[0].result);
      updateDrawerBadge();
    });
  };
  document.addEventListener("agent:record-changed", () => {
    backend.getWorkspaceInfos();
  });
  const observer = new MutationObserver(() => updateDrawerBadge());
  const contents = document.getElementById("workspace-contents");
  if (contents) {
    observer.observe(contents, { childList: true, subtree: true });
  }
}
function updateDrawerBadge() {
  const wrapper = document.querySelector(".hn-workspace-drawer__count");
  const value = document.querySelector(".hn-workspace-drawer__count-value");
  if (!wrapper || !value) {
    return;
  }
  const rows = document.querySelectorAll(
    "#workspace-contents typo3-workspaces-record-table tbody tr"
  ).length;
  value.textContent = String(rows);
  wrapper.classList.toggle("hidden", rows === 0);
}
init();
//# sourceMappingURL=workspace-changes.js.map
