<?php

use Hn\Agent\Controller\ChatController;

return [
    'ai_agent_chat' => [
        'parent' => 'web',
        'access' => 'user',
        'path' => '/module/web/ai-agent-chat',
        'iconIdentifier' => 'module-ai-agent-chat',
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
        ],
    ],
];
