<?php

declare(strict_types=1);

namespace Hn\Agent\Tests\Functional\Service;

use Hn\Agent\Service\LlmService;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class LlmServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
        'agent',
    ];

    private function buildService(): LlmService
    {
        return new LlmService(
            GeneralUtility::makeInstance(RequestFactory::class),
            GeneralUtility::makeInstance(ExtensionConfiguration::class),
        );
    }

    public function testChatCompletionThrowsOnMissingApiKey(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['agent']['apiKey'] = '';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/apiKey is not configured/');

        $this->buildService()->chatCompletion([
            ['role' => 'user', 'content' => 'Hello'],
        ]);
    }

    public function testChatCompletionStreamThrowsOnMissingApiKey(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['agent']['apiKey'] = '';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/apiKey is not configured/');

        $this->buildService()->chatCompletionStream(
            [['role' => 'user', 'content' => 'Hello']],
            [],
        );
    }
}
