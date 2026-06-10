<?php

declare(strict_types=1);

namespace Hn\Agent\Domain;

use Doctrine\DBAL\ArrayParameterType;
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
 * in the chat UI, injected into the system prompt (mode "always"), or fetched
 * on demand via the GetInstruction tool (mode "on_demand").
 *
 * `instruction` is returned as raw HTML (RTE body) — the chat panel renders it
 * via f:format.html; the conversion to plain prompt text is done by
 * \Hn\Agent\Service\InstructionTextFormatter, not here.
 */
class AgentInstructionRepository
{
    private const TABLE = 'tx_agent_instruction';

    public const MODE_ALWAYS = 'always';
    public const MODE_ON_DEMAND = 'on_demand';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * All currently active instructions, ordered by sorting.
     *
     * "Active" means not deleted, not hidden, and within the optional
     * start/end time window.
     *
     * @return array<int, array{uid:int, title:string, description:string, instruction:string, mode:string}>
     */
    public function findActive(): array
    {
        return $this->fetch();
    }

    /**
     * Active instructions with mode "always" — injected into the system prompt.
     *
     * @return array<int, array{uid:int, title:string, description:string, instruction:string, mode:string}>
     */
    public function findAlways(): array
    {
        return $this->fetch(static fn(array $s): bool => $s['mode'] === self::MODE_ALWAYS);
    }

    /**
     * Active instructions with mode "on_demand" — only indexed in the prompt
     * and fetched on demand via the GetInstruction tool.
     *
     * @return array<int, array{uid:int, title:string, description:string, instruction:string, mode:string}>
     */
    public function findOnDemand(): array
    {
        return $this->fetch(static fn(array $s): bool => $s['mode'] === self::MODE_ON_DEMAND);
    }

    /**
     * Active instructions for the given UIDs, ordered by sorting. Unknown /
     * inactive UIDs are silently dropped.
     *
     * @param int[] $uids
     * @return array<int, array{uid:int, title:string, description:string, instruction:string, mode:string}>
     */
    public function findByUids(array $uids): array
    {
        $uids = array_values(array_filter(array_map('intval', $uids), static fn(int $u): bool => $u > 0));
        if ($uids === []) {
            return [];
        }
        $qb = $this->createQueryBuilder();
        $qb->andWhere(
            $qb->expr()->in('uid', $qb->createNamedParameter($uids, ArrayParameterType::INTEGER)),
        );
        return $this->mapRows($qb->executeQuery()->fetchAllAssociative());
    }

    /**
     * Keyword search across title / description / instruction of active
     * instructions, ordered by sorting.
     *
     * @return array<int, array{uid:int, title:string, description:string, instruction:string, mode:string}>
     */
    public function searchActive(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $qb = $this->createQueryBuilder();
        $like = '%' . $qb->escapeLikeWildcards($query) . '%';
        $qb->andWhere(
            $qb->expr()->or(
                $qb->expr()->like('title', $qb->createNamedParameter($like)),
                $qb->expr()->like('description', $qb->createNamedParameter($like)),
                $qb->expr()->like('instruction', $qb->createNamedParameter($like)),
            ),
        );
        return $this->mapRows($qb->executeQuery()->fetchAllAssociative());
    }

    /**
     * Fetch active instructions, optionally filtered by a predicate on the
     * mapped row.
     *
     * @param null|callable(array{uid:int, title:string, description:string, instruction:string, mode:string}): bool $filter
     * @return array<int, array{uid:int, title:string, description:string, instruction:string, mode:string}>
     */
    private function fetch(?callable $filter = null): array
    {
        $instructions = $this->mapRows($this->createQueryBuilder()->executeQuery()->fetchAllAssociative());
        if ($filter === null) {
            return $instructions;
        }
        return array_values(array_filter($instructions, $filter));
    }

    /**
     * Query builder over active (deleted/hidden/time-restricted) instructions,
     * ordered by sorting, selecting the relevant columns.
     */
    private function createQueryBuilder(): \TYPO3\CMS\Core\Database\Query\QueryBuilder
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new HiddenRestriction())
            ->add(new StartTimeRestriction())
            ->add(new EndTimeRestriction());

        return $qb
            ->select('uid', 'title', 'description', 'instruction', 'mode')
            ->from(self::TABLE)
            ->orderBy('sorting', 'ASC');
    }

    /**
     * Map raw DB rows to normalized instruction arrays, dropping ones with an
     * empty body.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{uid:int, title:string, description:string, instruction:string, mode:string}>
     */
    private function mapRows(array $rows): array
    {
        $instructions = [];
        foreach ($rows as $row) {
            $instruction = trim((string)($row['instruction'] ?? ''));
            if ($instruction === '') {
                continue;
            }
            $mode = (string)($row['mode'] ?? self::MODE_ALWAYS);
            $instructions[] = [
                'uid' => (int)$row['uid'],
                'title' => (string)($row['title'] ?? ''),
                'description' => (string)($row['description'] ?? ''),
                'instruction' => $instruction,
                'mode' => $mode === self::MODE_ON_DEMAND ? self::MODE_ON_DEMAND : self::MODE_ALWAYS,
            ];
        }
        return $instructions;
    }
}
