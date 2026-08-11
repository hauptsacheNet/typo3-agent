import { test, expect } from '../fixtures/setup-fixtures';

/**
 * E2E tests for the clickable choice card. The fake LLM (fake-llm-server.php)
 * emits an ```agent-choices``` block when the prompt contains:
 *
 *  - E2E-CHOICE        single-select card
 *  - E2E-CHOICE-MULTI  multiselect card (needs the "Auswahl senden" button)
 *
 * A click sends the picked label(s) as a normal follow-up message; the fake
 * LLM then echoes "You said: <label(s)>", proving the loop continued.
 */
test.describe('Agent Choice Card', () => {
  test.beforeEach(async ({ agentModule }) => {
    await agentModule.gotoModule();
  });

  test('single-select choice renders a card and a click continues the conversation', async ({ agentModule, shot }) => {
    await agentModule.createTask('E2E-CHOICE: help me pick a title.');

    const choice = agentModule.chat().locator('hn-agent-choice').first();
    await expect(choice).toBeVisible({ timeout: 20000 });
    await shot('choice-card-rendered');

    // Pick the second option — a single-select click submits immediately.
    await choice.locator('.chat-choice-option').nth(1).click();

    // The picked label is sent back and echoed by the fake LLM.
    await expect(
      agentModule.chat().getByText('You said: Enterprise IT & Technologie', { exact: false }).first(),
    ).toBeVisible({ timeout: 20000 });
    await shot('choice-answered');

    // The now-answered card is no longer interactive (it isn't the last message).
    await expect(choice.locator('.chat-choice-option').first()).toBeDisabled();
  });

  test('multiselect choice sends the combined labels', async ({ agentModule, shot }) => {
    await agentModule.createTask('E2E-CHOICE-MULTI: help me pick titles.');

    const choice = agentModule.chat().locator('hn-agent-choice').first();
    await expect(choice).toBeVisible({ timeout: 20000 });

    // Toggle first and third option, then submit.
    await choice.locator('.chat-choice-option').nth(0).click();
    await choice.locator('.chat-choice-option').nth(2).click();
    await shot('multiselect-selected');
    await choice.getByRole('button', { name: /Auswahl senden/ }).click();

    await expect(
      agentModule.chat()
        .getByText('You said: IT-News & Branchentrends, Tech-Branche: News & Know-how', { exact: false })
        .first(),
    ).toBeVisible({ timeout: 20000 });
    await shot('multiselect-answered');
  });
});
