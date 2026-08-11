import { test, expect } from '../fixtures/setup-fixtures';

/**
 * E2E tests for the image choice card (clickable thumbnails). The fake LLM
 * (fake-llm-server.php) emits an ```agent-choices``` block whose options carry
 * a sys_file `uid` when the prompt contains:
 *
 *  - E2E-IMAGE-CHOICE        single-select thumbnail grid
 *  - E2E-IMAGE-CHOICE-MULTI  multiselect thumbnail grid
 *
 * The referenced uids (9991/9992) intentionally may not resolve to a real
 * file, so this exercises the thumbnail @error fallback too — the tile stays
 * clickable regardless. A click sends the picked "<label> (sys_file:<uid>)"
 * refs as a normal follow-up message, which the fake LLM echoes back.
 */
test.describe('Agent Image Choice Card', () => {
  test.beforeEach(async ({ agentModule }) => {
    await agentModule.gotoModule();
  });

  test('single-select image choice renders thumbnails and sends the sys_file uid', async ({ agentModule, shot }) => {
    await agentModule.createTask('E2E-IMAGE-CHOICE: welche Bilder soll ich einbauen?');

    const choice = agentModule.chat().locator('hn-agent-choice').first();
    await expect(choice).toBeVisible({ timeout: 20000 });
    const tiles = choice.locator('.chat-choice-tile');
    await expect(tiles).toHaveCount(2);
    await shot('image-choice-rendered');

    // Single-select: clicking a tile submits immediately with its sys_file uid.
    await tiles.nth(0).click();

    await expect(
      agentModule.chat()
        .getByText('You said: Ausgewählte Bilder: Logo oben links (sys_file:9991)', { exact: false })
        .first(),
    ).toBeVisible({ timeout: 20000 });
    await shot('image-choice-answered');
  });

  test('multiselect image choice combines the picked sys_file uids', async ({ agentModule, shot }) => {
    await agentModule.createTask('E2E-IMAGE-CHOICE-MULTI: welche Bilder soll ich einbauen?');

    const choice = agentModule.chat().locator('hn-agent-choice').first();
    await expect(choice).toBeVisible({ timeout: 20000 });
    const tiles = choice.locator('.chat-choice-tile');
    await expect(tiles).toHaveCount(2);

    await tiles.nth(0).click();
    await tiles.nth(1).click();
    await shot('image-multiselect-selected');
    await choice.getByRole('button', { name: /Auswahl senden/ }).click();

    await expect(
      agentModule.chat()
        .getByText('You said: Ausgewählte Bilder: Logo oben links (sys_file:9991), Team-Foto (sys_file:9992)', { exact: false })
        .first(),
    ).toBeVisible({ timeout: 20000 });
    await shot('image-multiselect-answered');
  });
});
