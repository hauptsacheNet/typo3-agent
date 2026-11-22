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
     * @return object{build: callable(array, array, bool, ?string): array}
     */
    private function buildService(): object
    {
        return new class(
            GeneralUtility::makeInstance(RequestFactory::class),
            GeneralUtility::makeInstance(ExtensionConfiguration::class),
        ) extends LlmService {
            public function build(array $messages, array $tools, bool $stream, ?string $modelOverride = null): array
            {
                return $this->buildRequestBody($messages, $tools, $stream, $modelOverride);
            }
        };
    }

    public function testWebFetchServerToolIsAppendedWhenEnabled(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['agent']['apiKey'] = 'test-key';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['agent']['apiUrl'] = 'https://openrouter.ai/api/v1/';
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
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['agent']['apiUrl'] = 'https://openrouter.ai/api/v1/';
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
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['agent']['apiUrl'] = 'https://openrouter.ai/api/v1/';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['agent']['webFetch'] = '0';

        $body = $this->buildService()->build(
            [['role' => 'user', 'content' => 'Hi']],
            [],
            false,
        );

        self::assertArrayNotHasKey('tools', $body);
    }

    public function testConfiguredModelIsPassedThroughVerbatim(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['agent']['apiKey'] = 'test-key';
        // Free-text field: any provider model ID must be accepted, not only a curated list.
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['agent']['model'] = 'mistralai/mistral-large-4-0';

        $body = $this->buildService()->build([['role' => 'user', 'content' => 'Hi']], [], false);

        self::assertSame('mistralai/mistral-large-4-0', $body['model']);
    }

    public function testEmptyModelFallsBackToDefault(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['agent']['apiKey'] = 'test-key';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['agent']['model'] = '  ';

        $body = $this->buildService()->build([['role' => 'user', 'content' => 'Hi']], [], false);

        self::assertSame(LlmService::DEFAULT_MODEL, $body['model']);
    }

    public function testDefaultModelMatchesExtConfTemplate(): void
    {
        $template = (string)file_get_contents(dirname(__DIR__, 3) . '/ext_conf_template.txt');

        self::assertMatchesRegularExpression(
            '/^model = ' . preg_quote(LlmService::DEFAULT_MODEL, '/') . '$/m',
            $template,
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function providersWithoutServerToolsProvider(): array
    {
        return [
            'OpenAI' => ['https://api.openai.com/v1/'],
            'Mistral' => ['https://api.mistral.ai/v1/'],
            'Cortecs' => ['https://api.cortecs.ai/v1/'],
            // Regional OpenRouter endpoints reject web_fetch with HTTP 403.
            'OpenRouter EU' => ['https://eu.openrouter.ai/api/v1/'],
            'unknown / self-hosted' => ['http://localhost:1234/v1/'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('providersWithoutServerToolsProvider')]
    public function testWebFetchIsNotSentToProvidersWithoutIt(string $apiUrl): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['agent']['apiKey'] = 'test-key';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['agent']['apiUrl'] = $apiUrl;
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['agent']['webFetch'] = '1';

        $body = $this->buildService()->build([['role' => 'user', 'content' => 'Hi']], [], false);

        self::assertArrayNotHasKey('tools', $body);
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function reasoningFormatProvider(): array
    {
        return [
            'OpenRouter' => ['https://openrouter.ai/api/v1/', ['reasoning' => ['effort' => 'high']]],
            'OpenRouter EU' => ['https://eu.openrouter.ai/api/v1/', ['reasoning' => ['effort' => 'high']]],
            'OpenAI' => ['https://api.openai.com/v1/', ['reasoning_effort' => 'high']],
            'Mistral' => ['https://api.mistral.ai/v1/', ['reasoning_effort' => 'high']],
            'Cortecs' => ['https://api.cortecs.ai/v1/', ['reasoning_effort' => 'high']],
            'unknown / self-hosted' => ['http://localhost:1234/v1/', ['reasoning_effort' => 'high']],
        ];
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('reasoningFormatProvider')]
    public function testReasoningEffortUsesProviderFormat(string $apiUrl, array $expected): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['agent']['apiKey'] = 'test-key';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['agent']['apiUrl'] = $apiUrl;
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['agent']['reasoningEffort'] = 'high';

        $body = $this->buildService()->build([['role' => 'user', 'content' => 'Hi']], [], false);

        foreach ($expected as $key => $value) {
            self::assertSame($value, $body[$key] ?? null);
        }
        // Never both formats at once — strict providers reject unknown parameters.
        self::assertFalse(isset($body['reasoning'], $body['reasoning_effort']));
    }

    public function testReasoningOffSendsNoReasoningParameter(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['agent']['apiKey'] = 'test-key';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['agent']['apiUrl'] = 'https://api.openai.com/v1/';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['agent']['reasoningEffort'] = 'off';

        $body = $this->buildService()->build([['role' => 'user', 'content' => 'Hi']], [], false);

        self::assertArrayNotHasKey('reasoning', $body);
        self::assertArrayNotHasKey('reasoning_effort', $body);
    }
}
