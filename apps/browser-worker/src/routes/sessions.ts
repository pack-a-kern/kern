import { Router, type NextFunction, type Request, type Response } from "express";
import { notImplementedPayload } from "../notImplemented.js";
import {
  PortalAutomationNotImplementedError,
  type PortalAuthenticator,
  type PortalAuthOutcome,
} from "../portal/portalAuthenticator.js";
import type { SessionManager } from "../sessions/sessionManager.js";
import type { BrowserSession, SessionStatus } from "../sessions/types.js";

function notFoundPayload(id: string): { error: "not_found"; message: string } {
  return { error: "not_found", message: `no SPV session ${id}` };
}

function invalidStatePayload(expected: SessionStatus, actual: SessionStatus): {
  error: "invalid_state";
  message: string;
} {
  return {
    error: "invalid_state",
    message: `session is in state '${actual}', expected '${expected}'`,
  };
}

function badRequestPayload(message: string): { error: "bad_request"; message: string } {
  return { error: "bad_request", message };
}

/** Express types `req.params.id` as `string | string[] | undefined`; a route
 * matching `:id` always yields a single string, but a non-string value is
 * treated the same as "not found" rather than assumed away. */
function sessionIdParam(req: Request): string | undefined {
  const { id } = req.params;
  return typeof id === "string" ? id : undefined;
}

/**
 * Blocks the four portal-action routes at an unknown/destroyed session id
 * before they ever reach the (currently 501) handler. Session existence is
 * generic lifecycle validation, not ANAF-specific behaviour.
 */
function requireSession(
  sessionManager: SessionManager,
): (req: Request, res: Response, next: NextFunction) => void {
  return (req: Request, res: Response, next: NextFunction): void => {
    const id = sessionIdParam(req);
    const session = id !== undefined ? sessionManager.get(id) : undefined;
    if (session === undefined) {
      res.status(404).json(notFoundPayload(id ?? "unknown"));
      return;
    }
    next();
  };
}

function notImplementedHandler(action: string): (req: Request, res: Response) => void {
  return (_req: Request, res: Response): void => {
    res.status(501).json(notImplementedPayload(action));
  };
}

function sessionOrNotFound(sessionManager: SessionManager, req: Request, res: Response): BrowserSession | undefined {
  const id = sessionIdParam(req);
  const session = id !== undefined ? sessionManager.get(id) : undefined;
  if (session === undefined) {
    res.status(404).json(notFoundPayload(id ?? "unknown"));
    return undefined;
  }
  return session;
}

/** Maps a `PortalAuthOutcome` to the persisted session status and the response body — the one
 * place that mapping is defined, shared by both `/credentials` and `/challenge`. */
function applyOutcome(
  sessionManager: SessionManager,
  sessionId: string,
  res: Response,
  outcome: PortalAuthOutcome,
): void {
  sessionManager.updateStatus(sessionId, outcome.outcome);

  if (outcome.outcome === "failed") {
    res.status(200).json({ status: "failed", reason: outcome.reason });
    return;
  }
  res.status(200).json({ status: outcome.outcome });
}

export function createSessionsRouter(sessionManager: SessionManager, portalAuthenticator: PortalAuthenticator): Router {
  const router = Router();

  router.post("/v1/spv/sessions", async (_req: Request, res: Response): Promise<void> => {
    const session = await sessionManager.create();
    res.status(201).json({
      id: session.id,
      status: session.status,
      createdAt: session.createdAt.toISOString(),
      expiresAt: session.expiresAt.toISOString(),
    });
  });

  router.post(
    "/v1/spv/sessions/:id/credentials",
    async (req: Request, res: Response): Promise<void> => {
      const session = sessionOrNotFound(sessionManager, req, res);
      if (session === undefined) {
        return;
      }
      if (session.status !== "created") {
        res.status(409).json(invalidStatePayload("created", session.status));
        return;
      }
      const { username, password } = req.body as { username?: unknown; password?: unknown };
      if (typeof username !== "string" || typeof password !== "string") {
        res.status(400).json(badRequestPayload("username and password are required strings"));
        return;
      }

      try {
        const outcome = await portalAuthenticator.submitCredentials(session.id, { username, password });
        applyOutcome(sessionManager, session.id, res, outcome);
      } catch (error) {
        if (error instanceof PortalAutomationNotImplementedError) {
          res.status(501).json(notImplementedPayload("credentials"));
          return;
        }
        throw error;
      }
    },
  );

  router.post(
    "/v1/spv/sessions/:id/challenge",
    async (req: Request, res: Response): Promise<void> => {
      const session = sessionOrNotFound(sessionManager, req, res);
      if (session === undefined) {
        return;
      }
      if (session.status !== "awaiting_challenge") {
        res.status(409).json(invalidStatePayload("awaiting_challenge", session.status));
        return;
      }
      const { code } = req.body as { code?: unknown };
      if (typeof code !== "string") {
        res.status(400).json(badRequestPayload("code is a required string"));
        return;
      }

      try {
        const outcome = await portalAuthenticator.submitChallengeCode(session.id, code);
        applyOutcome(sessionManager, session.id, res, outcome);
      } catch (error) {
        if (error instanceof PortalAutomationNotImplementedError) {
          res.status(501).json(notImplementedPayload("challenge"));
          return;
        }
        throw error;
      }
    },
  );

  router.post(
    "/v1/spv/sessions/:id/actions/sync",
    requireSession(sessionManager),
    notImplementedHandler("sync"),
  );

  router.delete("/v1/spv/sessions/:id", async (req: Request, res: Response): Promise<void> => {
    const id = sessionIdParam(req);
    const destroyed = id !== undefined && (await sessionManager.destroy(id));
    if (!destroyed) {
      res.status(404).json(notFoundPayload(id ?? "unknown"));
      return;
    }
    res.status(204).send();
  });

  return router;
}
