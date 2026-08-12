import { test, expect } from '../fixtures/setup-fixtures';

/**
 * E2E tests for the image choice card (clickable thumbnails). The fake LLM
 * (fake-llm-server.php) emits an ```agent-choices``` block whose options carry
 * a sys_file `uid` when the prompt contains:
 *
 *  - E2E-IMAGE-CHOICE            single-select thumbnail grid (sys_file uids)
 *  - E2E-IMAGE-CHOICE-MULTI      multiselect thumbnail grid (sys_file uids)
 *  - E2E-IMAGE-CHOICE-URL        single-select grid with direct image `url`
 *                                options mixed with a sys_file uid option
 *
 * The referenced uids (9991/9992) and urls intentionally may not resolve to a
 * real image, so this exercises the thumbnail @error fallback too — the tile
 * stays clickable regardless. A click sends the picked "<label>
 * (sys_file:<uid>)" / "<label> (<url>)" refs as a normal follow-up message,
 * which the fake LLM echoes back.
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

  test('url-based image choice renders the raw url as <img src> and sends it back', async ({ agentModule, shot }) => {
    await agentModule.createTask('E2E-IMAGE-CHOICE-URL: welches Logo soll ich verwenden?');

    const choice = agentModule.chat().locator('hn-agent-choice').first();
    await expect(choice).toBeVisible({ timeout: 20000 });
    const tiles = choice.locator('.chat-choice-tile');
    await expect(tiles).toHaveCount(3);

    // url options use the given url directly (no thumbnail endpoint), the
    // uid option still goes through the backend thumbnail endpoint.
    await expect(tiles.nth(0).locator('img')).toHaveAttribute('src', '/fileadmin/e2e/logo-blau.png');
    await expect(tiles.nth(1).locator('img')).toHaveAttribute('src', 'https://example.com/logo-rot.png');
    await expect(tiles.nth(2).locator('img')).not.toHaveAttribute('src', /logo-/);
    await shot('image-choice-url-rendered');

    // Single-select: clicking a url tile submits immediately with its url.
    await tiles.nth(0).click();

    await expect(
      agentModule.chat()
        .getByText('You said: Ausgewählte Bilder: Logo blau (/fileadmin/e2e/logo-blau.png)', { exact: false })
        .first(),
    ).toBeVisible({ timeout: 20000 });
    await shot('image-choice-url-answered');
  });
});
