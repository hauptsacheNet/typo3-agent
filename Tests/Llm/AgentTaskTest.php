<?php

declare(strict_types=1);

namespace Hn\Agent\Tests\Llm;

/**
 * First real-LLM tests for the agent loop: a read-only task answered from
 * the synthesized page context, and a write task that must go through the
 * WriteTable MCP tool. Both use realistic prompts without IDs or hints.
 */
class AgentTaskTest extends AgentLlmTestCase
{
    public function testAnswersPageTitleFromContext(): void
    {
        [$task, $messages] = $this->runAgentTask(
            'What is the title of the page you are currently working on? Answer with the exact page title.',
        );

        $this->assertTaskEnded($task, $messages);

        // Page 1 ("Home") is injected as synthetic GetPage context by
        // buildInitialMessages, so the answer must mention it — with or
        // without extra tool exploration.
        $finalText = $this->getFinalAssistantText($messages);
        self::assertStringContainsStringIgnoringCase(
            'Home',
            $finalText,
            'Expected the final answer to contain the page title. Tool calls: ' . $this->describeToolCalls($messages),
        );
    }

    public function testCreatesPageViaWriteTable(): void
    {
        [$task, $messages] = $this->runAgentTask(
            "Create a new page called 'Services' below the current page.",
        );

        $this->assertTaskEnded($task, $messages);
        $this->assertToolCalled($messages, 'WriteTable');

        // The page must actually exist afterwards — workspace versions
        // included, hence no t3ver_wsid filter.
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $count = (int)$queryBuilder
            ->count('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->like('title', $queryBuilder->createNamedParameter('%Services%')),
                $queryBuilder->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchOne();

        self::assertGreaterThan(
            0,
            $count,
            'Expected a "Services" page record to exist after the run. Tool calls: ' . $this->describeToolCalls($messages),
        );
    }
}
