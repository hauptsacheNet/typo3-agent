<?php

declare(strict_types=1);

namespace Hn\Agent\Tests\Functional\Renderer;

use Hn\Agent\Renderer\PromptRenderer;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Users who cannot start an agent task get no new-task prompt at all: the
 * agent only writes in workspaces, and the form posts to the AI Tasks module,
 * which the core blocks for users without access to it.
 */
class PromptRendererTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
        'agent',
    ];

    private const EDITOR_UID = 2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('be_users')
            ->insert('be_users', [
                'uid' => self::EDITOR_UID,
                'pid' => 0,
                'username' => 'editor',
                'password' => 'not-used',
                'admin' => 0,
                'workspace_perms' => 1,
                'userMods' => 'web_typo3_agent_tasks',
            ]);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');
    }

    private function createWorkspace(string $members): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_workspace')
            ->insert('sys_workspace', ['uid' => 5, 'pid' => 0, 'title' => 'AI drafts', 'members' => $members]);
    }

    private function renderer(): PromptRenderer
    {
        return $this->get(PromptRenderer::class);
    }

    public function testEditorNotAssignedToAnyWorkspaceGetsNoPrompt(): void
    {
        $this->createWorkspace('');
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(self::EDITOR_UID);

        self::assertFalse($this->renderer()->canStartTask());
        self::assertSame('', $this->renderer()->renderHtml(1, 'Ask the agent'));
    }

    public function testAdminWithoutAnyExistingWorkspaceGetsNoPrompt(): void
    {
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);

        self::assertFalse($this->renderer()->canStartTask());
        self::assertSame('', $this->renderer()->renderHtml(1, 'Ask the agent'));
    }

    public function testEditorAssignedToWorkspaceButInLiveGetsPrompt(): void
    {
        $this->createWorkspace('be_users_' . self::EDITOR_UID);
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(self::EDITOR_UID);

        self::assertTrue($this->renderer()->canStartTask());
        self::assertStringContainsString('workspace-id="0"', $this->renderer()->renderHtml(1, 'Ask the agent'));
    }

    public function testEditorInsideWorkspaceGetsPrompt(): void
    {
        $this->createWorkspace('be_users_' . self::EDITOR_UID);
        $backendUser = $this->setUpBackendUser(self::EDITOR_UID);
        $backendUser->setWorkspace(5);
        $GLOBALS['BE_USER'] = $backendUser;

        self::assertTrue($this->renderer()->canStartTask());
        self::assertStringContainsString('workspace-id="5"', $this->renderer()->renderHtml(1, 'Ask the agent'));
    }

    public function testEditorWithoutAccessToTaskModuleGetsNoPrompt(): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('be_users')
            ->update('be_users', ['userMods' => 'web_layout'], ['uid' => self::EDITOR_UID]);
        $this->createWorkspace('be_users_' . self::EDITOR_UID);
        $backendUser = $this->setUpBackendUser(self::EDITOR_UID);
        $backendUser->setWorkspace(5);
        $GLOBALS['BE_USER'] = $backendUser;

        self::assertFalse($this->renderer()->canStartTask());
        self::assertSame('', $this->renderer()->renderHtml(1, 'Ask the agent'));
    }
}
