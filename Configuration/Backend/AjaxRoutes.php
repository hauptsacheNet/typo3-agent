<?php

use Hn\Agent\Controller\AttachmentController;
use Hn\Agent\Controller\ModelListController;

return [
    'typo3_agent_tasks_attachment_preflight' => [
        'path' => '/typo3-agent-tasks/attachment-preflight',
        'target' => AttachmentController::class . '::preflightAction',
    ],
    'typo3_agent_config_models' => [
        'path' => '/typo3-agent-config/models',
        'target' => ModelListController::class . '::listAction',
    ],
];