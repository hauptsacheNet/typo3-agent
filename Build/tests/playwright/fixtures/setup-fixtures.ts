import { test as base, type FrameLocator, type Locator, type Page, expect } from '@playwright/test';
import config from '../config';

/**
 * The agent backend module, driven through the TYPO3 backend content iframe.
 */
export class AgentModulePage {
  readonly page: Page;

  constructor(page: Page) {
    this.page = page;
  }

  get frame(): FrameLocator {
    return this.page.frameLocator('#typo3-contentIframe');
  }

  /** Open the task list (index) view. Page uid 1 is the site root from `typo3 setup`. */
  async gotoModule(pageUid = 1): Promise<void> {
    await this.page.goto(`${config.baseUrl}/typo3/module/web/typo3-agent-tasks?id=${pageUid}`);
    await this.page.waitForLoadState('networkidle');
    await expect(this.frame.locator('.module-body')).toBeVisible();
  }

  composer(): Locator {
    return this.frame.locator('hn-agent-new-task hn-agent-message-composer');
  }

  /** Fill the new-task composer and submit; resolves when the chat view is open. */
  async createTask(prompt: string): Promise<void> {
    const textarea = this.composer().locator('textarea');
    await expect(textarea).toBeVisible();
    await textarea.fill(prompt);
    await this.composer().locator('button[type="submit"]').click();
    await expect(this.chat()).toBeVisible({ timeout: 15000 });
  }

  chat(): Locator {
    return this.frame.locator('hn-agent-chat');
  }

  chatBubbles(): Locator {
    return this.chat().locator('hn-agent-chat-bubble');
  }

  /** The collapsible "N Tool Calls" group panels rendered for assistant messages. */
  toolCallGroups(): Locator {
    return this.chat().locator('.panel', { hasText: /Tool Calls?/ });
  }

  /** The follow-up composer at the bottom of an open chat. */
  followUpComposer(): Locator {
    return this.chat().locator('hn-agent-message-composer');
  }

  async sendFollowUp(message: string): Promise<void> {
    const textarea = this.followUpComposer().locator('textarea');
    await expect(textarea).toBeEnabled({ timeout: 20000 });
    await textarea.fill(message);
    await this.followUpComposer().locator('button[type="submit"]').click();
  }
}

type Fixtures = {
  agentModule: AgentModulePage;
  /**
   * Take a numbered full-page screenshot documenting the current step.
   * Files land in Build/screenshots/<test-title>/NN-<name>.png (uploaded as
   * CI artifact) and are attached to the Playwright HTML report.
   */
  shot: (name: string) => Promise<void>;
};

export const test = base.extend<Fixtures>({
  agentModule: async ({ page }, use) => {
    await use(new AgentModulePage(page));
  },

  shot: async ({ page }, use, testInfo) => {
    let counter = 0;
    const testSlug = testInfo.title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    await use(async (name: string) => {
      counter++;
      const path = `screenshots/${testSlug}/${String(counter).padStart(2, '0')}-${name}.png`;
      await page.screenshot({ path, fullPage: true });
      await testInfo.attach(name, { path, contentType: 'image/png' });
    });
  },
});

export { expect };
