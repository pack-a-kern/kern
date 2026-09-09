import express, { type Express } from "express";
import { correlationIdMiddleware } from "./middleware/correlationId.js";
import { errorHandler } from "./middleware/errorHandler.js";
import { createInternalAuthMiddleware } from "./middleware/internalAuth.js";
import { requestLoggingMiddleware } from "./middleware/requestLogging.js";
import type { PortalAuthenticator } from "./portal/portalAuthenticator.js";
import { createHealthRouter } from "./routes/health.js";
import { createSessionsRouter } from "./routes/sessions.js";
import type { SessionManager } from "./sessions/sessionManager.js";

export function createApp(
  sessionManager: SessionManager,
  portalAuthenticator: PortalAuthenticator,
  internalAuthToken: string | undefined,
): Express {
  const app = express();
  app.disable("x-powered-by");
  app.use(correlationIdMiddleware);
  app.use(requestLoggingMiddleware);
  app.use(express.json());
  app.use(createHealthRouter());
  app.use("/v1/spv", createInternalAuthMiddleware(internalAuthToken));
  app.use(createSessionsRouter(sessionManager, portalAuthenticator));
  app.use(errorHandler);
  return app;
}
