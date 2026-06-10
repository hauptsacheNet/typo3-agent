<?php

declare(strict_types=1);

/**
 * Editor-maintainable "skills" for the AI agent — packaged guidance following
 * the progressive-disclosure idea of the SKILL.md open standard
 * (agentskills.io). Each record carries a name, a short "when to use" hint,
 * and a rich-text body.
 *
 * Two modes:
 *  - always:    the body is appended to the agent's system prompt for every
 *               new chat/task (global base rules).
 *  - on_demand: only the name + "when to use" hint go into the prompt as an
 *               index; the agent loads the full body on demand via the
 *               GetSkill tool. Keeps the prompt small and scales to many
 *               skills.
 *
 * See \Hn\Agent\Service\AgentService::buildSkillsSection() (prompt) and
 * \Hn\Agent\MCP\Tool\GetSkillTool (on-demand retrieval).
 *
 * Access control is left to native TYPO3 permissions: keep the records in a
 * dedicated folder and grant the relevant backend group "modify" rights on
 * this table (and access to that folder) via the group's Access Lists. That
 * way only that group can edit the skills, while everyone else can still read
 * them (List module + chat info panel).
 */
return [
    'ctrl' => [
        'title' => 'LLL:EXT:agent/Resources/Private/Language/locallang.xlf:tx_agent_skill',
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
        'searchFields' => 'title,description,instruction',
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'title, mode, description, instruction, --div--;LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.access, hidden, starttime, endtime',
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
            'label' => 'LLL:EXT:agent/Resources/Private/Language/locallang.xlf:tx_agent_skill.title',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'required' => true,
            ],
        ],
        'mode' => [
            'label' => 'LLL:EXT:agent/Resources/Private/Language/locallang.xlf:tx_agent_skill.mode',
            'description' => 'LLL:EXT:agent/Resources/Private/Language/locallang.xlf:tx_agent_skill.mode.description',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'LLL:EXT:agent/Resources/Private/Language/locallang.xlf:tx_agent_skill.mode.always', 'value' => 'always'],
                    ['label' => 'LLL:EXT:agent/Resources/Private/Language/locallang.xlf:tx_agent_skill.mode.on_demand', 'value' => 'on_demand'],
                ],
                'default' => 'always',
            ],
        ],
        'description' => [
            'label' => 'LLL:EXT:agent/Resources/Private/Language/locallang.xlf:tx_agent_skill.description',
            'description' => 'LLL:EXT:agent/Resources/Private/Language/locallang.xlf:tx_agent_skill.description.description',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
            ],
        ],
        'instruction' => [
            'label' => 'LLL:EXT:agent/Resources/Private/Language/locallang.xlf:tx_agent_skill.instruction',
            'description' => 'LLL:EXT:agent/Resources/Private/Language/locallang.xlf:tx_agent_skill.instruction.description',
            'config' => [
                'type' => 'text',
                'enableRichtext' => true,
                'richtextConfiguration' => 'default',
                'rows' => 10,
                'required' => true,
            ],
        ],
    ],
];
