<?php

declare(strict_types=1);

namespace Hn\Agent\Tests\Llm;

use Hn\Agent\Service\AgentScratchStorage;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Real-LLM acceptance tests for the scratch-storage workflow: chat uploads
 * and binary tool outputs land in the non-public agent scratch storage
 * (var/agent-scratch/, is_public=0) and must be handled correctly by the
 * agent —
 *
 *  1. read scratch attachments via ReadFile like any other FAL file, and
 *  2. NEVER reference a scratch file in a regular record directly, but
 *     promote it into a public FAL storage via PromoteScratchFile first
 *     and reference the promoted copy (the original stays in scratch for
 *     chat-history preview).
 *
 * These are the control criterion for the scratch implementation — the
 * functional suite only covers the mechanics with a scripted LLM.
 */
class ScratchFileTest extends AgentLlmTestCase
{
    /**
     * Unlike the base default (gpt-oss-120b, text-only), these tests hand
     * the agent an image attachment — a real agent may legitimately
     * ReadFile it, which returns image_url content the model must accept —
     * and the promote flow needs multi-step tool discipline (promote →
     * write → verify) that budget models fail at more often than the
     * 3-attempt retry absorbs (tested 2026-07: gemini-2.5-flash-lite and
     * gpt-5-nano regularly stop after the first tool call). claude-haiku-4.5
     * is the extension's production default model, so this is also the most
     * honest acceptance check; a run costs a few cents.
     */
    protected const DEFAULT_MODEL = 'anthropic/claude-haiku-4.5';

    /**
     * 1×1 transparent PNG — enough for FAL, small enough to embed here.
     */
    private const PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();

        // Production instances always have a public fileadmin storage as
        // uid 1 — the promote target. Create it BEFORE anything touches the
        // scratch storage so the uids mirror reality (fileadmin=1, scratch=2)
        // and PromoteScratchFile has somewhere public to copy to.
        GeneralUtility::mkdir_deep(Environment::getPublicPath() . '/fileadmin/user_upload');
        GeneralUtility::makeInstance(StorageRepository::class)
            ->createLocalStorage('fileadmin', 'fileadmin/', 'relative', '', true);
    }

    public function testReadsScratchAttachmentViaReadFile(): void
    {
        $file = $this->storeScratchFile(
            "Interne Projektnotiz\n\nDer Geheimcode für den Relaunch lautet BANANENBROT-42.\n",
            'text/plain',
        );

        [$task, $messages] = $this->runAgentTask(
            'Was steht in der angehängten Textdatei? Nenne den Geheimcode wörtlich.',
            1,
            [['uid' => $file->getUid()]],
        );

        $this->assertTaskEnded($task, $messages);

        // The scratch file must be readable like any other FAL file.
        $readCalls = $this->assertToolCalled($messages, 'ReadFile');
        $readUids = array_map(static fn(array $call): int => (int)($call['arguments']['uid'] ?? 0), $readCalls);
        self::assertContains($file->getUid(), $readUids, 'ReadFile was not called with the attachment UID.');

        self::assertStringContainsString(
            'BANANENBROT-42',
            $this->getFinalAssistantText($messages),
            'Expected the answer to quote the file content. Tool calls: ' . $this->describeToolCalls($messages),
        );
    }

    public function testPromotesScratchImageBeforeReferencingInContent(): void
    {
        $file = $this->storeScratchFile(base64_decode(self::PIXEL_PNG_BASE64), 'image/png');

        [$task, $messages] = $this->runAgentTask(
            'Erstelle auf der aktuellen Seite ein neues Inhaltselement mit dem angehängten Bild '
                . '(Überschrift: "Bildtest"). Das Bild muss nicht inhaltlich geprüft werden.',
            1,
            [['uid' => $file->getUid()]],
        );

        $this->assertTaskEnded($task, $messages);

        $scratch = $this->makeScratchStorage();
        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);

        // Promotion copies — the original must survive in scratch for the
        // chat-history preview. Whether the LLM called PromoteScratchFile
        // itself or the ScratchFileWriteGuard promoted on write is an
        // implementation detail; the invariant below is what matters.
        $original = $resourceFactory->getFileObject($file->getUid());
        self::assertTrue($scratch->isScratchFile($original), 'Original upload vanished from the scratch storage.');

        // The created content element must reference a file OUTSIDE the
        // scratch storage (a scratch reference would 404 in the frontend) —
        // and that file must be a copy of the upload, not something else.
        $references = $this->getTtContentImageReferences();
        self::assertNotEmpty(
            $references,
            'Expected a sys_file_reference on tt_content after the run. Tool calls: ' . $this->describeToolCalls($messages),
        );
        foreach ($references as $reference) {
            $referencedFile = $resourceFactory->getFileObject((int)$reference['uid_local']);
            self::assertFalse(
                $scratch->isScratchFile($referencedFile),
                sprintf(
                    'tt_content references sys_file:%d which still lives in the non-public scratch storage — it would 404 in the frontend.',
                    $referencedFile->getUid(),
                ),
            );
            self::assertSame(
                sha1(base64_decode(self::PIXEL_PNG_BASE64)),
                sha1($referencedFile->getContents()),
                'The referenced file is not a copy of the uploaded image.',
            );
        }
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Put a file into the agent scratch storage the same way chat-composer
     * uploads and binary tool outputs end up there.
     */
    private function storeScratchFile(string $binary, string $mimeType): File
    {
        return $this->makeScratchStorage()->store(1, $binary, $mimeType);
    }

    private function makeScratchStorage(): AgentScratchStorage
    {
        return new AgentScratchStorage(
            GeneralUtility::makeInstance(StorageRepository::class),
            GeneralUtility::makeInstance(ResourceFactory::class),
            $this->getConnectionPool(),
        );
    }

    /**
     * @return list<array{uid_local: int|string}>
     */
    private function getTtContentImageReferences(): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();
        return $queryBuilder
            ->select('uid_local')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter('tt_content')),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
