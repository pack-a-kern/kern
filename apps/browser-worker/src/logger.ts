/**
 * Structured JSON logging with a request-correlation id (`BLUEPRINT.md` §23).
 *
 * As on the processing-plane side, log lines carry only a fixed, reviewed
 * set of fields. Callers cannot smuggle a credential, OTP code, or document
 * body into a log line through `extra` — unlisted keys are silently dropped,
 * not serialized (`docs/AGENTS.md` §8).
 */

import { AsyncLocalStorage } from "node:async_hooks";

const correlationStorage = new AsyncLocalStorage<string>();

export function runWithCorrelationId<T>(correlationId: string, fn: () => T): T {
  return correlationStorage.run(correlationId, fn);
}

export function getCorrelationId(): string | undefined {
  return correlationStorage.getStore();
}

const ALLOWED_FIELDS = [
  "event",
  "method",
  "path",
  "statusCode",
  "durationMs",
  "sessionId",
  "port",
  /*
   * Why a run failed, for an operator who would otherwise read "run_failed" and have to reproduce it.
   *
   * On the allowlist rather than smuggled in as `message`, which is the log line's own event name. The
   * value is the same redacted text the response body carries — `Secrets.redact()` has already been over
   * it, because Playwright quotes a value back in the error it throws and that error is this string.
   */
  "failure",
  /* Where the browser was when something failed. Redacted like everything else on its way out. */
  "url",
] as const;

type AllowedField = (typeof ALLOWED_FIELDS)[number];

export type LogFields = Partial<Record<AllowedField, string | number>>;

type LogLevel = "info" | "warn" | "error";

function write(level: LogLevel, message: string, fields: LogFields): void {
  const payload: Record<string, unknown> = {
    timestamp: new Date().toISOString(),
    level,
    message,
  };

  const correlationId = getCorrelationId();
  if (correlationId !== undefined) {
    payload.correlationId = correlationId;
  }

  for (const key of ALLOWED_FIELDS) {
    const value = fields[key];
    if (value !== undefined) {
      payload[key] = value;
    }
  }

  const line = JSON.stringify(payload);
  if (level === "error") {
    console.error(line);
  } else {
    console.log(line);
  }
}

export const logger = {
  info: (message: string, fields: LogFields = {}): void => {
    write("info", message, fields);
  },
  warn: (message: string, fields: LogFields = {}): void => {
    write("warn", message, fields);
  },
  error: (message: string, fields: LogFields = {}): void => {
    write("error", message, fields);
  },
};
