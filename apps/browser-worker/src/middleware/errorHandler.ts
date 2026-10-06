import type { NextFunction, Request, Response } from "express";
import { logger } from "../logger.js";

const SENSITIVE_PATH_SUFFIXES = ["/credentials", "/challenge"];

function isSensitivePath(path: string): boolean {
  return SENSITIVE_PATH_SUFFIXES.some((suffix) => path.endsWith(suffix));
}

/**
 * Express error-handling middleware (identified by its 4-arg signature).
 * Never logs the error's own message for `/credentials` or `/challenge` —
 * a JSON body-parse error on those paths can otherwise carry a fragment of
 * the submitted credential into the error text (`docs/AGENTS.md` §1 rule 12).
 */
export function errorHandler(
  err: unknown,
  req: Request,
  res: Response,
  next: NextFunction,
): void {
  if (res.headersSent) {
    next(err);
    return;
  }

  const sensitive = isSensitivePath(req.path);
  const message =
    !sensitive && err instanceof Error ? `request_failed: ${err.message}` : "request_failed";
  logger.error(message, {
    event: "request_failed",
    method: req.method,
    path: req.path,
  });

  const isBodyParseError = err instanceof SyntaxError && "body" in err;
  res.status(isBodyParseError ? 400 : 500).json({
    error: isBodyParseError ? "bad_request" : "internal_error",
  });
}
