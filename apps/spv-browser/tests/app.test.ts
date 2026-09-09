import assert from "node:assert/strict";
import { createServer, type Server } from "node:http";
import type { AddressInfo } from "node:net";
import { test } from "node:test";
import { createApp } from "../src/app.js";
import { UnimplementedPortalAuthenticator, type PortalAuthenticator } from "../src/portal/portalAuthenticator.js";
import { SessionManager } from "../src/sessions/sessionManager.js";
import { createFakeBrowserContextFactory } from "./fakeBrowserContextFactory.js";
import { createFakePortalAuthenticator } from "./fakePortalAuthenticator.js";

const TEST_TOKEN = "test-internal-token";

interface CreatedSession {
  readonly id: string;
  readonly status: string;
}

async function withServer(
  fn: (baseUrl: string) => Promise<void>,
  portalAuthenticator: PortalAuthenticator = new UnimplementedPortalAuthenticator(),
): Promise<void> {
  const { factory } = createFakeBrowserContextFactory();
  const manager = new SessionManager({ ttlMs: 60_000, createBrowserContext: factory });
  const server: Server = createServer(createApp(manager, portalAuthenticator, TEST_TOKEN));
  await new Promise<void>((resolve) => server.listen(0, resolve));
  const address = server.address() as AddressInfo;
  try {
    await fn(`http://127.0.0.1:${address.port.toString()}`);
  } finally {
    await manager.closeAll();
    await new Promise<void>((resolve) => server.close(() => resolve()));
  }
}

function authed(init: RequestInit = {}): RequestInit {
  const headers = init.headers as Record<string, string> | undefined;
  return {
    ...init,
    headers: { ...(headers ?? {}), authorization: `Bearer ${TEST_TOKEN}` },
  };
}

async function createSession(baseUrl: string): Promise<CreatedSession> {
  const response = await fetch(`${baseUrl}/v1/spv/sessions`, authed({ method: "POST" }));
  return (await response.json()) as CreatedSession;
}

test("GET /health returns ok without requiring a session or auth", async () => {
  await withServer(async (baseUrl) => {
    const response = await fetch(`${baseUrl}/health`);
    assert.equal(response.status, 200);
    assert.deepEqual(await response.json(), { status: "ok", service: "spv-browser" });
  });
});

test("POST /v1/spv/sessions with no Authorization header is rejected before touching session state", async () => {
  await withServer(async (baseUrl) => {
    const response = await fetch(`${baseUrl}/v1/spv/sessions`, { method: "POST" });
    assert.equal(response.status, 401);
    const body = (await response.json()) as { error: string };
    assert.equal(body.error, "unauthorized");
  });
});

test("POST /v1/spv/sessions with the wrong token is rejected", async () => {
  await withServer(async (baseUrl) => {
    const response = await fetch(`${baseUrl}/v1/spv/sessions`, {
      method: "POST",
      headers: { authorization: "Bearer wrong-token" },
    });
    assert.equal(response.status, 401);
  });
});

test("every /v1/spv/* route is rejected when no internal auth token is configured on the server at all", async () => {
  const { factory } = createFakeBrowserContextFactory();
  const manager = new SessionManager({ ttlMs: 60_000, createBrowserContext: factory });
  const server: Server = createServer(createApp(manager, new UnimplementedPortalAuthenticator(), undefined));
  await new Promise<void>((resolve) => server.listen(0, resolve));
  const address = server.address() as AddressInfo;
  try {
    const response = await fetch(`http://127.0.0.1:${address.port.toString()}/v1/spv/sessions`, {
      method: "POST",
      headers: { authorization: `Bearer ${TEST_TOKEN}` },
    });
    assert.equal(response.status, 401);
    const body = (await response.json()) as { error: string; message: string };
    assert.match(body.message, /not configured/);
  } finally {
    await manager.closeAll();
    await new Promise<void>((resolve) => server.close(() => resolve()));
  }
});

test("POST /v1/spv/sessions creates a session and stamps a correlation id", async () => {
  await withServer(async (baseUrl) => {
    const response = await fetch(`${baseUrl}/v1/spv/sessions`, authed({ method: "POST" }));
    assert.equal(response.status, 201);
    const body = (await response.json()) as CreatedSession;
    assert.equal(body.status, "created");
    assert.ok(response.headers.get("x-request-id"));
  });
});

test("an inbound X-Request-Id is echoed back unchanged", async () => {
  await withServer(async (baseUrl) => {
    const response = await fetch(`${baseUrl}/health`, {
      headers: { "X-Request-Id": "test-correlation-id" },
    });
    assert.equal(response.headers.get("x-request-id"), "test-correlation-id");
  });
});

test("DELETE /v1/spv/sessions/:id destroys a known session", async () => {
  await withServer(async (baseUrl) => {
    const created = await createSession(baseUrl);
    const response = await fetch(`${baseUrl}/v1/spv/sessions/${created.id}`, authed({ method: "DELETE" }));
    assert.equal(response.status, 204);
  });
});

test("DELETE /v1/spv/sessions/:id on an unknown id returns 404", async () => {
  await withServer(async (baseUrl) => {
    const response = await fetch(`${baseUrl}/v1/spv/sessions/does-not-exist`, authed({ method: "DELETE" }));
    assert.equal(response.status, 404);
  });
});

test("POST .../credentials on an unknown session returns 404 before doing anything else", async () => {
  await withServer(async (baseUrl) => {
    const response = await fetch(
      `${baseUrl}/v1/spv/sessions/does-not-exist/credentials`,
      authed({
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ username: "u", password: "p" }),
      }),
    );
    assert.equal(response.status, 404);
  });
});

