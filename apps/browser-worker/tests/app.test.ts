import assert from "node:assert/strict";
import { createServer, type Server } from "node:http";
import type { AddressInfo } from "node:net";
import { test } from "node:test";

import { createApp } from "../src/app.js";
import { SessionManager } from "../src/sessions/sessionManager.js";
import { createFakeBrowserContextFactory } from "./fakeBrowserContextFactory.js";

const TEST_TOKEN = "test-internal-token";

/**
 * The middleware every request passes through, whatever it asks for.
 *
 * What the routes themselves do is {@link ./runs.test.ts}'s subject, against a real browser. These
 * are about the layers in front: health without a secret, auth that fails closed, and a correlation
 * id on everything. A fake context is right here — none of them opens a browser.
 */
async function withServer(
  fn: (baseUrl: string) => Promise<void>,
  token: string | undefined = TEST_TOKEN,
  maxSessions = 8,
): Promise<void> {
  const { factory } = createFakeBrowserContextFactory();
  const manager = new SessionManager({ ttlMs: 60_000, createBrowserContext: factory, maxSessions });
  const server: Server = createServer(
    createApp(manager, { authMode: "token", internalAuthToken: token, concurrency: 1, maxWaiting: 4 }),
  );

  await new Promise<void>((resolve) => server.listen(0, resolve));

  const address = server.address() as AddressInfo;

  try {
    await fn(`http://127.0.0.1:${address.port.toString()}`);
  } finally {
    await manager.closeAll();
    await new Promise<void>((resolve) => server.close(() => resolve()));
  }
}

test("GET /health returns ok without requiring auth", async () => {
  // The one unauthenticated route: a probe runs before any secret is configured, and has nothing
  // to say about what is inside.
  await withServer(async (baseUrl) => {
    const response = await fetch(`${baseUrl}/health`);

    assert.equal(response.status, 200);
    assert.deepEqual(await response.json(), { status: "ok", service: "browser-worker" });
  });
});

test("a request with no Authorization header is rejected before it reaches a route", async () => {
  await withServer(async (baseUrl) => {
    const response = await fetch(`${baseUrl}/v1/sessions`, { method: "POST" });

    assert.equal(response.status, 401);
  });
});

test("a request with the wrong token is rejected", async () => {
  await withServer(async (baseUrl) => {
    const response = await fetch(`${baseUrl}/v1/sessions`, {
      method: "POST",
      headers: { authorization: "Bearer not-the-token" },
    });

    assert.equal(response.status, 401);
  });
});

test("every /v1 route is rejected while no token is configured on the server at all", async () => {
  // Fails closed: a worker in token mode with nothing to compare against refuses, rather than
  // accepting whatever arrives. A deployment that wants no token says so with `authMode`.
  await withServer(async (baseUrl) => {
    for (const path of ["/v1/sessions", "/v1/run", "/v1/stats"]) {
      const response = await fetch(`${baseUrl}${path}`, {
        method: path === "/v1/stats" ? "GET" : "POST",
        headers: { authorization: "Bearer anything" },
      });

      assert.equal(response.status, 401, path);
    }
  }, undefined);
});

test("a correlation id is stamped on the response", async () => {
  await withServer(async (baseUrl) => {
    const response = await fetch(`${baseUrl}/health`);

    assert.match(response.headers.get("x-request-id") ?? "", /[0-9a-f-]{36}/);
  });
});

test("an inbound X-Request-Id is echoed back unchanged", async () => {
  await withServer(async (baseUrl) => {
    const response = await fetch(`${baseUrl}/health`, { headers: { "x-request-id": "given-by-the-caller" } });

    assert.equal(response.headers.get("x-request-id"), "given-by-the-caller");
  });
});

test("a session past the ceiling is refused as at capacity, the way a full run queue is", async () => {
  const playbook = {
    version: 1,
    name: "example",
    allow: ["example.test"],
    sequences: { noop: [{ waitFor: { any: [{ selector: "body", outcome: "ready" }], timeout: 2000 } }] },
  };
  const open = (baseUrl: string): Promise<Response> =>
    fetch(`${baseUrl}/v1/sessions`, {
      method: "POST",
      headers: { authorization: `Bearer ${TEST_TOKEN}`, "content-type": "application/json" },
      body: JSON.stringify({ playbook }),
    });

  await withServer(
    async (baseUrl) => {
      assert.equal((await open(baseUrl)).status, 201);

      const refused = await open(baseUrl);
      assert.equal(refused.status, 503);
      assert.deepEqual(await refused.json(), { error: "at_capacity" });
    },
    TEST_TOKEN,
    1,
  );
});
