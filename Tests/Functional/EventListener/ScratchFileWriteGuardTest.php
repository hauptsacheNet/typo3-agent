<?php

declare(strict_types=1);

namespace Hn\Agent\Tests\Functional\EventListener;

use Hn\Agent\EventListener\ScratchFileWriteGuard;
use Hn\Agent\Service\AgentScratchStorage;
use Hn\Agent\Service\ScratchFilePromotionService;
use Hn\McpServer\Event\BeforeRecordWriteEvent;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\EventDispatcher\EventDispatcher;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\DefaultUploadFolderResolver;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Deterministic coverage for the scratch-storage write guard: whenever
 * record data references a scratch sys_file via uid_local, the listener
 * must promote the file into a public storage and rewrite the reference —
 * except on tx_agent_message, whose references ARE the chat previews.
 */
class ScratchFileWriteGuardTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
        'agent',
    ];

    private AgentScratchStorage $scratchStorage;
    private ScratchFileWriteGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');

        // Public default storage first (uid 1, like production fileadmin),
        // scratch storage second — mirrors a real instance.
        GeneralUtility::mkdir_deep(Environment::getPublicPath() . '/fileadmin/user_upload');
        GeneralUtility::makeInstance(StorageRepository::class)
            ->createLocalStorage('fileadmin', 'fileadmin/', 'relative', '', true);

        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
        $this->scratchStorage = new AgentScratchStorage(
            GeneralUtility::makeInstance(StorageRepository::class),
            $resourceFactory,
            $this->getConnectionPool(),
        );
        $this->guard = new ScratchFileWriteGuard(
            new ScratchFilePromotionService(
                $this->scratchStorage,
                $resourceFactory,
                new DefaultUploadFolderResolver($resourceFactory, GeneralUtility::makeInstance(EventDispatcher::class)),
            ),
            $resourceFactory,
        );
    }

    public function testPromotesScratchFileReferencedInlineAndRewritesUidLocal(): void
    {
        $scratchFile = $this->scratchStorage->store(1, 'scratch-bytes', 'text/plain');

        $event = new BeforeRecordWriteEvent('tt_content', 'create', [
            'CType' => 'image',
            'header' => 'Bildtest',
            'image' => [['uid_local' => $scratchFile->getUid(), 'alternative' => 'Alt']],
        ], null, 1);

        ($this->guard)($event);

        self::assertFalse($event->isVetoed(), (string)$event->getVetoReason());
        $rewritten = (int)$event->getData()['image'][0]['uid_local'];
        self::assertNotSame($scratchFile->getUid(), $rewritten, 'uid_local was not rewritten to the promoted copy.');

        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
        $promoted = $resourceFactory->getFileObject($rewritten);
        self::assertFalse($this->scratchStorage->isScratchFile($promoted), 'Promoted file still lives in scratch.');
        self::assertSame('scratch-bytes', $promoted->getContents());

        // Copy, not move: the original stays for chat-history preview.
        $original = $resourceFactory->getFileObject($scratchFile->getUid());
        self::assertTrue($this->scratchStorage->isScratchFile($original));

        // Untouched sibling data survives.
        self::assertSame('Alt', $event->getData()['image'][0]['alternative']);
    }

    public function testLeavesPublicFilesAlone(): void
    {
        $scratchFile = $this->scratchStorage->store(1, 'promote-me', 'text/plain');
        $event = new BeforeRecordWriteEvent('tt_content', 'create', [
            'image' => [['uid_local' => $scratchFile->getUid()]],
        ], null, 1);
        ($this->guard)($event);
        $publicUid = (int)$event->getData()['image'][0]['uid_local'];

        // Second write referencing the already-public file must not touch it.
        $secondEvent = new BeforeRecordWriteEvent('tt_content', 'create', [
            'image' => [['uid_local' => $publicUid]],
        ], null, 1);
        ($this->guard)($secondEvent);

        self::assertSame($publicUid, (int)$secondEvent->getData()['image'][0]['uid_local']);
        self::assertFalse($secondEvent->isVetoed());
    }

    public function testPromotesDirectSysFileReferenceWrites(): void
    {
        $scratchFile = $this->scratchStorage->store(1, 'direct-reference', 'text/plain');

        $event = new BeforeRecordWriteEvent('sys_file_reference', 'create', [
            'uid_local' => $scratchFile->getUid(),
            'tablenames' => 'tt_content',
            'fieldname' => 'image',
        ], null, 1);

        ($this->guard)($event);

        self::assertFalse($event->isVetoed(), (string)$event->getVetoReason());
        $rewritten = (int)$event->getData()['uid_local'];
        self::assertNotSame($scratchFile->getUid(), $rewritten);
        $promoted = GeneralUtility::makeInstance(ResourceFactory::class)->getFileObject($rewritten);
        self::assertFalse($this->scratchStorage->isScratchFile($promoted));
    }

    public function testSkipsAgentMessageReferences(): void
    {
        $scratchFile = $this->scratchStorage->store(1, 'chat-preview', 'text/plain');

        $event = new BeforeRecordWriteEvent('sys_file_reference', 'create', [
            'uid_local' => $scratchFile->getUid(),
            'tablenames' => 'tx_agent_message',
            'fieldname' => 'attachments',
        ], null, 1);

        ($this->guard)($event);

        self::assertFalse($event->isVetoed());
        self::assertSame($scratchFile->getUid(), (int)$event->getData()['uid_local'], 'Chat-preview references must stay in scratch.');
    }

    public function testVetoesWhenPromotionIsImpossible(): void
    {
        $scratchFile = $this->scratchStorage->store(1, 'no-target', 'text/plain');

        // Without a BE user there is no default upload folder to promote into.
        $backendUser = $GLOBALS['BE_USER'];
        unset($GLOBALS['BE_USER']);
        try {
            $event = new BeforeRecordWriteEvent('tt_content', 'create', [
                'image' => [['uid_local' => $scratchFile->getUid()]],
            ], null, 1);
            ($this->guard)($event);
        } finally {
            $GLOBALS['BE_USER'] = $backendUser;
        }

        self::assertTrue($event->isVetoed(), 'Expected a veto when the scratch file cannot be promoted.');
        self::assertStringContainsString('Scratch-Storage', (string)$event->getVetoReason());
    }

    public function testIgnoresIrrelevantWrites(): void
    {
        $event = new BeforeRecordWriteEvent('pages', 'create', [
            'title' => 'Nur Text',
            'hidden' => 0,
        ], null, 1);

        ($this->guard)($event);

        self::assertFalse($event->isVetoed());
        self::assertSame('Nur Text', $event->getData()['title']);
    }

    public function testGuardIsRegisteredForBeforeRecordWriteEvent(): void
    {
        // The listener only protects anything if it is actually wired into
        // the event dispatcher — guard against a lost registration.
        $scratchFile = $this->scratchStorage->store(1, 'via-dispatcher', 'text/plain');
        $event = new BeforeRecordWriteEvent('tt_content', 'create', [
            'image' => [['uid_local' => $scratchFile->getUid()]],
        ], null, 1);

        $this->get(\Psr\EventDispatcher\EventDispatcherInterface::class)->dispatch($event);

        self::assertNotSame(
            $scratchFile->getUid(),
            (int)$event->getData()['image'][0]['uid_local'],
            'ScratchFileWriteGuard does not seem to be registered as an event listener.',
        );
    }
}
