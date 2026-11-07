import { createApp } from "./app.js";
import { DEFAULT_MAX_SOURCE_BYTES } from "./playbook/interpreter.js";
import { logger } from "./logger.js";
import { createPlaywrightBrowserContext } from "./sessions/browserContextFactory.js";
import { SessionManager } from "./sessions/sessionManager.js";
import { createListener, tlsFilesFrom } from "./tlsListener.js";

const DEFAULT_PORT = 8090;
const DEFAULT_SESSION_TTL_MS = 15 * 60 * 1000;

const DEFAULT_CONCURRENCY = 1;
const DEFAULT_MAX_WAITING = 8;
/*
 * Measured on 2026-10-20: the worker idles at about 340 MB and an empty Chromium adds about 45; one on a
 * real portal page is several times that. Four leaves the 2 GB the container is given room for all of
 * them and the one-shot runs beside them. Raise it with the container's memory, not on its own.
 */
const DEFAULT_MAX_SESSIONS = 4;
// How often the certificate is checked for a renewal. It is renewed a month before it ends, so this
// only has to be often enough that nobody waits on it.
const DEFAULT_TLS_WATCH_MS = 5 * 60 * 1000;

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
  maxSessions: Number(process.env.BROWSER_MAX_SESSIONS ?? DEFAULT_MAX_SESSIONS),
});

const app = createApp(sessionManager, {
  authMode,
  internalAuthToken: process.env.BROWSER_AUTH_TOKEN,
  // One browser at a time by default: each holds a Chromium and several hundred megabytes, and a
  // worker that accepts more than it can run is slower than one that says so.
  concurrency: Number(process.env.BROWSER_CONCURRENCY ?? DEFAULT_CONCURRENCY),
  maxWaiting: Number(process.env.BROWSER_MAX_WAITING ?? DEFAULT_MAX_WAITING),
  maxSourceBytes: Number(process.env.BROWSER_MAX_SOURCE_BYTES ?? DEFAULT_MAX_SOURCE_BYTES),
});
const tlsFiles = tlsFilesFrom(process.env);
const { server, stopWatching } = createListener(
  app,
  tlsFiles,
  Number(process.env.BROWSER_TLS_WATCH_MS ?? DEFAULT_TLS_WATCH_MS),
);

server.listen(port, () => {
  logger.info(tlsFiles === null ? "server_started" : "server_started_tls", { event: "server_started", port });
});

let shuttingDown = false;

function shutdown(signal: string): void {
  if (shuttingDown) {
    return;
  }
  shuttingDown = true;
  logger.info(`server_shutting_down (${signal})`, { event: "server_shutting_down" });
  stopWatching();
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
