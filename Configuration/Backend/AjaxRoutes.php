<?php

use Hn\Agent\Controller\ChatController;

return [
    'ai_agent_file_info' => [
        'path' => '/agent/file-info',
        'target' => ChatController::class . '::fileInfoAction',
    ],
];
