<?php

use Hn\Agent\Controller\ChatController;

return [
    'ai_agent_chat' => [
        'parent' => 'web',
        'access' => 'user',
        'path' => '/module/web/ai-agent-chat',
        'iconIdentifier' => 'module-ai-agent-chat',
        'labels' => [
            'title' => 'AI Chat',
            'description' => 'Chat with the AI agent using TYPO3 tools',
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
