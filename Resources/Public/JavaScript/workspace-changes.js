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
    this.sendRemoteRequest(
      this.generateRemotePayload("getWorkspaceInfos", settings)
    ).then(async (response) => {
      this.renderWorkspaceInfos((await response.resolve())[0].result);
    });
  };
  document.addEventListener("agent:record-changed", () => {
    backend.getWorkspaceInfos();
  });
}
init();
//# sourceMappingURL=workspace-changes.js.map
