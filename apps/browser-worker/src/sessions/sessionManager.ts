import { randomUUID } from "node:crypto";
import { logger } from "../logger.js";
import type { RunContext } from "../playbook/types.js";
import type { BrowserContextFactory, BrowserContextHandle } from "./browserContextFactory.js";
import type { BrowserSession, SessionStatus } from "./types.js";

export interface SessionManagerOptions {
  readonly ttlMs: number;
  readonly createBrowserContext: BrowserContextFactory;
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
  private readonly sweepTimer: NodeJS.Timeout;

  constructor(options: SessionManagerOptions) {
    this.ttlMs = options.ttlMs;
    this.createBrowserContext = options.createBrowserContext;
    this.sweepTimer = setInterval(
      () => void this.sweepExpired(),
      Math.min(this.ttlMs, MAX_SWEEP_INTERVAL_MS),
    );
    this.sweepTimer.unref();
  }

  async create(context?: RunContext): Promise<BrowserSession> {
    const handle = await this.createBrowserContext(context);
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
