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
 * Guards every `/v1` route — never `/health` — with a shared-secret bearer token.
 *
 * A shared secret rather than mTLS or a signed JWT: this worker is reached by one caller over one
 * network, and the heavier schemes buy nothing there while costing a certificate lifecycle nobody
 * asked for.
 *
 * **Fails closed.** A worker in token mode with no token configured refuses everything, rather than
 * accepting whatever arrives — a missing variable must never be the thing that opens a service. A
 * deployment that wants no token says so with `BROWSER_AUTH_MODE`, and then this middleware is not
 * mounted at all.
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
