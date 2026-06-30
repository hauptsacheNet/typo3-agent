<?php

use Hn\Agent\Controller\ChatController;
use Hn\Agent\Controller\ChatStreamController;

return [
    'web_typo3_agent_tasks' => [
        'parent' => 'web',
        'access' => 'user',
        'path' => '/module/web/typo3-agent-tasks',
        'iconIdentifier' => 'module-typo3-agent-tasks',
        'labels' => [
            'title' => 'LLL:EXT:agent/Resources/Private/Language/locallang.xlf:module.title',
            'description' => 'LLL:EXT:agent/Resources/Private/Language/locallang.xlf:module.description',
        ],
        'routes' => [
            '_default' => [
                'target' => ChatController::class . '::indexAction',
            ],
            'show' => [
                'target' => ChatController::class . '::showAction',
            ],
            'new' => [
                'target' => ChatController::class . '::newAction',
                'methods' => ['POST'],
            ],
            'streamMessage' => [
                'target' => ChatStreamController::class . '::streamMessageAction',
                'methods' => ['POST'],
            ],
            'cancelMessage' => [
                'target' => ChatStreamController::class . '::cancelMessageAction',
                'methods' => ['POST'],
            ],
        ],
    ],
];
