<?php

defined('TYPO3') or die();

$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['agent'] ??= [];
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['agent'] = array_merge([
    'apiUrl' => 'https://openrouter.ai/api/v1/',
    'apiKey' => '',
    'model' => 'anthropic/claude-haiku-4-5',
    'systemPrompt' => 'You are a helpful TYPO3 CMS assistant. You have access to tools that let you read and modify TYPO3 pages, content, and database records. Use these tools to fulfill the user\'s request. Always verify your changes by reading the data back after writing.',
    'maxIterations' => 20,
], $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['agent']);
