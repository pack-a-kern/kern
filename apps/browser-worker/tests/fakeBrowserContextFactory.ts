import type { Browser, BrowserContext } from "playwright";
import type { BrowserContextFactory, BrowserContextHandle } from "../src/sessions/browserContextFactory.js";

/**
 * A `BrowserContextFactory` that never touches Playwright or the network.
 * The real factory launches Chromium, which this sandbox has no cached
 * binaries for and Milestone 0 has no reason to download — session
 * lifecycle logic is exercised against this fake instead.
 */
export function createFakeBrowserContextFactory(): {
  readonly factory: BrowserContextFactory;
  readonly closedIds: readonly string[];
} {
  const closedIds: string[] = [];
  let counter = 0;

  const factory: BrowserContextFactory = () => {
    const id = String(counter);
    counter += 1;
    const handle: BrowserContextHandle = {
      context: {} as unknown as BrowserContext,
      browser: {} as unknown as Browser,
      downloadsDir: `/tmp/fake-spv-session-${id}`,
      close: () => {
        closedIds.push(id);
        return Promise.resolve();
      },
    };
    return Promise.resolve(handle);
  };

  return { factory, closedIds };
}
