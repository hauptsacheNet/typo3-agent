<?php

declare(strict_types=1);

namespace Hn\Agent\Renderer;

use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Resource\DefaultUploadFolderResolver;
use TYPO3\CMS\Core\Resource\Folder;

class PromptRenderer
{
    public function __construct(
        private readonly UriBuilder $uriBuilder,
        private readonly PageRenderer $pageRenderer,
        private readonly DefaultUploadFolderResolver $defaultUploadFolderResolver,
    ) {}

    /**
     * Resolve the current backend user's workspace into [id, title] for the UI.
     *
     * @return array{id:int,title:string}
     */
    private function getCurrentWorkspaceInfo(): array
    {
        $workspaceId = (int)($GLOBALS['BE_USER']->workspace ?? 0);
        if ($workspaceId <= 0) {
            $title = $GLOBALS['LANG']->sL('LLL:EXT:agent/Resources/Private/Language/locallang.xlf:workspace.live') ?: 'Live';
            return ['id' => 0, 'title' => $title];
        }
        $record = BackendUtility::getRecord('sys_workspace', $workspaceId, 'title');
        return [
            'id' => $workspaceId,
            'title' => (string)($record['title'] ?? ('#' . $workspaceId)),
        ];
    }

    /**
     * Registriert alles, was der Message-Composer im Modul-Iframe braucht, um
     * Upload & File-Picking betreiben zu können — als Inline-Settings, die der
     * Composer im JS liest:
     *   - `TYPO3.settings.Agent.defaultUploadFolder` (kontextabhängig via
     *     DefaultUploadFolderResolver aus pageId + tableName + BE_USER auf-
     *     gelöst).
     *   - `TYPO3.settings.Agent.elementBrowserUrl` (Basis-URL des Element-
     *     Browsers; der Core setzt sie nur im äußeren Backend-Shell, nicht im
     *     Modul-Iframe).
     *   - DragUploader-Sprach-Labels (`TYPO3.lang["file_upload.*"]`).
     */
    public function registerUploadContext(int $pageId, string $tableName): void
    {
        $beUser = $GLOBALS['BE_USER'] ?? null;
        $uploadFolder = $beUser instanceof BackendUserAuthentication
            ? $this->defaultUploadFolderResolver->resolve($beUser, $pageId, $tableName !== '' ? $tableName : null)
            : false;
        $defaultUploadFolder = $uploadFolder instanceof Folder ? $uploadFolder->getCombinedIdentifier() : '';

        $this->pageRenderer->addInlineLanguageLabelFile('EXT:core/Resources/Private/Language/locallang_core.xlf', 'file_upload');
        $this->pageRenderer->addInlineSetting('Agent', 'defaultUploadFolder', $defaultUploadFolder);
        $this->pageRenderer->addInlineSetting(
            'Agent',
            'elementBrowserUrl',
            (string)$this->uriBuilder->buildUriFromRoute('wizard_element_browser'),
        );
    }

    /**
     * Render the <hn-agent-new-task> HTML element with context attributes.
     * Used by Page module and List module providers that can inject HTML directly.
     */
    public function renderHtml(int $pageId, string $placeholder, string $tableName = '', int $uid = 0): string
    {
        if ($pageId <= 0) {
            return '';
        }

        $actionUri = (string)$this->uriBuilder->buildUriFromRoute('web_typo3_agent_tasks.new', ['id' => $pageId]);
        $workspace = $this->getCurrentWorkspaceInfo();
        $this->registerUploadContext($pageId, $tableName);

        $this->pageRenderer->addInlineLanguageLabelFile('EXT:agent/Resources/Private/Language/locallang.xlf');
        $this->pageRenderer->addCssFile('EXT:agent/Resources/Public/Css/agent-chat.css');

        return sprintf(
            '<hn-agent-new-task action-uri="%s" table="%s" uid="%d" placeholder="%s" workspace-id="%d" workspace-title="%s"></hn-agent-new-task>',
            htmlspecialchars($actionUri, ENT_QUOTES | ENT_HTML5),
            htmlspecialchars($tableName, ENT_QUOTES | ENT_HTML5),
            $uid,
            htmlspecialchars($placeholder, ENT_QUOTES | ENT_HTML5),
            $workspace['id'],
            htmlspecialchars($workspace['title'], ENT_QUOTES | ENT_HTML5),
        );
    }

    /**
     * Register context as inline settings and load the JS module via PageRenderer.
     * Used by FormEngine provider where no direct HTML injection is possible.
     */
    public function registerSettings(int $pageId, string $placeholder, string $tableName = '', int $uid = 0): void
    {
        if ($pageId <= 0) {
            return;
        }

        $actionUri = (string)$this->uriBuilder->buildUriFromRoute('web_typo3_agent_tasks.new', ['id' => $pageId]);
        $workspace = $this->getCurrentWorkspaceInfo();
        $this->registerUploadContext($pageId, $tableName);

        $this->pageRenderer->addInlineSetting('Agent', 'newTaskUri', $actionUri);
        $this->pageRenderer->addInlineSetting('Agent', 'table', $tableName);
        $this->pageRenderer->addInlineSetting('Agent', 'uid', (string)$uid);
        $this->pageRenderer->addInlineSetting('Agent', 'placeholder', $placeholder);
        $this->pageRenderer->addInlineSetting('Agent', 'workspaceId', (string)$workspace['id']);
        $this->pageRenderer->addInlineSetting('Agent', 'workspaceTitle', $workspace['title']);
        $this->pageRenderer->addInlineLanguageLabelFile('EXT:agent/Resources/Private/Language/locallang.xlf');
        $this->pageRenderer->addCssFile('EXT:agent/Resources/Public/Css/agent-chat.css');
        $this->pageRenderer->loadJavaScriptModule('@hn/agent/auto-insert-new-task.js');
    }
}
