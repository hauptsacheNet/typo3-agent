<?php

declare(strict_types=1);

namespace Hn\Agent\Tests\Functional\Service;

use Hn\Agent\Service\AgentScratchStorage;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Proves that an image stored in the non-public agent scratch storage is
 * resolvable and its bytes are readable server-side through the FAL image
 * processing pipeline. This is the backbone of the image-choice thumbnail
 * delivery: the chat frontend requests thumbnails for scratch sys_file UIDs
 * via Core's /typo3/thumbnails endpoint, which streams exactly this processed
 * file's bytes. `is_public=0` only blocks a public URL — the backend still
 * reads/serves the bytes (the scratch storage is permission-unlocked for every
 * StorageRepository consumer via AgentScratchStorageInitializer).
 */
class ScratchThumbnailTest extends FunctionalTestCase
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');

        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['BE_USER'] = $backendUser;
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');

        $this->scratchStorage = new AgentScratchStorage(
            GeneralUtility::makeInstance(StorageRepository::class),
            GeneralUtility::makeInstance(ResourceFactory::class),
            GeneralUtility::makeInstance(ConnectionPool::class),
        );
    }

    public function testScratchImageIsServableAsThumbnail(): void
    {
        $pngBytes = (string)file_get_contents(__DIR__ . '/../Fixtures/Files/image.png');
        self::assertNotSame('', $pngBytes, 'Fixture PNG should be readable.');

        $file = $this->scratchStorage->store(taskUid: 1, binary: $pngBytes, mimeType: 'image/png');
        self::assertTrue($this->scratchStorage->isScratchFile($file), 'Stored file should live in the scratch storage.');
        self::assertFalse($file->getStorage()->isPublic(), 'Scratch storage is expected to be non-public.');

        // Run the same processing the /typo3/thumbnails endpoint performs. We
        // assert only what is deterministic across environments: the non-public
        // scratch image resolves and its bytes are readable server-side. The
        // actual pixel downscaling depends on GraphicsMagick/ImageMagick being
        // installed — that is TYPO3's concern, not the scratch storage's, and
        // is deliberately not asserted here.
        $processed = $file->process(ProcessedFile::CONTEXT_IMAGECROPSCALEMASK, ['maxWidth' => 80]);

        self::assertTrue($processed->exists(), 'Processed thumbnail file should exist on disk.');
        self::assertGreaterThan(0, (int)$processed->getProperty('width'), 'Thumbnail should have a positive width.');
        self::assertNotSame('', $processed->getContents(), 'Thumbnail bytes should be readable server-side.');
    }
}
