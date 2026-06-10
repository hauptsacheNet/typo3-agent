<?php

declare(strict_types=1);

/**
 * Editor-maintainable instructions for the AI agent.
 *
 * Each record is a piece of editorial guidance (tone of voice, how to handle
 * certain content elements/records, …) that gets appended to the agent's
 * system prompt when a new chat/task is started — see
 * \Hn\Agent\Service\AgentService::buildInitialMessages().
 *
 * Access control is left to native TYPO3 permissions: keep the records in a
 * dedicated folder and grant the relevant backend group "modify" rights on
 * this table (and access to that folder) via the group's Access Lists. That
 * way only that group can edit the instructions, while everyone else can
 * still read them (List module + chat info panel).
 */
return [
    'ctrl' => [
        'title' => 'LLL:EXT:agent/Resources/Private/Language/locallang.xlf:tx_agent_instruction',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'sortby' => 'sorting',
        'default_sortby' => 'sorting ASC',
        'enablecolumns' => [
            'disabled' => 'hidden',
            'starttime' => 'starttime',
            'endtime' => 'endtime',
        ],
        'iconfile' => 'EXT:agent/Resources/Public/Icons/Extension.svg',
        'searchFields' => 'title,instruction',
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'title, instruction, --div--;LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.access, hidden, starttime, endtime',
        ],
    ],
    'columns' => [
        'hidden' => [
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.hidden',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
            ],
        ],
        'starttime' => [
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.starttime',
            'config' => [
                'type' => 'datetime',
                'default' => 0,
            ],
        ],
        'endtime' => [
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.endtime',
            'config' => [
                'type' => 'datetime',
                'default' => 0,
                'range' => [
                    'upper' => mktime(0, 0, 0, 1, 1, 2106),
                ],
            ],
        ],
        'title' => [
            'label' => 'LLL:EXT:agent/Resources/Private/Language/locallang.xlf:tx_agent_instruction.title',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'required' => true,
            ],
        ],
        'instruction' => [
            'label' => 'LLL:EXT:agent/Resources/Private/Language/locallang.xlf:tx_agent_instruction.instruction',
            'description' => 'LLL:EXT:agent/Resources/Private/Language/locallang.xlf:tx_agent_instruction.instruction.description',
            'config' => [
                'type' => 'text',
                'rows' => 8,
                'required' => true,
            ],
        ],
    ],
];
