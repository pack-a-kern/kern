import { randomUUID } from "node:crypto";
import type { NextFunction, Request, Response } from "express";
import { runWithCorrelationId } from "../logger.js";

export const REQUEST_ID_HEADER = "X-Request-Id";

/**
 * Reuses an inbound `X-Request-Id` (Laravel/an internal caller may already
 * have one), otherwise mints a new one. Echoed back on the response and
 * available to every log line emitted while handling the request via
 * `getCorrelationId()`.
 */
export function correlationIdMiddleware(req: Request, res: Response, next: NextFunction): void {
  const incoming = req.header(REQUEST_ID_HEADER);
  const correlationId = incoming !== undefined && incoming.length > 0 ? incoming : randomUUID();
  res.setHeader(REQUEST_ID_HEADER, correlationId);
  runWithCorrelationId(correlationId, () => {
    next();
  });
}
