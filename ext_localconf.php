<?php

declare(strict_types=1);

// Load libraries bundled for the TER distribution when no composer autoloader
// has already provided them (e.g. non-composer TYPO3 installations).
$bundledAutoload = __DIR__ . '/Resources/Private/PHP/vendor/autoload.php';
if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class) && is_file($bundledAutoload)) {
    require_once $bundledAutoload;
}

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][]
    = \Hn\Agent\Hook\DataHandlerHook::class;

$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['agent_models'] ??= [
    'backend' => \TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend::class,
    'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
    'options' => ['defaultLifetime' => 3600],
    'groups' => ['system'],
];
