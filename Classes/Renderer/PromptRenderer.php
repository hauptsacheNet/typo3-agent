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
     * Resolve the upload-context bundle (default upload folder, file browser
     * URI, preflight URI) for the chat composer / new-task form. Also injects
     * the DragUploader's inline language labels — they're read from
     * TYPO3.lang["file_upload.*"] but not loaded by the module shell.
     *
     * @return array{defaultUploadFolder:string,fileBrowserUri:string,preflightUri:string}
     */
    public function getUploadContext(int $pageId, string $tableName, string $browserFieldName): array
    {
        $beUser = $GLOBALS['BE_USER'] ?? null;
        $uploadFolder = $beUser instanceof BackendUserAuthentication
            ? $this->defaultUploadFolderResolver->resolve($beUser, $pageId, $tableName !== '' ? $tableName : null)
            : false;
        $defaultUploadFolder = $uploadFolder instanceof Folder ? $uploadFolder->getCombinedIdentifier() : '';

        $this->pageRenderer->addInlineLanguageLabelFile('EXT:core/Resources/Private/Language/locallang_core.xlf', 'file_upload');

        return [
            'defaultUploadFolder' => $defaultUploadFolder,
            'fileBrowserUri' => (string)$this->uriBuilder->buildUriFromRoute('wizard_element_browser', [
                'mode' => 'file',
                'bparams' => $browserFieldName . '|||*|',
            ]),
            'preflightUri' => (string)$this->uriBuilder->buildUriFromRoute('ajax_ai_agent_attachment_preflight'),
        ];
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

        $actionUri = (string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat.new', ['id' => $pageId]);
        $workspace = $this->getCurrentWorkspaceInfo();
        $upload = $this->getUploadContext($pageId, $tableName, 'hn-agent-new-task');

        $this->pageRenderer->addInlineLanguageLabelFile('EXT:agent/Resources/Private/Language/locallang.xlf');

        return sprintf(
            '<hn-agent-new-task action-uri="%s" table="%s" uid="%d" placeholder="%s" workspace-id="%d" workspace-title="%s" default-upload-folder="%s" file-browser-uri="%s" preflight-uri="%s"></hn-agent-new-task>',
            htmlspecialchars($actionUri, ENT_QUOTES | ENT_HTML5),
            htmlspecialchars($tableName, ENT_QUOTES | ENT_HTML5),
            $uid,
            htmlspecialchars($placeholder, ENT_QUOTES | ENT_HTML5),
            $workspace['id'],
            htmlspecialchars($workspace['title'], ENT_QUOTES | ENT_HTML5),
            htmlspecialchars($upload['defaultUploadFolder'], ENT_QUOTES | ENT_HTML5),
            htmlspecialchars($upload['fileBrowserUri'], ENT_QUOTES | ENT_HTML5),
            htmlspecialchars($upload['preflightUri'], ENT_QUOTES | ENT_HTML5),
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

        $actionUri = (string)$this->uriBuilder->buildUriFromRoute('ai_agent_chat.new', ['id' => $pageId]);
        $workspace = $this->getCurrentWorkspaceInfo();
        $upload = $this->getUploadContext($pageId, $tableName, 'hn-agent-new-task');

        $this->pageRenderer->addInlineSetting('Agent', 'newTaskUri', $actionUri);
        $this->pageRenderer->addInlineSetting('Agent', 'table', $tableName);
        $this->pageRenderer->addInlineSetting('Agent', 'uid', (string)$uid);
        $this->pageRenderer->addInlineSetting('Agent', 'placeholder', $placeholder);
        $this->pageRenderer->addInlineSetting('Agent', 'workspaceId', (string)$workspace['id']);
        $this->pageRenderer->addInlineSetting('Agent', 'workspaceTitle', $workspace['title']);
        $this->pageRenderer->addInlineSetting('Agent', 'defaultUploadFolder', $upload['defaultUploadFolder']);
        $this->pageRenderer->addInlineSetting('Agent', 'fileBrowserUri', $upload['fileBrowserUri']);
        $this->pageRenderer->addInlineSetting('Agent', 'preflightUri', $upload['preflightUri']);
        $this->pageRenderer->addInlineLanguageLabelFile('EXT:agent/Resources/Private/Language/locallang.xlf');
        $this->pageRenderer->loadJavaScriptModule('@hn/agent/auto-insert-new-task.js');
    }
}
