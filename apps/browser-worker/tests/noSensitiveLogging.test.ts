import assert from "node:assert/strict";
import { createServer, type Server } from "node:http";
import type { AddressInfo } from "node:net";
import { test } from "node:test";
import { createApp } from "../src/app.js";
import { SessionManager } from "../src/sessions/sessionManager.js";
import { createFakeBrowserContextFactory } from "./fakeBrowserContextFactory.js";
import { createFakePortalAuthenticator } from "./fakePortalAuthenticator.js";

const TEST_TOKEN = "test-internal-token";
const SECRET_PASSWORD = "sup3r-secret-password-marker";
const SECRET_CODE = "424242";

/**
 * `docs/AGENTS.md` §1 rule 12 / `BLUEPRINT.md` §13.2 steps 3/9 — a password or OTP code must never
 * reach a log line, under any outcome (success, wrong code, malformed body, unknown session).
 * `src/logger.ts`'s allowlisted-field mechanism already makes this structurally hard to violate;
 * this test proves it end to end by spying on the real `console.log`/`console.error` sinks
 * `logger.ts` writes to, rather than trusting the allowlist by inspection alone.
 */
async function withSpiedConsole(fn: (baseUrl: string) => Promise<void>): Promise<string[]> {
  const lines: string[] = [];
  const originalLog = console.log;
  const originalError = console.error;
  console.log = (...args: unknown[]): void => {
    lines.push(args.map(String).join(" "));
  };
  console.error = (...args: unknown[]): void => {
    lines.push(args.map(String).join(" "));
  };

  const { factory } = createFakeBrowserContextFactory();
  const { authenticator } = createFakePortalAuthenticator();
  const manager = new SessionManager({ ttlMs: 60_000, createBrowserContext: factory });
  const server: Server = createServer(createApp(manager, authenticator, { authMode: "token", internalAuthToken: TEST_TOKEN, concurrency: 1, maxWaiting: 4 }));
  await new Promise<void>((resolve) => server.listen(0, resolve));
  const address = server.address() as AddressInfo;

  try {
    await fn(`http://127.0.0.1:${address.port.toString()}`);
  } finally {
    console.log = originalLog;
    console.error = originalError;
    await manager.closeAll();
    await new Promise<void>((resolve) => server.close(() => resolve()));
  }

  return lines;
}

function authed(init: RequestInit = {}): RequestInit {
  const headers = init.headers as Record<string, string> | undefined;
  return { ...init, headers: { ...(headers ?? {}), authorization: `Bearer ${TEST_TOKEN}` } };
}

test("no log line ever contains a submitted password or challenge code, across every outcome", async () => {
  const lines = await withSpiedConsole(async (baseUrl) => {
    const createResponse = await fetch(`${baseUrl}/v1/spv/sessions`, authed({ method: "POST" }));
    const created = (await createResponse.json()) as { id: string };

    await fetch(
      `${baseUrl}/v1/spv/sessions/${created.id}/credentials`,
      authed({
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ username: "ion.popescu", password: SECRET_PASSWORD }),
      }),
    );

    await fetch(
      `${baseUrl}/v1/spv/sessions/${created.id}/challenge`,
      authed({
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ code: SECRET_CODE }),
      }),
    );

    // A malformed JSON body on the sensitive path — src/middleware/errorHandler.ts's own
    // "never log the error message for /credentials or /challenge" guarantee, exercised for
    // real rather than only read.
    await fetch(
      `${baseUrl}/v1/spv/sessions/${created.id}/credentials`,
      authed({
        method: "POST",
        headers: { "content-type": "application/json" },
        body: `{"password": "${SECRET_PASSWORD}truncated`,
      }),
    );
  });

  assert.ok(lines.length > 0, "expected at least one log line to have been captured");
  for (const line of lines) {
    assert.doesNotMatch(line, new RegExp(SECRET_PASSWORD));
    assert.doesNotMatch(line, new RegExp(SECRET_CODE));
  }
});
