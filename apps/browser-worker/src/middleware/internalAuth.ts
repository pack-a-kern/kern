import { timingSafeEqual } from "node:crypto";
import type { NextFunction, Request, Response } from "express";

const UNAUTHORIZED_MISSING = { error: "unauthorized", message: "Missing or malformed Authorization header." };
const UNAUTHORIZED_INVALID = { error: "unauthorized", message: "Invalid service authentication token." };
const UNAUTHORIZED_UNCONFIGURED = { error: "unauthorized", message: "Service authentication is not configured." };

function constantTimeEquals(a: string, b: string): boolean {
  const bufferA = Buffer.from(a);
  const bufferB = Buffer.from(b);
  if (bufferA.length !== bufferB.length) {
    // Deliberately still compares against something of A's own length so this branch takes
    // comparable time to the equal-length path, rather than returning immediately.
    timingSafeEqual(bufferA, bufferA);
    return false;
  }
  return timingSafeEqual(bufferA, bufferB);
}

/**
 * Guards every `/v1/spv/*` route (never `/health`) with a shared-secret bearer token —
 * `BLUEPRINT.md` §13.4's internal contract, reachable only from Laravel over the internal network,
 * never directly by the browser/PWA. This is the identical shared-secret compromise
 * `apps/processing`'s `internal_auth.py` documents (mTLS/signed-JWT was never decided anywhere in
 * this codebase, and standing one up here would itself be inventing an unverified contract,
 * `docs/AGENTS.md` §1 rule 1) — fails closed when the expected token is unset, exactly like that
 * module.
 */
export function createInternalAuthMiddleware(
  expectedToken: string | undefined,
): (req: Request, res: Response, next: NextFunction) => void {
  return (req: Request, res: Response, next: NextFunction): void => {
    const header = req.header("authorization");
    if (!header?.startsWith("Bearer ")) {
      res.status(401).json(UNAUTHORIZED_MISSING);
      return;
    }

    if (expectedToken === undefined || expectedToken === "") {
      res.status(401).json(UNAUTHORIZED_UNCONFIGURED);
      return;
    }

    const provided = header.slice("Bearer ".length);
    if (!constantTimeEquals(provided, expectedToken)) {
      res.status(401).json(UNAUTHORIZED_INVALID);
      return;
    }

    next();
  };
}
