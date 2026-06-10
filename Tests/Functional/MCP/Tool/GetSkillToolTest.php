<?php

declare(strict_types=1);

namespace Hn\Agent\Tests\Functional\MCP\Tool;

use Hn\Agent\Domain\AgentSkillRepository;
use Hn\Agent\MCP\Tool\GetSkillTool;
use Hn\Agent\Service\SkillTextFormatter;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class GetSkillToolTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
        'agent',
    ];

    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');
        $this->connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
    }

    private function createSkill(string $title, string $instruction, string $mode = 'on_demand', string $description = '', int $hidden = 0, int $sorting = 0): int
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_agent_skill');
        $connection->insert('tx_agent_skill', [
            'pid' => 0,
            'title' => $title,
            'description' => $description,
            'instruction' => $instruction,
            'mode' => $mode,
            'hidden' => $hidden,
            'sorting' => $sorting,
            'deleted' => 0,
            'crdate' => time(),
            'tstamp' => time(),
        ]);
        return (int)$connection->lastInsertId();
    }

    private function buildTool(): GetSkillTool
    {
        return new GetSkillTool(
            new AgentSkillRepository($this->connectionPool),
            new SkillTextFormatter(),
        );
    }

    private function firstText(\Mcp\Types\CallToolResult $result): string
    {
        self::assertNotEmpty($result->content);
        $block = $result->content[0];
        self::assertInstanceOf(TextContent::class, $block);
        return $block->text;
    }

    public function testNoArgumentsReturnsIndexOfOnDemandSkills(): void
    {
        $uid = $this->createSkill('News writing', '<p>Full body here.</p>', 'on_demand', 'When writing news', 0, 10);
        // "always" skills must not show up in the on-demand index.
        $this->createSkill('Global tone', 'Always polite.', 'always', '', 0, 20);

        $text = $this->firstText($this->buildTool()->execute([]));

        self::assertStringContainsString('News writing', $text);
        self::assertStringContainsString('When writing news', $text);
        self::assertStringContainsString('#' . $uid, $text);
        self::assertStringNotContainsString('Global tone', $text);
        // Index must not contain the full body.
        self::assertStringNotContainsString('Full body here', $text);
    }

    public function testIdsReturnFullConvertedBody(): void
    {
        $uid = $this->createSkill(
            'News writing',
            '<p>Use <strong>active</strong> voice.</p><ul><li>Short teaser</li></ul>',
            'on_demand',
            'When writing news',
        );

        $text = $this->firstText($this->buildTool()->execute(['ids' => [$uid]]));

        self::assertStringContainsString('News writing', $text);
        self::assertStringContainsString('**active**', $text);
        self::assertStringContainsString('- Short teaser', $text);
        self::assertStringNotContainsString('<p>', $text);
    }

    public function testQueryReturnsMatchingSkillBody(): void
    {
        $this->createSkill('News writing', 'Teaser rules for news articles.', 'on_demand', 'When writing news');
        $this->createSkill('Image captions', 'Caption rules.', 'on_demand', 'When adding images');

        $text = $this->firstText($this->buildTool()->execute(['query' => 'news']));

        self::assertStringContainsString('Teaser rules for news articles.', $text);
        self::assertStringNotContainsString('Caption rules.', $text);
    }

    public function testHiddenSkillIsNotReturnedByIds(): void
    {
        $uid = $this->createSkill('Hidden skill', 'Secret body.', 'on_demand', '', 1);

        $result = $this->buildTool()->execute(['ids' => [$uid]]);
        $text = $this->firstText($result);

        self::assertStringNotContainsString('Secret body.', $text);
        self::assertStringContainsString('No active skills found', $text);
    }
}
