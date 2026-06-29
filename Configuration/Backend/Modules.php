<?php

use Hn\Agent\Controller\ChatController;

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
            'sendMessage' => [
                'target' => ChatController::class . '::sendMessageAction',
                'methods' => ['POST'],
            ],
            'streamMessage' => [
                'target' => ChatController::class . '::streamMessageAction',
                'methods' => ['POST'],
            ],
            'cancelMessage' => [
                'target' => ChatController::class . '::cancelMessageAction',
                'methods' => ['POST'],
            ],
            'switchWorkspace' => [
                'target' => ChatController::class . '::switchWorkspaceAction',
            ],
        ],
    ],
];
