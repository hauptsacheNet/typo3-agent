<?php

declare(strict_types=1);

namespace Hn\Agent\Tests\Functional\Service;

use Hn\Agent\Service\LlmService;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Exercises LlmService::buildRequestBody() — specifically the injection of
 * OpenRouter's server-side tools. The method is protected, so we expose it via
 * an anonymous subclass. No live HTTP or DB access.
 */
class LlmServiceRequestBodyTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
        'agent',
    ];

    /**
     * @return object{build: callable(array, array, bool, ?string, ?string): array}
     */
    private function buildService(): object
    {
        return new class(
            GeneralUtility::makeInstance(RequestFactory::class),
            GeneralUtility::makeInstance(ExtensionConfiguration::class),
        ) extends LlmService {
            public function build(array $messages, array $tools, bool $stream, ?string $modelOverride = null, ?string $sessionId = null): array
            {
                return $this->buildRequestBody($messages, $tools, $stream, $modelOverride, $sessionId);
            }
        };
    }

    public function testWebFetchServerToolIsAppendedWhenEnabled(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['agent']['apiKey'] = 'test-key';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['agent']['webFetch'] = '1';

        $functionTool = [
            'type' => 'function',
            'function' => ['name' => 'GetPage', 'parameters' => ['type' => 'object']],
        ];

        $body = $this->buildService()->build(
            [['role' => 'user', 'content' => 'Hi']],
            [$functionTool],
            false,
        );

        self::assertContains(['type' => 'openrouter:web_fetch'], $body['tools']);
        // The existing function tool must still be present alongside it.
        self::assertContains($functionTool, $body['tools']);
    }

    public function testWebFetchServerToolIsAppendedEvenWithoutFunctionTools(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['agent']['apiKey'] = 'test-key';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['agent']['webFetch'] = '1';

        $body = $this->buildService()->build(
            [['role' => 'user', 'content' => 'Hi']],
            [],
            false,
        );

        self::assertSame([['type' => 'openrouter:web_fetch']], $body['tools']);
    }

    public function testWebFetchServerToolIsOmittedWhenDisabled(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['agent']['apiKey'] = 'test-key';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['agent']['webFetch'] = '0';

        $body = $this->buildService()->build(
            [['role' => 'user', 'content' => 'Hi']],
            [],
            false,
        );

        self::assertArrayNotHasKey('tools', $body);
    }

    public function testSessionIdIsSentWhenProvided(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['agent']['apiKey'] = 'test-key';

        $body = $this->buildService()->build(
            [['role' => 'user', 'content' => 'Hi']],
            [],
            true,
            null,
            'task-42',
        );

        self::assertSame('task-42', $body['session_id']);
    }

    public function testSessionIdIsOmittedWhenNullOrEmpty(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['agent']['apiKey'] = 'test-key';
        $service = $this->buildService();

        self::assertArrayNotHasKey('session_id', $service->build([['role' => 'user', 'content' => 'Hi']], [], false));
        self::assertArrayNotHasKey('session_id', $service->build([['role' => 'user', 'content' => 'Hi']], [], false, null, ''));
    }

    public function testSessionIdIsTruncatedTo256Characters(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['agent']['apiKey'] = 'test-key';

        $body = $this->buildService()->build(
            [['role' => 'user', 'content' => 'Hi']],
            [],
            false,
            null,
            str_repeat('a', 300),
        );

        self::assertSame(256, mb_strlen($body['session_id']));
    }
}
