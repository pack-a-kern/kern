import { randomUUID } from "node:crypto";
import { logger } from "../logger.js";
import type { AllowList, RunContext } from "../playbook/types.js";
import type { BrowserContextFactory, BrowserContextHandle } from "./browserContextFactory.js";
import type { BrowserSession, SessionStatus } from "./types.js";

export interface SessionManagerOptions {
  readonly ttlMs: number;
  readonly createBrowserContext: BrowserContextFactory;
  /**
   * How many browsers may be open at once, across every caller and every playbook.
   *
   * Each session is its own Chromium and a few hundred megabytes, and nothing upstream bounds how many
   * are asked for: one taxpayer can have several connections, an installation many taxpayers, and a
   * future integration will bring its own. Without a ceiling here, enough of them filled the
   * container's memory and took every caller's browser down at once. With it, the one past the limit
   * is told the worker is at capacity and every open session keeps working.
   */
  readonly maxSessions: number;
}

/** Thrown by `create()` when `maxSessions` browsers are already open — answered as `at_capacity`. */
export class SessionLimitError extends Error {
  public constructor(public readonly limit: number) {
    super(`The worker already has ${String(limit)} browser sessions open.`);
    this.name = "SessionLimitError";
  }
}

interface TrackedSession {
  readonly session: BrowserSession;
  readonly handle: BrowserContextHandle;
}

const MAX_SWEEP_INTERVAL_MS = 60_000;

/**
 * Owns the ephemeral browser context behind every session: creation, lookup, explicit destruction,
 * and expiry at the TTL.
 *
 * One browser and one context per session, never reused between them — a session's cookies and
 * storage are the whole reason it is held open between calls, and two callers sharing a context
 * would share those.
 *
 * Nothing here knows what a session is for. Which site it visits, and what it does there, arrives
 * with each run as a playbook.
 */
export class SessionManager {
  private readonly sessions = new Map<string, TrackedSession>();
  private readonly ttlMs: number;
  private readonly createBrowserContext: BrowserContextFactory;
  private readonly maxSessions: number;
  /** Browsers being launched and not yet in `sessions` — counted, so two requests at once cannot both take the last slot. */
  private launching = 0;
  private readonly sweepTimer: NodeJS.Timeout;

  constructor(options: SessionManagerOptions) {
    this.ttlMs = options.ttlMs;
    this.createBrowserContext = options.createBrowserContext;
    this.maxSessions = options.maxSessions;
    this.sweepTimer = setInterval(
      () => void this.sweepExpired(),
      Math.min(this.ttlMs, MAX_SWEEP_INTERVAL_MS),
    );
    this.sweepTimer.unref();
  }

  async create(allow: AllowList, context?: RunContext): Promise<BrowserSession> {
    if (this.sessions.size + this.launching >= this.maxSessions) {
      // An expired session the next sweep would close is not a reason to refuse one that is wanted now.
      await this.sweepExpired();
    }

    if (this.sessions.size + this.launching >= this.maxSessions) {
      throw new SessionLimitError(this.maxSessions);
    }

    this.launching++;
    let handle: BrowserContextHandle;

    try {
      handle = await this.createBrowserContext(allow, context);
    } finally {
      this.launching--;
    }

    const createdAt = new Date();
    const session: BrowserSession = {
      id: randomUUID(),
      status: "created",
      createdAt,
      expiresAt: new Date(createdAt.getTime() + this.ttlMs),
      downloadsDir: handle.downloadsDir,
    };
    this.sessions.set(session.id, { session, handle });
    logger.info("session_created", { event: "session_created", sessionId: session.id });
    return session;
  }

  get(id: string): BrowserSession | undefined {
    return this.sessions.get(id)?.session;
  }

  /**
   * Pushes a session's expiry forward, because it has just been used.
   *
   * The TTL is idleness, not age. A sitting that dies fifteen minutes after it began would end in the
   * middle of somebody's work however recently they acted; one that dies fifteen minutes after its last
   * use ends when they stop. Nothing is kept alive by being looked at — only by being driven.
   *
   * No absolute cap, deliberately. A session renewed indefinitely is still only as good as the portal
   * behind it, and the portal ending its own session is a case the playbook already recognises and
   * reports as `session_lost`. A second clock here would mostly cut sittings short for no gain.
   */
  renew(id: string): BrowserSession | undefined {
    const tracked = this.sessions.get(id);

    if (tracked === undefined) {
      return undefined;
    }

    const renewed: BrowserSession = { ...tracked.session, expiresAt: new Date(Date.now() + this.ttlMs) };
    this.sessions.set(id, { session: renewed, handle: tracked.handle });

    return renewed;
  }

  /**
   * The browser behind a session, for whoever is driving it.
   *
   * Separate from `get` because the two answer different questions — "does this session exist and
   * when does it expire" is something a route may say out loud, and the context is not.
   */
  handleOf(id: string): BrowserContextHandle | undefined {
    return this.sessions.get(id)?.handle;
  }

  /** What is open, for a screen that asks. Never the contexts, only what they are. */
  list(): readonly BrowserSession[] {
    return [...this.sessions.values()].map((tracked) => tracked.session);
  }

  /**
   * Moves a session's lifecycle state forward (`credentials`/`challenge` route handlers, after a
   * `PortalAuthenticator` call resolves). Returns the updated session, or `undefined` if `id` is
   * unknown or was destroyed/expired concurrently — a caller who already validated `get(id)`
   * immediately before still re-checks this return value rather than assuming success.
   */
  updateStatus(id: string, status: SessionStatus): BrowserSession | undefined {
    const tracked = this.sessions.get(id);
    if (tracked === undefined) {
      return undefined;
    }
    const updated: BrowserSession = { ...tracked.session, status };
    this.sessions.set(id, { session: updated, handle: tracked.handle });
    return updated;
  }

  async destroy(id: string): Promise<boolean> {
    const tracked = this.sessions.get(id);
    if (tracked === undefined) {
      return false;
    }
    this.sessions.delete(id);
    await tracked.handle.close();
    logger.info("session_destroyed", { event: "session_destroyed", sessionId: id });
    return true;
  }

  async closeAll(): Promise<void> {
    clearInterval(this.sweepTimer);
    await Promise.all([...this.sessions.keys()].map((id) => this.destroy(id)));
  }

  private async sweepExpired(): Promise<void> {
    const now = Date.now();
    const expiredIds = [...this.sessions.values()]
      .filter((tracked) => tracked.session.expiresAt.getTime() <= now)
      .map((tracked) => tracked.session.id);
    await Promise.all(expiredIds.map((id) => this.destroy(id)));
  }
}
