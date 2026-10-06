import type { NextFunction, Request, Response } from "express";
import { logger } from "../logger.js";

/**
 * Logs one line per request: method, path, status, duration. Never the
 * request or response body — `docs/AGENTS.md` §1 rule 12 forbids logging a
 * request body on a sensitive endpoint, and every endpoint here can carry
 * one (`/credentials`, `/challenge`).
 */
export function requestLoggingMiddleware(req: Request, res: Response, next: NextFunction): void {
  const startedAt = process.hrtime.bigint();

  res.on("finish", () => {
    const durationMs = Number(process.hrtime.bigint() - startedAt) / 1_000_000;
    logger.info("http_request", {
      event: "http_request",
      method: req.method,
      path: req.path,
      statusCode: res.statusCode,
      durationMs: Math.round(durationMs * 100) / 100,
    });
  });

  next();
}
