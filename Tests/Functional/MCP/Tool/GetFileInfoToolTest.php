<?php

declare(strict_types=1);

namespace Hn\Agent\Tests\Functional\MCP\Tool;

use Hn\Agent\MCP\Tool\GetFileInfoTool;
use Hn\Agent\Service\AttachmentService;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class GetFileInfoToolTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
        'agent',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');
    }

    public function testReturnsMetadataForImage(): void
    {
        $tool = $this->buildTool(101, 'image/png', 2048, 'pixel.png', '1:/uploads/pixel.png');

        $result = $tool->execute(['uid' => 101]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        self::assertCount(1, $result->content);
        $text = $result->content[0];
        self::assertInstanceOf(TextContent::class, $text);
        self::assertStringContainsString('pixel.png', $text->text);
        self::assertStringContainsString('image/png', $text->text);
        self::assertStringContainsString('sys_file:101', $text->text);
        self::assertStringNotContainsString('base64', $text->text);
    }

    public function testReturnsMetadataForPdfWithoutError(): void
    {
        // PDFs are no longer special-cased — GetFileInfo just reports the
        // facts and doesn't flag as error. ViewImage would error on a PDF.
        $tool = $this->buildTool(202, 'application/pdf', 4096, 'doc.pdf', '1:/uploads/doc.pdf');

        $result = $tool->execute(['uid' => 202]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        self::assertCount(1, $result->content);
        $text = $result->content[0];
        self::assertInstanceOf(TextContent::class, $text);
        self::assertStringContainsString('doc.pdf', $text->text);
        self::assertStringContainsString('application/pdf', $text->text);
    }

    public function testReturnsMetadataForUnsupportedMime(): void
    {
        $tool = $this->buildTool(404, 'text/plain', 100, 'notes.txt', '1:/uploads/notes.txt');

        $result = $tool->execute(['uid' => 404]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        self::assertCount(1, $result->content);
        self::assertStringContainsString('text/plain', $result->content[0]->text);
        self::assertStringContainsString('notes.txt', $result->content[0]->text);
    }

    public function testReturnsErrorWhenUidNotFound(): void
    {
        $tool = new GetFileInfoTool(
            new AttachmentService(
                GeneralUtility::makeInstance(ResourceFactory::class),
                GeneralUtility::makeInstance(ConnectionPool::class),
            ),
        );

        $result = $tool->execute(['uid' => 999999]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('999999', $result->content[0]->text);
        self::assertStringContainsString('could not be resolved', $result->content[0]->text);
    }

    public function testReturnsErrorWhenUidIsZero(): void
    {
        $tool = new GetFileInfoTool(
            new AttachmentService(
                GeneralUtility::makeInstance(ResourceFactory::class),
                GeneralUtility::makeInstance(ConnectionPool::class),
            ),
        );

        $result = $tool->execute(['uid' => 0]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('uid', $result->content[0]->text);
    }

    private function buildTool(int $uid, string $mime, int $size, string $name, string $identifier): GetFileInfoTool
    {
        $file = $this->getMockBuilder(File::class)->disableOriginalConstructor()->getMock();
        $file->method('getUid')->willReturn($uid);
        $file->method('getMimeType')->willReturn($mime);
        $file->method('getSize')->willReturn($size);
        $file->method('getName')->willReturn($name);
        $file->method('getCombinedIdentifier')->willReturn($identifier);
        // GetFileInfo never reads bytes — assert that explicitly.
        $file->expects(self::never())->method('getContents');

        $factory = $this->getMockBuilder(ResourceFactory::class)->disableOriginalConstructor()->getMock();
        $factory->expects(self::atLeastOnce())->method('getFileObject')->with($uid)->willReturn($file);

        return new GetFileInfoTool(
            new AttachmentService($factory, GeneralUtility::makeInstance(ConnectionPool::class)),
        );
    }
}
