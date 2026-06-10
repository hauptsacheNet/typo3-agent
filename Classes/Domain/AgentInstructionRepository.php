<?php

declare(strict_types=1);

namespace Hn\Agent\Domain;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\StartTimeRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\EndTimeRestriction;

/**
 * Reads the editor-maintainable agent instructions (tx_agent_instruction).
 *
 * Instructions are global: every active record is returned regardless of the
 * page it lives on. Access control for *editing* is handled by native TYPO3
 * group permissions; reading is unrestricted so the guidance can be surfaced
 * in the chat UI and injected into the system prompt.
 */
class AgentInstructionRepository
{
    private const TABLE = 'tx_agent_instruction';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * All currently active instructions, ordered by sorting.
     *
     * "Active" means not deleted, not hidden, and within the optional
     * start/end time window — so editors can stage instructions or retire
     * them without deleting the record.
     *
     * @return array<int, array{uid:int, title:string, instruction:string}>
     */
    public function findActiveInstructions(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new HiddenRestriction())
            ->add(new StartTimeRestriction())
            ->add(new EndTimeRestriction());

        $rows = $qb
            ->select('uid', 'title', 'instruction')
            ->from(self::TABLE)
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $instructions = [];
        foreach ($rows as $row) {
            $text = trim((string)($row['instruction'] ?? ''));
            if ($text === '') {
                continue;
            }
            $instructions[] = [
                'uid' => (int)$row['uid'],
                'title' => (string)($row['title'] ?? ''),
                'instruction' => $text,
            ];
        }
        return $instructions;
    }
}
