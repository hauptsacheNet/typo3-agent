<?php

use Hn\Agent\Controller\ChatController;

return [
    'ai_agent_attachment_preflight' => [
        'path' => '/agent/attachment-preflight',
        'target' => ChatController::class . '::attachmentPreflightAction',
    ],
];