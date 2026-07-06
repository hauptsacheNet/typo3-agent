import { test, expect } from '../fixtures/setup-fixtures';

/**
 * E2E tests for the agent backend module against a real TYPO3 backend and a
 * deterministic fake LLM (Build/tests/fake-llm-server.php). The fake LLM
 * replies with recognizable markers:
 *
 *  - FAKE-LLM-REPLY      plain streamed answer
 *  - GetPage tool call    when the prompt contains "E2E-TOOL"
 *  - FAKE-LLM-TOOL-DONE  final answer after the tool result round-trip
 */
test.describe('Agent Backend Module', () => {
  test.beforeEach(async ({ agentModule }) => {
    await agentModule.gotoModule();
  });

  test('module loads with task composer and heading', async ({ agentModule }) => {
    await expect(agentModule.frame.locator('hn-agent-new-task')).toBeVisible();
    await expect(agentModule.composer().locator('textarea')).toBeVisible();
    await expect(agentModule.frame.locator('h1')).toBeVisible();
  });

  test('creating a task streams an assistant reply', async ({ agentModule }) => {
    await agentModule.createTask('Say hello to the E2E test, please.');

    // The user prompt is rendered as a bubble …
    await expect(agentModule.chatBubbles().filter({ hasText: 'Say hello to the E2E test' }).first())
      .toBeVisible({ timeout: 15000 });

    // … and the streamed assistant answer arrives via SSE.
    await expect(agentModule.chat().getByText('FAKE-LLM-REPLY', { exact: false }).first())
      .toBeVisible({ timeout: 20000 });
  });

  test('agent executes a tool call and renders it in the chat', async ({ agentModule }) => {
    await agentModule.createTask('E2E-TOOL: inspect the current page.');

    // The final answer only exists after the GetPage tool round-trip
    // (LLM → tool call → tool execution → LLM), so this asserts the whole loop.
    await expect(agentModule.chat().getByText('FAKE-LLM-TOOL-DONE', { exact: false }).first())
      .toBeVisible({ timeout: 30000 });

    // The tool call is rendered as a collapsible group; expand it and check
    // the GetPage call including its result is shown.
    const group = agentModule.toolCallGroups().first();
    await expect(group).toBeVisible();
    await group.locator('.panel-button').first().click();
    await expect(group.getByText('GetPage', { exact: false }).first()).toBeVisible();
  });

  test('follow-up message continues the conversation', async ({ agentModule }) => {
    await agentModule.createTask('Say hello once for the follow-up test.');
    await expect(agentModule.chat().getByText('FAKE-LLM-REPLY', { exact: false }).first())
      .toBeVisible({ timeout: 20000 });

    await agentModule.sendFollowUp('And now answer my follow-up question.');

    await expect(
      agentModule.chat().getByText('You said: And now answer my follow-up question', { exact: false }).first(),
    ).toBeVisible({ timeout: 20000 });
  });

  test('created task appears in the module task list', async ({ agentModule }) => {
    await agentModule.createTask('Task list visibility check, hello.');
    await expect(agentModule.chat().getByText('FAKE-LLM-REPLY', { exact: false }).first())
      .toBeVisible({ timeout: 20000 });

    await agentModule.gotoModule();
    await expect(
      agentModule.frame.getByText('Task list visibility check', { exact: false }).first(),
    ).toBeVisible();
  });
});
