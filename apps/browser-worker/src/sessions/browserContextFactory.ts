import { mkdtemp, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { chromium, devices, type Browser, type BrowserContext, type BrowserContextOptions } from "playwright";

import type { RunContext } from "../playbook/types.js";

export interface BrowserContextHandle {
  readonly context: BrowserContext;
  readonly browser: Browser;
  readonly downloadsDir: string;
  close(): Promise<void>;
}

export type BrowserContextFactory = (context?: RunContext) => Promise<BrowserContextHandle>;

/**
 * One Chromium browser and one browser context per session (`BLUEPRINT.md`
 * §3.4): no Chrome profile is ever reused between tax subjects, downloads
 * land in a session-private directory, and both are torn down together in
 * `close()`. Navigating the actual SPV portal is Milestone 10 — this
 * factory only establishes the isolated runtime that automation will later
 * run inside.
 */
/**
 * A playbook's `context` as Playwright's own options.
 *
 * A device descriptor first, so a named device sets viewport, user agent and scale together the way
 * Playwright means it to, and anything stated explicitly beside it wins — "iPhone 13, but in
 * Romanian" is a thing somebody will want to say.
 */
function optionsFor(context: RunContext | undefined): BrowserContextOptions {
  const described = context?.device === undefined ? undefined : devices[context.device];

  return {
    acceptDownloads: true,
    ...described,
    ...(context?.viewport === undefined ? {} : { viewport: context.viewport }),
    ...(context?.userAgent === undefined ? {} : { userAgent: context.userAgent }),
    ...(context?.locale === undefined ? {} : { locale: context.locale }),
    ...(context?.timezoneId === undefined ? {} : { timezoneId: context.timezoneId }),
    ...(context?.proxy === undefined ? {} : { proxy: context.proxy }),
  };
}

export const createPlaywrightBrowserContext: BrowserContextFactory = async (runContext) => {
  const downloadsDir = await mkdtemp(join(tmpdir(), "browser-session-"));
  const browser = await chromium.launch({ headless: true, downloadsPath: downloadsDir });
  const context = await browser.newContext(optionsFor(runContext));

  // Resource types the playbook said it does not need. A page without images and fonts loads in a
  // fraction of the time, and nothing here is looking at them.
  if (runContext?.block !== undefined && runContext.block.length > 0) {
    const blocked = new Set(runContext.block);

    await context.route("**/*", async (route) => {
      await (blocked.has(route.request().resourceType()) ? route.abort() : route.continue());
    });
  }

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
