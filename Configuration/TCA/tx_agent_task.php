<?php

return [
    'ctrl' => [
        'title' => 'Agent Task',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'versioningWS_alwaysAllowLiveEdit' => true,
        'default_sortby' => 'crdate DESC',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'iconfile' => 'EXT:agent/Resources/Public/Icons/Extension.svg',
        'searchFields' => 'title,prompt',
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'title, prompt, workspace_id, --div--;Status, status, --div--;Result, result, --div--;Messages, messages',
        ],
    ],
    'columns' => [
        'title' => [
            'label' => 'Title',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'required' => true,
            ],
        ],
        'prompt' => [
            'label' => 'Prompt',
            'config' => [
                'type' => 'text',
                'rows' => 10,
                'required' => true,
            ],
        ],
        'status' => [
            'label' => 'Status',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'Pending', 'value' => 0],
                    ['label' => 'In Progress', 'value' => 1],
                    ['label' => 'Ended', 'value' => 2],
                    ['label' => 'Failed', 'value' => 3],
                ],
                'default' => 0,
            ],
        ],
        'result' => [
            'label' => 'Result',
            'config' => [
                'type' => 'text',
                'rows' => 15,
                'readOnly' => true,
            ],
        ],
        'workspace_id' => [
            'label' => 'Workspace',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        // Inline relation to tx_agent_message. This is *the* mechanism by
        // which DataHandler cascades soft-delete/undelete from the task to
        // its messages (and — via the messages' `attachments` type=file
        // field — transitively to sys_file_reference rows). No manual hook
        // needed; the DB rows are messaged by AgentMessageRepository::append().
        'messages' => [
            'label' => 'Messages',
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'tx_agent_message',
                'foreign_field' => 'task',
                'foreign_sortby' => 'sorting',
                'appearance' => [
                    'collapseAll' => true,
                    'expandSingle' => true,
                    'useSortable' => false,
                    'enabledControls' => [
                        'info' => true,
                        'new' => false,
                        'dragdrop' => false,
                        'sort' => false,
                        'hide' => false,
                        'delete' => false,
                        'localize' => false,
                    ],
                ],
                'behaviour' => [
                    'enableCascadingDelete' => true,
                ],
            ],
        ],
    ],
];
