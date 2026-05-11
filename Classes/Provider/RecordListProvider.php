<?php

declare(strict_types=1);

namespace Hn\Agent\Provider;

use Hn\Agent\Renderer\PromptRenderer;
use TYPO3\CMS\Backend\Controller\Event\RenderAdditionalContentToRecordListEvent;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Attribute\AsEventListener;

final class RecordListProvider
{
    public function __construct(
        private readonly PromptRenderer $promptRenderer,
    ) {}

    #[AsEventListener('agent-prompt-to-record-list')]
    public function __invoke(RenderAdditionalContentToRecordListEvent $event): void
    {
        $request = $event->getRequest();
        $pageId = (int)($request->getQueryParams()['id'] ?? $request->getParsedBody()['id'] ?? 0);
        if ($pageId <= 0) {
            return;
        }

        $tableName = (string)($request->getQueryParams()['table'] ?? '');
        $pageTitle = BackendUtility::getRecord('pages', $pageId, 'title')['title'] ?? '';
        $languageService = $GLOBALS['LANG'];
        $placeholder = sprintf($languageService->sL('LLL:EXT:agent/Resources/Private/Language/locallang.xlf:placeholder.page'), $pageTitle);

        $this->promptRenderer->registerSettings($pageId, $placeholder, $tableName);
    }
}