// "challenge" is deliberately absent here: it now requires the session to already be
// "awaiting_challenge" (enforced *before* the portal adapter is ever consulted), and the shipped
// UnimplementedPortalAuthenticator's own /credentials call always throws before any session ever
// reaches that state — so /challenge against a fresh session correctly answers 409 invalid_state,
// never 501. See "submitting a challenge code before credentials were ever submitted..." below.
test("POST .../actions/submit-d212 is not routed at all (declaration filing is out of scope)", async () => {
  // Removed deliberately (ADR 0012): this product syncs documents FROM ANAF; it does not file
  // declarations. A 404 here — rather than a 501 — is the assertion that the capability is absent,
  // not merely unimplemented, so nobody wires a filing flow to a route that quietly exists.
  await withServer(async (baseUrl) => {
    const created = await createSession(baseUrl);
    const response = await fetch(`${baseUrl}/v1/spv/sessions/${created.id}/actions/submit-d212`, {
      method: "POST",
      headers: { "content-type": "application/json", authorization: `Bearer ${TEST_TOKEN}` },
      body: JSON.stringify({}),
    });
    assert.equal(response.status, 404);
  });
});

const stillUnimplementedActions = [
  { path: "credentials", body: { username: "u", password: "p" } },
  { path: "actions/sync", body: {} },
];

for (const action of stillUnimplementedActions) {
  test(`POST .../${action.path} against the shipped UnimplementedPortalAuthenticator returns 501 not_implemented`, async () => {
    await withServer(async (baseUrl) => {
      const created = await createSession(baseUrl);
      const response = await fetch(
        `${baseUrl}/v1/spv/sessions/${created.id}/${action.path}`,
        authed({
          method: "POST",
          headers: { "content-type": "application/json" },
          body: JSON.stringify(action.body),
        }),
      );
      assert.equal(response.status, 501);
      const body = (await response.json()) as { error: string };
      assert.equal(body.error, "not_implemented");
    });
  });
}

test("the full credential + challenge machinery works end to end against a fake portal adapter", async () => {
  const { authenticator, credentialCalls, challengeCalls } = createFakePortalAuthenticator();
  await withServer(async (baseUrl) => {
    const created = await createSession(baseUrl);

    const credentialsResponse = await fetch(
      `${baseUrl}/v1/spv/sessions/${created.id}/credentials`,
      authed({
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ username: "ion.popescu", password: "s3cr3t-password" }),
      }),
    );
    assert.equal(credentialsResponse.status, 200);
    assert.deepEqual(await credentialsResponse.json(), { status: "awaiting_challenge" });
    assert.deepEqual([...credentialCalls], [{ username: "ion.popescu", password: "s3cr3t-password" }]);

    const wrongCodeResponse = await fetch(
      `${baseUrl}/v1/spv/sessions/${created.id}/challenge`,
      authed({
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ code: "999999" }),
      }),
    );
    assert.equal(wrongCodeResponse.status, 200);
    assert.deepEqual(await wrongCodeResponse.json(), { status: "failed", reason: "invalid_code" });

    // A session that has just failed a challenge is no longer awaiting one — resubmitting is a
    // state-ordering violation, not a fresh attempt.
    const retryAfterFailure = await fetch(
      `${baseUrl}/v1/spv/sessions/${created.id}/challenge`,
      authed({
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ code: "000000" }),
      }),
    );
    assert.equal(retryAfterFailure.status, 409);

    assert.deepEqual([...challengeCalls], ["999999"]);
  }, authenticator);
});

test("submitting a challenge code before credentials were ever submitted is rejected as an invalid state", async () => {
  const { authenticator, challengeCalls } = createFakePortalAuthenticator();
  await withServer(async (baseUrl) => {
    const created = await createSession(baseUrl);

    const response = await fetch(
      `${baseUrl}/v1/spv/sessions/${created.id}/challenge`,
      authed({
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ code: "000000" }),
      }),
    );

    assert.equal(response.status, 409);
    const body = (await response.json()) as { error: string };
    assert.equal(body.error, "invalid_state");
    assert.deepEqual([...challengeCalls], []);
  }, authenticator);
});

test("submitting credentials twice for the same session is rejected once it has moved past 'created'", async () => {
  const { authenticator, credentialCalls } = createFakePortalAuthenticator();
  await withServer(async (baseUrl) => {
    const created = await createSession(baseUrl);
    const body = JSON.stringify({ username: "u", password: "p" });

    const first = await fetch(
      `${baseUrl}/v1/spv/sessions/${created.id}/credentials`,
      authed({ method: "POST", headers: { "content-type": "application/json" }, body }),
    );
    assert.equal(first.status, 200);

    const second = await fetch(
      `${baseUrl}/v1/spv/sessions/${created.id}/credentials`,
      authed({ method: "POST", headers: { "content-type": "application/json" }, body }),
    );
    assert.equal(second.status, 409);

    assert.equal(credentialCalls.length, 1);
  }, authenticator);
});

test("a malformed credentials body is rejected as a bad request, never forwarded to the portal adapter", async () => {
  const { authenticator, credentialCalls } = createFakePortalAuthenticator();
  await withServer(async (baseUrl) => {
    const created = await createSession(baseUrl);
    const response = await fetch(
      `${baseUrl}/v1/spv/sessions/${created.id}/credentials`,
      authed({
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ username: "u" }),
      }),
    );
    assert.equal(response.status, 400);
    assert.deepEqual([...credentialCalls], []);
  }, authenticator);
});
