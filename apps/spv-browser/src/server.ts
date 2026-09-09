import { createServer } from "node:http";
import { createApp } from "./app.js";
import { logger } from "./logger.js";
import { UnimplementedPortalAuthenticator } from "./portal/portalAuthenticator.js";
import { createPlaywrightBrowserContext } from "./sessions/browserContextFactory.js";
import { SessionManager } from "./sessions/sessionManager.js";

const DEFAULT_PORT = 8090;
const DEFAULT_SESSION_TTL_MS = 15 * 60 * 1000;

const port = Number(process.env.PORT ?? DEFAULT_PORT);
const sessionTtlMs = Number(process.env.SPV_SESSION_TTL_MS ?? DEFAULT_SESSION_TTL_MS);

const sessionManager = new SessionManager({
  ttlMs: sessionTtlMs,
  createBrowserContext: createPlaywrightBrowserContext,
});

const app = createApp(sessionManager, new UnimplementedPortalAuthenticator(), process.env.SPV_BROWSER_AUTH_TOKEN);
const server = createServer(app);

server.listen(port, () => {
  logger.info("server_started", { event: "server_started", port });
});

let shuttingDown = false;

function shutdown(signal: string): void {
  if (shuttingDown) {
    return;
  }
  shuttingDown = true;
  logger.info(`server_shutting_down (${signal})`, { event: "server_shutting_down" });
  server.close();
  void sessionManager.closeAll().then(() => {
    process.exit(0);
  });
}

process.on("SIGTERM", () => {
  shutdown("SIGTERM");
});
process.on("SIGINT", () => {
  shutdown("SIGINT");
});
