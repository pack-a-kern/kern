import { createServer } from "node:http";
import { createApp } from "./app.js";
import { logger } from "./logger.js";
import { createPlaywrightBrowserContext } from "./sessions/browserContextFactory.js";
import { SessionManager } from "./sessions/sessionManager.js";

const DEFAULT_PORT = 8090;
const DEFAULT_SESSION_TTL_MS = 15 * 60 * 1000;

const DEFAULT_CONCURRENCY = 1;
const DEFAULT_MAX_WAITING = 8;

const port = Number(process.env.PORT ?? DEFAULT_PORT);
const sessionTtlMs = Number(process.env.BROWSER_SESSION_TTL_MS ?? DEFAULT_SESSION_TTL_MS);

/*
 * `token` unless the deployment says otherwise.
 *
 * Defaulting the other way would mean a missing variable silently removes the only thing standing
 * in front of a browser — and a mistake that opens something should never be the quiet one.
 */
const authMode = process.env.BROWSER_AUTH_MODE === "network" ? "network" : "token";

const sessionManager = new SessionManager({
  ttlMs: sessionTtlMs,
  createBrowserContext: createPlaywrightBrowserContext,
});

const app = createApp(sessionManager, {
  authMode,
  internalAuthToken: process.env.BROWSER_AUTH_TOKEN,
  // One browser at a time by default: each holds a Chromium and several hundred megabytes, and a
  // worker that accepts more than it can run is slower than one that says so.
  concurrency: Number(process.env.BROWSER_CONCURRENCY ?? DEFAULT_CONCURRENCY),
  maxWaiting: Number(process.env.BROWSER_MAX_WAITING ?? DEFAULT_MAX_WAITING),
});
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
