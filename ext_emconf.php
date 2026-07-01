<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'AI Agent',
    'description' => 'TYPO3 extension that integrates an AI agent directly into TYPO3, using the MCP ToolRegistry for native tool execution with an OpenAI-compatible LLM API',
    'category' => 'module',
    'author' => 'Marco Pfeiffer, Kay Wienöbst',
    'author_email' => 'marco@hauptsache.net, kay@hauptsache.net',
    'state' => 'beta',
    'clearCacheOnLoad' => true,
    'version' => '0.1.1',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
            'php' => '8.1.0-8.4.99',
            'mcp_server' => '0.1.0-0.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
