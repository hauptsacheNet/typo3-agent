<?php

declare(strict_types=1);

namespace Hn\Agent\Provider;

use Hn\Agent\Renderer\PromptRenderer;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Attribute\AsEventListener;

final class PageModuleProvider
{
    public function __construct(
        private readonly PromptRenderer $promptRenderer,
    ) {}

    #[AsEventListener('agent-prompt-to-page-module')]
    public function __invoke(ModifyPageLayoutContentEvent $event): void
    {
        $pageId = (int)($event->getRequest()->getQueryParams()['id'] ?? 0);
        if ($pageId <= 0) {
            return;
        }

        $pageTitle = BackendUtility::getRecord('pages', $pageId, 'title')['title'] ?? '';
        $languageService = $GLOBALS['LANG'];
        $placeholder = sprintf($languageService->sL('LLL:EXT:agent/Resources/Private/Language/locallang.xlf:placeholder.page'), $pageTitle);

        $this->promptRenderer->registerSettings($pageId, $placeholder, 'pages', $pageId);
    }
}
