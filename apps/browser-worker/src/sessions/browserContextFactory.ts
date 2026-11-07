import { mkdtemp, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { chromium, devices, type Browser, type BrowserContext, type BrowserContextOptions } from "playwright";

import { startEgressProxy } from "../network/egressProxy.js";
import type { AllowList, RunContext } from "../playbook/types.js";

export interface BrowserContextHandle {
  readonly context: BrowserContext;
  readonly browser: Browser;
  readonly downloadsDir: string;
  close(): Promise<void>;
}

export type BrowserContextFactory = (allow: AllowList, context?: RunContext) => Promise<BrowserContextHandle>;

/**
 * One Chromium browser and one browser context per session.
 *
 * Never reused between sessions: a profile carries cookies, storage and cached credentials, and two
 * callers sharing one would share whoever signed in first. Downloads land in a directory private to
 * the session, and browser, context and directory are torn down together in `close()`.
 */
/**
 * A playbook's `context` as Playwright's own options.
 *
 * A device descriptor first, so a named device sets viewport, user agent and scale together the way
 * Playwright means it to, and anything stated explicitly beside it wins — "iPhone 13, but in
 * a different locale" is a thing somebody will want to say.
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
    // A service worker fetches outside the page; refusing them keeps every request the page makes one
    // the browser makes, through the proxy below.
    serviceWorkers: "block",
  };
}

/**
 * A browser for one session, whose every request goes through an egress proxy holding the playbook's
 * allow-list ({@see startEgressProxy}) — redirects, scripts and frames included, which no step sees.
 *
 * `<-loopback>` takes away Chromium's own exception for loopback, which would otherwise reach
 * 127.0.0.1 around the proxy: an address on this machine is refused like any other the playbook does
 * not name.
 */
export const createPlaywrightBrowserContext: BrowserContextFactory = async (allow, runContext) => {
  const downloadsDir = await mkdtemp(join(tmpdir(), "browser-session-"));
  const proxy = await startEgressProxy(allow, runContext?.proxy);
  let browser: Browser;

  try {
    browser = await chromium.launch({ headless: true, downloadsPath: downloadsDir, proxy: { server: proxy.url, bypass: "<-loopback>" } });
  } catch (error) {
    await proxy.close();
    await rm(downloadsDir, { recursive: true, force: true });
    throw error;
  }

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
      await proxy.close();
      await rm(downloadsDir, { recursive: true, force: true });
    },
  };
};
