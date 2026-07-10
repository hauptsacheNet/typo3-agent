<?php

use Hn\Agent\Middleware\ExtensionConfigAssetLoader;

return [
    'backend' => [
        'hn/agent/extension-config-asset-loader' => [
            'target' => ExtensionConfigAssetLoader::class,
            'after' => [
                'typo3/cms-backend/authentication',
            ],
        ],
    ],
];
