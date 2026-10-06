import express, { type Express } from "express";
import { correlationIdMiddleware } from "./middleware/correlationId.js";
import { errorHandler } from "./middleware/errorHandler.js";
import { createInternalAuthMiddleware } from "./middleware/internalAuth.js";
import { requestLoggingMiddleware } from "./middleware/requestLogging.js";
import { RunQueue } from "./runs/queue.js";
import { Stats } from "./runs/stats.js";
import { createHealthRouter } from "./routes/health.js";
import { createRunRouter } from "./routes/runs.js";
import type { SessionManager } from "./sessions/sessionManager.js";

export interface AppOptions {
  /**
   * How `/v1` is protected.
   *
   * `token` demands a bearer token and refuses every request while none is configured. `network`
   * trusts whatever can reach the port, which is defensible when nothing but one caller can — and
   * is a sentence somebody has to write, so no deployment arrives there by leaving a variable
   * unset.
   */
  readonly authMode: "token" | "network";
  readonly internalAuthToken: string | undefined;
  readonly concurrency: number;
  readonly maxWaiting: number;
}

export function createApp(sessionManager: SessionManager, options: AppOptions): Express {
  const app = express();
  app.disable("x-powered-by");
  app.use(correlationIdMiddleware);
  app.use(requestLoggingMiddleware);
  app.use(express.json());
  // Unauthenticated, and the only thing that is: a health probe runs before any secret is
  // configured and has nothing to say about what is inside.
  app.use(createHealthRouter());

  if (options.authMode === "token") {
    app.use("/v1", createInternalAuthMiddleware(options.internalAuthToken));
  }

  app.use(createRunRouter(sessionManager, new RunQueue(options.concurrency, options.maxWaiting), new Stats()));
  app.use(errorHandler);
  return app;
}
