import { randomUUID } from "node:crypto";
import { logger } from "../logger.js";
import type { BrowserContextFactory, BrowserContextHandle } from "./browserContextFactory.js";
import type { SpvSession, SpvSessionStatus } from "./types.js";

export interface SessionManagerOptions {
  readonly ttlMs: number;
  readonly createBrowserContext: BrowserContextFactory;
}

interface TrackedSession {
  readonly session: SpvSession;
  readonly handle: BrowserContextHandle;
}

const MAX_SWEEP_INTERVAL_MS = 60_000;

/**
 * Owns the ephemeral browser context behind every SPV session: creation,
 * lookup, explicit destruction, and TTL expiry (`BLUEPRINT.md` §3.4, §13.2 step
 * 11 — "sesiunea se distruge conform policy"). Holds no ANAF-specific state;
 * that starts at Milestone 10.
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

  async create(): Promise<SpvSession> {
    const handle = await this.createBrowserContext();
    const createdAt = new Date();
    const session: SpvSession = {
      id: randomUUID(),
      status: "created",
      createdAt,
      expiresAt: new Date(createdAt.getTime() + this.ttlMs),
      downloadsDir: handle.downloadsDir,
    };
    this.sessions.set(session.id, { session, handle });
    logger.info("spv_session_created", { event: "spv_session_created", sessionId: session.id });
    return session;
  }

  get(id: string): SpvSession | undefined {
    return this.sessions.get(id)?.session;
  }

  /**
   * Moves a session's lifecycle state forward (`credentials`/`challenge` route handlers, after a
   * `PortalAuthenticator` call resolves). Returns the updated session, or `undefined` if `id` is
   * unknown or was destroyed/expired concurrently — a caller who already validated `get(id)`
   * immediately before still re-checks this return value rather than assuming success.
   */
  updateStatus(id: string, status: SpvSessionStatus): SpvSession | undefined {
    const tracked = this.sessions.get(id);
    if (tracked === undefined) {
      return undefined;
    }
    const updated: SpvSession = { ...tracked.session, status };
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
    logger.info("spv_session_destroyed", { event: "spv_session_destroyed", sessionId: id });
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
