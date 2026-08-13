import { test, expect } from '../fixtures/setup-fixtures';

/**
 * E2E tests for the image lightbox: chat thumbnails open a large preview in a
 * TYPO3 backend modal (typo3-backend-modal, native <dialog>). The modal
 * renders in the top frame, not in the module iframe.
 *
 *  - Choice tiles carry a separate zoom button (.chat-choice-tile-zoom) so the
 *    tile click still submits the choice. uid options zoom through the
 *    extension's attachment-preview endpoint (core's thumbnail endpoint caps
 *    at 96px), url options zoom to the raw url.
 *  - Plain <img> in assistant markdown opens the lightbox via the delegated
 *    click handler in ChatElement. The fake LLM echoes the prompt back as
 *    markdown ("You said: ..."), which lets us render an arbitrary image.
 */
test.describe('Agent Image Lightbox', () => {
  test.beforeEach(async ({ agentModule }) => {
    await agentModule.gotoModule();
  });

  test('zoom icon on a choice tile opens the large preview without submitting the choice', async ({ agentModule, shot }) => {
    await agentModule.createTask('E2E-IMAGE-CHOICE: welche Bilder soll ich einbauen?');

    const choice = agentModule.chat().locator('hn-agent-choice').first();
    await expect(choice).toBeVisible({ timeout: 20000 });
    const zoomButtons = choice.locator('.chat-choice-tile-zoom');
    await expect(zoomButtons).toHaveCount(2);

    await zoomButtons.nth(0).click();

    // uid options go through the extension's large-preview endpoint.
    const modalImg = agentModule.page.locator('typo3-backend-modal .hn-agent-lightbox-img');
    await expect(modalImg).toBeVisible();
    await expect(modalImg).toHaveAttribute('src', /attachment-preview.*identifier=9991/);
    await shot('lightbox-open');

    // The zoom click must not have submitted the choice.
    await expect(agentModule.chat().getByText('You said:', { exact: false })).toHaveCount(0);

    // Escape closes the modal — and still no choice submission.
    await agentModule.page.keyboard.press('Escape');
    await expect(modalImg).toBeHidden();
    await shot('lightbox-closed');
    await expect(agentModule.chat().getByText('You said:', { exact: false })).toHaveCount(0);

    // The tile itself still submits as before.
    await choice.locator('.chat-choice-tile').nth(0).click();
    await expect(
      agentModule.chat()
        .getByText('You said: Ausgewählte Bilder: Logo oben links (sys_file:9991)', { exact: false })
        .first(),
    ).toBeVisible({ timeout: 20000 });
  });

  test('url-based tile zooms to the raw image url', async ({ agentModule, shot }) => {
    await agentModule.createTask('E2E-IMAGE-CHOICE-URL: welches Logo soll ich verwenden?');

    const choice = agentModule.chat().locator('hn-agent-choice').first();
    await expect(choice).toBeVisible({ timeout: 20000 });

    await choice.locator('.chat-choice-tile-zoom').nth(0).click();

    const modalImg = agentModule.page.locator('typo3-backend-modal .hn-agent-lightbox-img');
    await expect(modalImg).toBeVisible();
    await expect(modalImg).toHaveAttribute('src', '/fileadmin/e2e/logo-blau.png');
    await shot('lightbox-url-open');
  });

  test('markdown image in an assistant reply opens the lightbox', async ({ agentModule, shot }) => {
    await agentModule.createTask('Zeig mal ![Beispielbild](/fileadmin/e2e/logo-blau.png)');

    const mdImg = agentModule.chatBubbles().locator('img[src="/fileadmin/e2e/logo-blau.png"]').first();
    await expect(mdImg).toBeVisible({ timeout: 20000 });

    await mdImg.click();

    const modalImg = agentModule.page.locator('typo3-backend-modal .hn-agent-lightbox-img');
    await expect(modalImg).toBeVisible();
    await expect(modalImg).toHaveAttribute('src', /logo-blau\.png/);
    await shot('lightbox-markdown-open');
  });
});
