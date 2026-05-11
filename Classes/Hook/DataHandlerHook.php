<?php

declare(strict_types=1);

namespace Hn\Agent\Hook;

use Hn\Agent\Domain\AgentTaskRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class DataHandlerHook
{
    public function processCmdmap_discardAction(string $table, int $uid, array $record, bool &$recordWasDiscarded): void
    {
        GeneralUtility::makeInstance(AgentTaskRepository::class)
            ->removeChangeByRecord($table, $uid);
    }
}
