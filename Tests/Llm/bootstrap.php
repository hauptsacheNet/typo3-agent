<?php

declare(strict_types=1);

/**
 * Bootstrap for the LLM test suite: TYPO3 functional-test bootstrap plus a
 * small .env.local loader so OPENROUTER_API_KEY can live outside the shell.
 */

require_once __DIR__ . '/../../vendor/typo3/testing-framework/Resources/Core/Build/FunctionalTestsBootstrap.php';
require_once __DIR__ . '/../../vendor/autoload.php';

// Simple .env.local loader (KEY=VALUE, quotes optional, # comments)
(static function () {
    $envFile = __DIR__ . '/../../.env.local';
    if (!file_exists($envFile)) {
        return;
    }
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }
        if (preg_match('/^([A-Z_]+)\s*=\s*(.*)$/', $line, $matches)) {
            $key = $matches[1];
            $value = trim($matches[2]);
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }
            if (!getenv($key)) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }
})();
