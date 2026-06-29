<?php

use Hn\Agent\Controller\ChatController;

return [
    'typo3_agent_tasks_attachment_preflight' => [
        'path' => '/typo3-agent-tasks/attachment-preflight',
        'target' => ChatController::class . '::attachmentPreflightAction',
    ],
];