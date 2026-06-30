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
    // v13/v14-Unterscheidung: v13 hat generateRemotePayload (wrappt action="RemoteServer"
    // und ruft intern generateRemotePayloadBody(action, method, data)). In v14 wurde
    // generateRemotePayload entfernt und generateRemotePayloadBody auf (method, data)
    // umsigniert (kein action-Feld mehr). Beide Methoden existieren in v13 mit
    // unterschiedlichen Signaturen, daher discriminiert die Existenz des Wrappers.
    const payload = typeof this.generateRemotePayload === 'function'
      ? this.generateRemotePayload('getWorkspaceInfos', settings)
      : this.generateRemotePayloadBody('getWorkspaceInfos', settings);
    this.sendRemoteRequest(payload).then(async (response: any) => {
      this.renderWorkspaceInfos((await response.resolve())[0].result);
      updateDrawerBadge();
    });
  };

  // 3. Bei SSE-Changes neu laden
  document.addEventListener('agent:record-changed', () => {
    backend.getWorkspaceInfos();
  });

  // 4. Badge im Drawer-Header an die Anzahl der gerenderten Records koppeln.
  //    renderWorkspaceInfos kann asynchron sein — MutationObserver hält das
  //    Badge auch dann konsistent, wenn das Custom-Element später Rows nachzieht.
  const observer = new MutationObserver(() => updateDrawerBadge());
  const contents = document.getElementById('workspace-contents');
  if (contents) {
    observer.observe(contents, { childList: true, subtree: true });
  }
}

function updateDrawerBadge(): void {
  const wrapper = document.querySelector<HTMLElement>('.hn-workspace-drawer__count');
  const value = document.querySelector<HTMLElement>('.hn-workspace-drawer__count-value');
  if (!wrapper || !value) {
    return;
  }
  const rows = document.querySelectorAll(
    '#workspace-contents typo3-workspaces-record-table tbody tr',
  ).length;
  value.textContent = String(rows);
  wrapper.classList.toggle('hidden', rows === 0);
}

init();
