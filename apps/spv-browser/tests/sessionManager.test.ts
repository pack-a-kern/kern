import assert from "node:assert/strict";
import { test } from "node:test";
import { SessionManager } from "../src/sessions/sessionManager.js";
import { createFakeBrowserContextFactory } from "./fakeBrowserContextFactory.js";

test("create() returns a session created now, expiring after the ttl", async () => {
  const { factory } = createFakeBrowserContextFactory();
  const manager = new SessionManager({ ttlMs: 60_000, createBrowserContext: factory });
  try {
    const session = await manager.create();
    assert.equal(session.status, "created");
    assert.ok(session.expiresAt.getTime() > session.createdAt.getTime());
  } finally {
    await manager.closeAll();
  }
});

test("get() returns undefined for an unknown id", async () => {
  const { factory } = createFakeBrowserContextFactory();
  const manager = new SessionManager({ ttlMs: 60_000, createBrowserContext: factory });
  try {
    assert.equal(manager.get("does-not-exist"), undefined);
  } finally {
    await manager.closeAll();
  }
});

test("destroy() closes the browser context and removes the session", async () => {
  const { factory, closedIds } = createFakeBrowserContextFactory();
  const manager = new SessionManager({ ttlMs: 60_000, createBrowserContext: factory });
  const session = await manager.create();

  const destroyed = await manager.destroy(session.id);

  assert.equal(destroyed, true);
  assert.equal(manager.get(session.id), undefined);
  assert.deepEqual([...closedIds], ["0"]);
});

test("destroy() returns false for an unknown id", async () => {
  const { factory } = createFakeBrowserContextFactory();
  const manager = new SessionManager({ ttlMs: 60_000, createBrowserContext: factory });
  try {
    assert.equal(await manager.destroy("does-not-exist"), false);
  } finally {
    await manager.closeAll();
  }
});

test("each session is isolated — distinct id and downloads dir, no shared profile", async () => {
  const { factory } = createFakeBrowserContextFactory();
  const manager = new SessionManager({ ttlMs: 60_000, createBrowserContext: factory });
  try {
    const a = await manager.create();
    const b = await manager.create();
    assert.notEqual(a.id, b.id);
    assert.notEqual(a.downloadsDir, b.downloadsDir);
  } finally {
    await manager.closeAll();
  }
});

test("expired sessions are swept and destroyed automatically", async () => {
  const { factory, closedIds } = createFakeBrowserContextFactory();
  const manager = new SessionManager({ ttlMs: 20, createBrowserContext: factory });
  const session = await manager.create();

  await new Promise((resolve) => setTimeout(resolve, 150));

  assert.equal(manager.get(session.id), undefined);
  assert.deepEqual([...closedIds], ["0"]);
  await manager.closeAll();
});

test("closeAll() destroys every still-open session", async () => {
  const { factory, closedIds } = createFakeBrowserContextFactory();
  const manager = new SessionManager({ ttlMs: 60_000, createBrowserContext: factory });
  await manager.create();
  await manager.create();

  await manager.closeAll();

  assert.equal(closedIds.length, 2);
});
