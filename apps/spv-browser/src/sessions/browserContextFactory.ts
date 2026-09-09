import { mkdtemp, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { chromium, type Browser, type BrowserContext } from "playwright";

export interface BrowserContextHandle {
  readonly context: BrowserContext;
  readonly browser: Browser;
  readonly downloadsDir: string;
  close(): Promise<void>;
}

export type BrowserContextFactory = () => Promise<BrowserContextHandle>;

/**
 * One Chromium browser and one browser context per session (`BLUEPRINT.md`
 * §3.4): no Chrome profile is ever reused between tax subjects, downloads
 * land in a session-private directory, and both are torn down together in
 * `close()`. Navigating the actual SPV portal is Milestone 10 — this
 * factory only establishes the isolated runtime that automation will later
 * run inside.
 */
export const createPlaywrightBrowserContext: BrowserContextFactory = async () => {
  const downloadsDir = await mkdtemp(join(tmpdir(), "spv-session-"));
  const browser = await chromium.launch({ headless: true, downloadsPath: downloadsDir });
  const context = await browser.newContext({ acceptDownloads: true });

  return {
    context,
    browser,
    downloadsDir,
    close: async (): Promise<void> => {
      await context.close();
      await browser.close();
      await rm(downloadsDir, { recursive: true, force: true });
    },
  };
};
