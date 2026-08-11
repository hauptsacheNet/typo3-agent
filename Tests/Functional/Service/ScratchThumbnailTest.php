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
 * Proves that an image stored in the non-public agent scratch storage can be
 * turned into a downscaled thumbnail server-side (GraphicsMagick). This is the
 * backbone of the image-choice thumbnail delivery: the chat frontend requests
 * thumbnails for scratch sys_file UIDs via Core's /typo3/thumbnails endpoint,
 * which performs exactly this ProcessedFile processing. `is_public=0` only
 * blocks a public URL — reading/processing bytes through the backend works.
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

    protected array $configurationToUseInTestInstance = [
        'GFX' => [
            'processor' => 'GraphicsMagick',
            'processor_enabled' => true,
            'processor_effects' => false,
            'processor_path' => '/usr/bin/',
        ],
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

    public function testScratchImageCanBeThumbnailed(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD extension not available to synthesize a test PNG.');
        }

        // A 200x150 PNG — larger than the target so scaling actually happens.
        $image = imagecreatetruecolor(200, 150);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 40, 40));
        ob_start();
        imagepng($image);
        $pngBytes = (string)ob_get_clean();
        imagedestroy($image);
        self::assertNotSame('', $pngBytes, 'Failed to synthesize a PNG for the test.');

        $file = $this->scratchStorage->store(taskUid: 1, binary: $pngBytes, mimeType: 'image/png');
        self::assertTrue($this->scratchStorage->isScratchFile($file), 'Stored file should live in the scratch storage.');
        self::assertFalse($file->getStorage()->isPublic(), 'Scratch storage is expected to be non-public.');

        $processed = $file->process(ProcessedFile::CONTEXT_IMAGECROPSCALEMASK, ['maxWidth' => 80]);

        self::assertTrue($processed->exists(), 'Processed thumbnail file should exist on disk.');
        $width = (int)$processed->getProperty('width');
        self::assertGreaterThan(0, $width, 'Thumbnail should have a positive width.');
        self::assertLessThanOrEqual(80, $width, 'Thumbnail should be scaled down to the requested maxWidth.');
        self::assertNotSame('', $processed->getContents(), 'Thumbnail bytes should be readable server-side.');
    }
}
