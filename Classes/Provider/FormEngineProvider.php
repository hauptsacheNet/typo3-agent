<?php

declare(strict_types=1);

namespace Hn\Agent\Provider;

use Hn\Agent\Renderer\PromptRenderer;
use TYPO3\CMS\Backend\Controller\Event\AfterFormEnginePageInitializedEvent;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Attribute\AsEventListener;

final class FormEngineProvider
{
    public function __construct(
        private readonly PromptRenderer $promptRenderer,
    ) {}

    #[AsEventListener('agent-prompt-to-form-engine')]
    public function __invoke(AfterFormEnginePageInitializedEvent $event): void
    {
        $request = $event->getRequest();
        $editConf = $request->getQueryParams()['edit'] ?? [];
        if (!is_array($editConf) || $editConf === []) {
            return;
        }

        $table = (string)array_key_first($editConf);
        $uidList = $editConf[$table] ?? [];
        if (!is_array($uidList) || $uidList === []) {
            return;
        }

        $uid = (int)array_key_first($uidList);
        if ($uid <= 0) {
            return;
        }

        if (empty($GLOBALS['TCA'][$table]['ctrl']['versioningWS'])) {
            return;
        }

        $record = BackendUtility::getRecord($table, $uid);
        if ($record === null) {
            return;
        }

        $pageId = $table === 'pages' ? $uid : (int)($record['pid'] ?? 0);
        if ($pageId <= 0) {
            return;
        }

        $recordTitle = BackendUtility::getRecordTitle($table, $record);
        $tableLabel = $GLOBALS['LANG']->sL($GLOBALS['TCA'][$table]['ctrl']['title'] ?? '') ?: $table;
        $placeholder = sprintf($GLOBALS['LANG']->sL('LLL:EXT:agent/Resources/Private/Language/locallang.xlf:placeholder.record'), $recordTitle, $tableLabel);

        $this->promptRenderer->registerSettings($pageId, $placeholder, $table, $uid);
    }
}
