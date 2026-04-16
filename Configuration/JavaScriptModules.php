<?php

return [
    'dependencies' => [
        'backend',
        'core',
    ],
    'imports' => [
        '@hn/agent/' => 'EXT:agent/Resources/Public/JavaScript/',
        'marked' => 'EXT:agent/Resources/Public/JavaScript/vendor/marked.esm.js',
        'dompurify' => 'EXT:agent/Resources/Public/JavaScript/vendor/purify.es.mjs',
    ],
];
