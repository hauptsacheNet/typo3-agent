<?php

return [
    'ctrl' => [
        'title' => 'Agent Task',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'default_sortby' => 'crdate DESC',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'iconfile' => 'EXT:agent/Resources/Public/Icons/Extension.svg',
        'searchFields' => 'title,prompt',
    ],
    'types' => [
        '0' => [
            'showitem' => 'title, prompt, --div--;Status, status, --div--;Result, result, messages',
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
        'messages' => [
            'label' => 'Messages (JSON)',
            'config' => [
                'type' => 'json',
                'readOnly' => true,
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
    ],
];
