<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'Agent Message',
        'label' => 'role',
        'label_alt' => 'content',
        'label_alt_force' => true,
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'sortby' => 'sorting',
        'versioningWS_alwaysAllowLiveEdit' => true,
        'iconfile' => 'EXT:agent/Resources/Public/Icons/Extension.svg',
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
        'hideTable' => true,
    ],
    'types' => [
        '0' => [
            'showitem' => 'task, role, content, reasoning, tool_calls, tool_call_id, tool_name, attachments',
        ],
    ],
    'columns' => [
        'task' => [
            'label' => 'Task',
            'config' => [
                'type' => 'group',
                'allowed' => 'tx_agent_task',
                'size' => 1,
                'minitems' => 1,
                'maxitems' => 1,
                'readOnly' => true,
            ],
        ],
        'role' => [
            'label' => 'Role',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'User', 'value' => 'user'],
                    ['label' => 'Assistant', 'value' => 'assistant'],
                    ['label' => 'System', 'value' => 'system'],
                    ['label' => 'Tool', 'value' => 'tool'],
                ],
                'readOnly' => true,
            ],
        ],
        'content' => [
            'label' => 'Content',
            'config' => [
                'type' => 'text',
                'rows' => 10,
                'readOnly' => true,
            ],
        ],
        'reasoning' => [
            'label' => 'Reasoning',
            'config' => [
                'type' => 'text',
                'rows' => 6,
                'readOnly' => true,
            ],
        ],
        'tool_calls' => [
            'label' => 'Tool Calls (JSON)',
            'config' => [
                'type' => 'json',
                'readOnly' => true,
            ],
        ],
        'tool_call_id' => [
            'label' => 'Tool Call ID',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'tool_name' => [
            'label' => 'Tool Name',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'attachments' => [
            'label' => 'Attachments',
            'config' => [
                'type' => 'file',
                'allowed' => '',
                'appearance' => [
                    'createNewRelationLinkTitle' => 'Add attachment',
                ],
            ],
        ],
    ],
];
