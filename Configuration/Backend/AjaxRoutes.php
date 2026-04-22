<?php

use Hn\Agent\Controller\ModelFetchController;

return [
    'agent_fetch_models' => [
        'path' => '/agent/fetch-models',
        'target' => ModelFetchController::class . '::fetchAction',
    ],
];
