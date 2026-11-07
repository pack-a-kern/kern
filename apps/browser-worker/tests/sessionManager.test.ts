import assert from "node:assert/strict";
import { test } from "node:test";
import { SessionLimitError, SessionManager } from "../src/sessions/sessionManager.js";
import { createFakeBrowserContextFactory } from "./fakeBrowserContextFactory.js";

test("create() returns a session created now, expiring after the ttl", async () => {
  const { factory } = createFakeBrowserContextFactory();
  const manager = new SessionManager({ ttlMs: 60_000, createBrowserContext: factory, maxSessions: 8 });
  try {
    const session = await manager.create(["example.test"]);
    assert.equal(session.status, "created");
    assert.ok(session.expiresAt.getTime() > session.createdAt.getTime());
  } finally {
    await manager.closeAll();
  }
});

test("get() returns undefined for an unknown id", async () => {
  const { factory } = createFakeBrowserContextFactory();
  const manager = new SessionManager({ ttlMs: 60_000, createBrowserContext: factory, maxSessions: 8 });
  try {
    assert.equal(manager.get("does-not-exist"), undefined);
  } finally {
    await manager.closeAll();
  }
});

test("destroy() closes the browser context and removes the session", async () => {
  const { factory, closedIds } = createFakeBrowserContextFactory();
  const manager = new SessionManager({ ttlMs: 60_000, createBrowserContext: factory, maxSessions: 8 });
  const session = await manager.create(["example.test"]);

  const destroyed = await manager.destroy(session.id);

  assert.equal(destroyed, true);
  assert.equal(manager.get(session.id), undefined);
  assert.deepEqual([...closedIds], ["0"]);
});

test("destroy() returns false for an unknown id", async () => {
  const { factory } = createFakeBrowserContextFactory();
  const manager = new SessionManager({ ttlMs: 60_000, createBrowserContext: factory, maxSessions: 8 });
  try {
    assert.equal(await manager.destroy("does-not-exist"), false);
  } finally {
    await manager.closeAll();
  }
});

test("each session is isolated — distinct id and downloads dir, no shared profile", async () => {
  const { factory } = createFakeBrowserContextFactory();
  const manager = new SessionManager({ ttlMs: 60_000, createBrowserContext: factory, maxSessions: 8 });
  try {
    const a = await manager.create(["example.test"]);
    const b = await manager.create(["example.test"]);
    assert.notEqual(a.id, b.id);
    assert.notEqual(a.downloadsDir, b.downloadsDir);
  } finally {
    await manager.closeAll();
  }
});

test("expired sessions are swept and destroyed automatically", async () => {
  const { factory, closedIds } = createFakeBrowserContextFactory();
  const manager = new SessionManager({ ttlMs: 20, createBrowserContext: factory, maxSessions: 8 });
  const session = await manager.create(["example.test"]);

  await new Promise((resolve) => setTimeout(resolve, 150));

  assert.equal(manager.get(session.id), undefined);
  assert.deepEqual([...closedIds], ["0"]);
  await manager.closeAll();
});

test("closeAll() destroys every still-open session", async () => {
  const { factory, closedIds } = createFakeBrowserContextFactory();
  const manager = new SessionManager({ ttlMs: 60_000, createBrowserContext: factory, maxSessions: 8 });
  await manager.create(["example.test"]);
  await manager.create(["example.test"]);

  await manager.closeAll();

  assert.equal(closedIds.length, 2);
});

test("create() refuses past the ceiling, and the open sessions keep working", async () => {
  const { factory } = createFakeBrowserContextFactory();
  const manager = new SessionManager({ ttlMs: 60_000, createBrowserContext: factory, maxSessions: 2 });
  try {
    const first = await manager.create(["example.test"]);
    await manager.create(["example.test"]);

    await assert.rejects(manager.create(["example.test"]), SessionLimitError);

    assert.notEqual(manager.get(first.id), undefined);
  } finally {
    await manager.closeAll();
  }
});

test("create() takes a slot again once a session is closed", async () => {
  const { factory } = createFakeBrowserContextFactory();
  const manager = new SessionManager({ ttlMs: 60_000, createBrowserContext: factory, maxSessions: 1 });
  try {
    const only = await manager.create(["example.test"]);
    await manager.destroy(only.id);

    const next = await manager.create(["example.test"]);
    assert.equal(next.status, "created");
  } finally {
    await manager.closeAll();
  }
});

test("create() counts a browser still launching, so two requests at once cannot both take the last slot", async () => {
  const { factory } = createFakeBrowserContextFactory();
  const manager = new SessionManager({ ttlMs: 60_000, createBrowserContext: factory, maxSessions: 1 });
  try {
    const results = await Promise.allSettled([manager.create(["example.test"]), manager.create(["example.test"])]);

    assert.equal(results.filter((result) => result.status === "fulfilled").length, 1);
    assert.ok(results.some((result) => result.status === "rejected" && result.reason instanceof SessionLimitError));
  } finally {
    await manager.closeAll();
  }
});

test("create() makes room by closing what has expired before it refuses", async () => {
  const { factory } = createFakeBrowserContextFactory();
  const manager = new SessionManager({ ttlMs: 1, createBrowserContext: factory, maxSessions: 1 });
  try {
    await manager.create(["example.test"]);
    await new Promise((resolve) => setTimeout(resolve, 5));

    const next = await manager.create(["example.test"]);
    assert.equal(next.status, "created");
  } finally {
    await manager.closeAll();
  }
});
