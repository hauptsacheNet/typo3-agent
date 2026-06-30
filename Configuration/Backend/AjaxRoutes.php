<?php

use Hn\Agent\Controller\ChatStreamController;

return [
    'typo3_agent_tasks_attachment_preflight' => [
        'path' => '/typo3-agent-tasks/attachment-preflight',
        'target' => ChatStreamController::class . '::attachmentPreflightAction',
    ],
];