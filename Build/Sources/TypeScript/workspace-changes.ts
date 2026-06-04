import backendInstance from '@typo3/workspaces/backend.js';

function init(): void {
  const wrapper = document.getElementById('workspace-content-wrapper');
  if (!wrapper) {
    return;
  }

  const taskUid = wrapper.dataset.taskUid;
  if (!taskUid) {
    return;
  }

  // 1. Task-ID als Query-Param an die Workspace-AJAX-URL hängen.
  //    Alle Calls über sendRemoteRequest tragen den Param automatisch mit.
  //    Der EventListener FilterWorkspaceDataForAgentTask liest ihn aus
  //    $GLOBALS['TYPO3_REQUEST']->getQueryParams().
  const ajaxUrls = (TYPO3.settings as unknown as Record<string, Record<string, string>>).ajaxUrls;
  if (ajaxUrls?.workspace_dispatch) {
    ajaxUrls.workspace_dispatch += '&agentTaskUid=' + encodeURIComponent(taskUid);
  }

  // 2. getWorkspaceInfos patchen: breite Suche (alle Seiten, alle Tiefen).
  //    Der Agent kann Records auf verschiedenen Seiten ändern. Die Standard-
  //    Workspace-Abfrage filtert nach aktueller Seite + Tiefe. Mit id=-1
  //    werden alle Seiten abgefragt, der EventListener filtert auf Task-Records.
  const backend = backendInstance as any;
  backend.getWorkspaceInfos = function () {
    const settings = { ...this.settings, id: -1, depth: 99 };
    this.sendRemoteRequest(
      this.generateRemotePayload('getWorkspaceInfos', settings),
    ).then(async (response: any) => {
      this.renderWorkspaceInfos((await response.resolve())[0].result);
    });
  };

  // 3. Bei SSE-Changes neu laden
  document.addEventListener('agent:record-changed', () => {
    backend.getWorkspaceInfos();
  });
}

init();
