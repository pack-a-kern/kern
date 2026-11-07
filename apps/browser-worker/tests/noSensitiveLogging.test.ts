import assert from "node:assert/strict";
import { createServer, type Server } from "node:http";
import type { AddressInfo } from "node:net";
import { test } from "node:test";

import { createApp } from "../src/app.js";
import { createPlaywrightBrowserContext } from "../src/sessions/browserContextFactory.js";
import { SessionManager } from "../src/sessions/sessionManager.js";

const TEST_TOKEN = "test-internal-token";
const SECRET_PASSWORD = "sup3r-secret-password-marker";

/**
 * A secret never reaches a log line, and never leaves in a response.
 *
 * `docs/AGENTS.md` §1 rule 12 — under every outcome, including the failing ones, which is where it
 * is most likely to slip: Playwright quotes the value it was filling into the error it throws, and
 * that error becomes the response body unless something takes it out.
 *
 * Against a real browser, because the failure being tested is a real Playwright error rather than
 * one this test could write.
 */
async function withServer(fn: (baseUrl: string, lines: string[]) => Promise<void>): Promise<void> {
  const lines: string[] = [];
  const originalLog = console.log;
  const originalError = console.error;

  console.log = (...args: unknown[]): void => {
    lines.push(args.map(String).join(" "));
  };
  console.error = (...args: unknown[]): void => {
    lines.push(args.map(String).join(" "));
  };

  const manager = new SessionManager({ ttlMs: 60_000, createBrowserContext: createPlaywrightBrowserContext, maxSessions: 8 });
  const server: Server = createServer(
    createApp(manager, { authMode: "token", internalAuthToken: TEST_TOKEN, concurrency: 1, maxWaiting: 4, maxSourceBytes: 1024 * 1024 }),
  );

  await new Promise<void>((resolve) => server.listen(0, resolve));

  const address = server.address() as AddressInfo;

  try {
    await fn(`http://127.0.0.1:${address.port.toString()}`, lines);
  } finally {
    console.log = originalLog;
    console.error = originalError;
    await manager.closeAll();
    await new Promise<void>((resolve) => server.close(() => resolve()));
  }
}

test("a secret reaches neither a log line nor the response, on the path where it fails", async () => {
  await withServer(async (baseUrl, lines) => {
    const playbook = {
      version: 1,
      name: "secret-test",
      allow: ["example.test"],
      sequences: {
        // A selector nothing matches, so Playwright times out while filling — the moment it has the
        // value in hand and puts it into an error.
        signIn: [{ fill: { selector: "#nothing-matches-this", value: "{{ secret.password }}", sensitive: true, timeout: 500 } }],
      },
    };

    const opened = await fetch(`${baseUrl}/v1/sessions`, {
      method: "POST",
      headers: { authorization: `Bearer ${TEST_TOKEN}`, "content-type": "application/json" },
      body: JSON.stringify({ playbook }),
    });

    const { session_id: id } = (await opened.json()) as { session_id: string };

    const response = await fetch(`${baseUrl}/v1/sessions/${id}/run`, {
      method: "POST",
      headers: { authorization: `Bearer ${TEST_TOKEN}`, "content-type": "application/json" },
      body: JSON.stringify({ playbook, sequence: "signIn", secrets: { password: SECRET_PASSWORD } }),
    });

    const body = await response.text();

    assert.ok(!body.includes(SECRET_PASSWORD), `the response carried the secret: ${body}`);

    for (const line of lines) {
      assert.ok(!line.includes(SECRET_PASSWORD), `a log line carried the secret: ${line}`);
    }
  });
});
