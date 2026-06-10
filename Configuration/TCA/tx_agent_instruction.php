<?php

declare(strict_types=1);

/**
 * Editor-maintainable "instructions" for the AI agent — packaged guidance
 * following the progressive-disclosure idea of the SKILL.md open standard
 * (agentskills.io). Each record carries a name, a short "when to use" hint,
 * and a rich-text body.
 *
 * Two modes:
 *  - always:    the body is appended to the agent's system prompt for every
 *               new chat/task (global base rules).
 *  - on_demand: only the name + "when to use" hint go into the prompt as an
 *               index; the agent loads the full body on demand via the
 *               GetInstruction tool. Keeps the prompt small and scales to many
 *               instructions.
 *
 * See \Hn\Agent\Service\AgentService::buildInstructionsSection() (prompt) and
 * \Hn\Agent\MCP\Tool\GetInstructionTool (on-demand retrieval).
 *
 * Access control is left to native TYPO3 permissions: keep the records in a
 * dedicated folder and grant the relevant backend group "modify" rights on
 * this table (and access to that folder) via the group's Access Lists. That
 * way only that group can edit the instructions, while everyone else can still
 * read them (List module + chat info panel).
 */
return [
    'ctrl' => [
        'title' => 'LLL:EXT:agent/Resources/Private/Language/locallang.xlf:tx_agent_instruction',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'sortby' => 'sorting',
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
            'showitem' => 'title, mode, description, instruction, --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access, hidden, starttime, endtime',
        ],
    ],
    'columns' => [
        'title' => [
            'label' => 'LLL:EXT:agent/Resources/Private/Language/locallang.xlf:tx_agent_instruction.title',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'required' => true,
            ],
        ],
        'mode' => [
            'label' => 'LLL:EXT:agent/Resources/Private/Language/locallang.xlf:tx_agent_instruction.mode',
            'description' => 'LLL:EXT:agent/Resources/Private/Language/locallang.xlf:tx_agent_instruction.mode.description',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'LLL:EXT:agent/Resources/Private/Language/locallang.xlf:tx_agent_instruction.mode.always', 'value' => 'always'],
                    ['label' => 'LLL:EXT:agent/Resources/Private/Language/locallang.xlf:tx_agent_instruction.mode.on_demand', 'value' => 'on_demand'],
                ],
                'default' => 'always',
            ],
        ],
        'description' => [
            'label' => 'LLL:EXT:agent/Resources/Private/Language/locallang.xlf:tx_agent_instruction.description',
            'description' => 'LLL:EXT:agent/Resources/Private/Language/locallang.xlf:tx_agent_instruction.description.description',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
            ],
        ],
        'instruction' => [
            'label' => 'LLL:EXT:agent/Resources/Private/Language/locallang.xlf:tx_agent_instruction.instruction',
            'description' => 'LLL:EXT:agent/Resources/Private/Language/locallang.xlf:tx_agent_instruction.instruction.description',
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
