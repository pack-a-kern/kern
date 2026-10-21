import { readFileSync, unwatchFile, watchFile } from "node:fs";
import { createServer as createHttpServer, type RequestListener, type Server as HttpServer } from "node:http";
import { createServer as createHttpsServer, type Server as HttpsServer } from "node:https";
import { logger } from "./logger.js";

/*
 * The worker's listener: HTTPS when it is given a certificate, which compose always does
 * (compose.services/browser.yaml) — every request carries the worker's token and, during a sign-in,
 * the person's SPV credentials, so on a network they are not sent in the clear.
 *
 * The certificate is issued and renewed by the stack's own CA (docker/certs). A renewed one is loaded
 * into the running server with `setSecureContext`, for every connection made after it: a restart
 * would end every open browser session, and with it any sign-in waiting on the person's phone.
 */

export interface TlsFiles {
  cert: string;
  key: string;
}

/** The certificate's paths from the environment, or nothing when the worker is not given one. */
export function tlsFilesFrom(env: NodeJS.ProcessEnv): TlsFiles | null {
  const cert = env.BROWSER_TLS_CERT;
  const key = env.BROWSER_TLS_KEY;

  return cert !== undefined && cert !== "" && key !== undefined && key !== "" ? { cert, key } : null;
}

export type ReadPair = (files: TlsFiles) => { cert: Buffer; key: Buffer };

const readPairFromDisk: ReadPair = (files) => ({
  cert: readFileSync(files.cert),
  key: readFileSync(files.key),
});

/**
 * Loads a changed certificate into a running server. The key is read with it, and docker/certs writes
 * the key before the certificate, so a changed certificate always finds its own key beside it. A pair
 * that fails to load leaves the current one serving, and is tried again at the next change.
 */
export function reloadOnChange(
  server: { setSecureContext: (options: { cert: Buffer; key: Buffer }) => void },
  files: TlsFiles,
  readPair: ReadPair = readPairFromDisk,
): void {
  try {
    server.setSecureContext(readPair(files));
    logger.info("tls_certificate_reloaded", { event: "tls_certificate_reloaded" });
  } catch {
    logger.warn("tls_certificate_reload_failed", { event: "tls_certificate_reload_failed" });
  }
}

export interface Listener {
  server: HttpServer | HttpsServer;
  /** Stops watching the certificate, for a shutdown. */
  stopWatching: () => void;
}

/** HTTPS with the given certificate, watched for renewal every `watchIntervalMs`; plain HTTP without one. */
export function createListener(app: RequestListener, files: TlsFiles | null, watchIntervalMs: number): Listener {
  if (files === null) {
    return { server: createHttpServer(app), stopWatching: (): void => undefined };
  }

  const server = createHttpsServer(readPairFromDisk(files), app);

  watchFile(files.cert, { interval: watchIntervalMs, persistent: false }, (current, previous) => {
    if (current.mtimeMs !== previous.mtimeMs) {
      reloadOnChange(server, files);
    }
  });

  return {
    server,
    stopWatching: (): void => {
      unwatchFile(files.cert);
    },
  };
}
