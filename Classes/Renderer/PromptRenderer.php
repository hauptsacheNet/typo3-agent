<?php

declare(strict_types=1);

namespace Hn\Agent\Renderer;

use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Page\PageRenderer;

class PromptRenderer
{
    public function __construct(
        private readonly UriBuilder $uriBuilder,
        private readonly PageRenderer $pageRenderer,
    ) {}

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

        $this->pageRenderer->addInlineLanguageLabelFile('EXT:agent/Resources/Private/Language/locallang.xlf');

        return sprintf(
            '<hn-agent-new-task action-uri="%s" table="%s" uid="%d" placeholder="%s"></hn-agent-new-task>',
            htmlspecialchars($actionUri, ENT_QUOTES | ENT_HTML5),
            htmlspecialchars($tableName, ENT_QUOTES | ENT_HTML5),
            $uid,
            htmlspecialchars($placeholder, ENT_QUOTES | ENT_HTML5),
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

        $this->pageRenderer->addInlineSetting('Agent', 'newTaskUri', $actionUri);
        $this->pageRenderer->addInlineSetting('Agent', 'table', $tableName);
        $this->pageRenderer->addInlineSetting('Agent', 'uid', (string)$uid);
        $this->pageRenderer->addInlineSetting('Agent', 'placeholder', $placeholder);
        $this->pageRenderer->addInlineLanguageLabelFile('EXT:agent/Resources/Private/Language/locallang.xlf');
        $this->pageRenderer->loadJavaScriptModule('@hn/agent/new-task-element.js');
    }
}
