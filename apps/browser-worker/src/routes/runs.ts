import { Router, type Request, type Response } from "express";

import { logger } from "../logger.js";
import { DestinationRefusedError } from "../playbook/allowList.js";
import { Interpreter, StepFailedError } from "../playbook/interpreter.js";
import { MissingSecretError, Secrets } from "../playbook/secrets.js";
import type { Playbook } from "../playbook/types.js";
import { PlaybookInvalidError, sequenceOf, validatePlaybook } from "../playbook/validate.js";
import { QueueFullError, type RunQueue } from "../runs/queue.js";
import type { Stats } from "../runs/stats.js";
import type { BrowserContextHandle } from "../sessions/browserContextFactory.js";
import type { SessionManager } from "../sessions/sessionManager.js";

/**
 * The generic API: open a browser, run a sequence of a playbook in it, close it.
 *
 * ## Why a session is separate from a run
 *
 * Because a flow can pause. A login that emails a code needs the browser still open while a person
 * goes to read their inbox — so the caller opens a session, runs `signIn`, comes back minutes later
 * and runs `challenge` against the same browser. A one-shot `/v1/run` exists for everything that
 * does not need that, and closes the browser on the way out.
 *
 * ## What leaves this worker
 *
 * An outcome, extracted data, and a duration. Never a secret: every message on the way out passes
 * through the run's own redactor, because Playwright puts values into the errors it throws and
 * those errors would otherwise become the response body.
 */
export function createRunRouter(
  sessions: SessionManager,
  queue: RunQueue,
  stats: Stats,
): Router {
  const router = Router();

  router.post("/v1/sessions", async (req: Request, res: Response): Promise<void> => {
    const body = asRecord(req.body);
    let playbook: Playbook;

    try {
      playbook = validatePlaybook(body.playbook);
    } catch (error) {
      respondToFailure(error, res, null);

      return;
    }

    const session = await sessions.create(playbook.context);

    stats.recordBrowser();

    res.status(201).json({
      session_id: session.id,
      expires_at: session.expiresAt.toISOString(),
    });
  });

  router.post("/v1/sessions/:id/run", async (req: Request, res: Response): Promise<void> => {
    const id = idParam(req);
    const handle = id === undefined ? undefined : sessions.handleOf(id);

    if (handle === undefined) {
      res.status(404).json({ error: "unknown_session" });

      return;
    }

    await execute(req, res, handle, queue, stats, null);
  });

  router.delete("/v1/sessions/:id", async (req: Request, res: Response): Promise<void> => {
    const destroyed = await sessions.destroy(idParam(req) ?? "");

    res.status(destroyed ? 200 : 404).json({ destroyed });
  });

  /** Everything that does not need to pause: one browser, one sequence, closed on the way out. */
  router.post("/v1/run", async (req: Request, res: Response): Promise<void> => {
    const body = asRecord(req.body);
    let playbook: Playbook;

    try {
      playbook = validatePlaybook(body.playbook);
    } catch (error) {
      respondToFailure(error, res, null);

      return;
    }

    const session = await sessions.create(playbook.context);

    stats.recordBrowser();

    const handle = sessions.handleOf(session.id);

    try {
      if (handle !== undefined) {
        await execute(req, res, handle, queue, stats, playbook);
      }
    } finally {
      // Closed whatever happened, including a refusal: a one-shot that left a browser open would
      // leak one per failed request, which is the fastest way to exhaust a worker.
      await sessions.destroy(session.id);
    }
  });

  router.get("/v1/sessions", (_req: Request, res: Response): void => {
    res.json({
      sessions: sessions.list().map((session) => ({
        id: session.id,
        status: session.status,
        created_at: session.createdAt.toISOString(),
        expires_at: session.expiresAt.toISOString(),
      })),
    });
  });

  router.get("/v1/stats", (_req: Request, res: Response): void => {
    res.json(stats.snapshot(sessions.list().length, queue.depth, queue.running));
  });

  return router;
}

async function execute(
  req: Request,
  res: Response,
  handle: BrowserContextHandle,
  queue: RunQueue,
  stats: Stats,
  known: Playbook | null,
): Promise<void> {
  const body = asRecord(req.body);
  let playbook: Playbook;
  let secrets: Secrets;

  try {
    playbook = known ?? validatePlaybook(body.playbook);
    secrets = new Secrets(asStringRecord(body.secrets));
  } catch (error) {
    respondToFailure(error, res, null);

    return;
  }

  const name = typeof body.sequence === "string" ? body.sequence : "";

  try {
    const steps = sequenceOf(playbook, name);

    const result = await queue.run(async () => {
      // The context is reused across sequences, and so is its page: the cookies a login set are
      // what the sequence after it depends on, and a fresh context would throw them away.
      const page = handle.context.pages()[0] ?? (await handle.context.newPage());

      return await new Interpreter(page, playbook, secrets).run(steps);
    });

    stats.recordRun(result.outcome, result.timingMs);

    res.json({
      outcome: result.outcome,
      ...(result.message === undefined ? {} : { message: secrets.redact(result.message) }),
      data: result.data,
      timing_ms: result.timingMs,
    });
  } catch (error) {
    stats.recordFailure();
    // Where the browser actually was. A step that fails is nearly always a page that is not the page the
    // playbook was written against, and the address is the cheapest thing that says which — without it,
    // diagnosing means reproducing the run, which for a portal that emails a one-time code means
    // spending one.
    respondToFailure(error, res, secrets, handle.context.pages()[0]?.url());
  }
}

/**
 * One place every failure leaves by.
 *
 * Each kind gets the status that says whose problem it is — a playbook that does not parse is the
 * caller's, a page that never showed what was expected is the site's, a full queue is this
 * worker's. And every message is redacted on the way out, including the ones that came from
 * Playwright, which quotes values back in its errors.
 */
function respondToFailure(error: unknown, res: Response, secrets: Secrets | null, url?: string): void {
  const say = (message: string): string => (secrets === null || secrets.isEmpty ? message : secrets.redact(message));

  if (error instanceof PlaybookInvalidError) {
    res.status(422).json({ error: "playbook_invalid", problems: error.problems });

    return;
  }

  if (error instanceof MissingSecretError) {
    res.status(422).json({ error: "secret_missing", secret: error.secretName });

    return;
  }

  if (error instanceof DestinationRefusedError) {
    res.status(403).json({ error: "destination_refused", url: error.url });

    return;
  }

  if (error instanceof QueueFullError) {
    res.status(503).json({ error: "at_capacity" });

    return;
  }

  // The address is a URL on a host the playbook already named, so it reveals nothing the caller did not
  // send — but it is redacted anyway, because a portal is free to put anything in a query string.
  const at = url === undefined ? {} : { url: say(url) };

  if (error instanceof StepFailedError) {
    logger.error("step_failed", { event: "step_failed", failure: say(error.message), ...at });
    res.status(422).json({ error: "step_failed", step: error.step, message: say(error.message), ...at });

    return;
  }

  const message = error instanceof Error ? say(error.message) : "The run failed.";

  // The message, not merely the fact. It is redacted by `say()` above, and without it an operator
  // reads "run_failed" and has to reproduce the run to learn anything.
  logger.error("run_failed", { event: "run_failed", failure: message, ...at });
  res.status(502).json({ error: "run_failed", message, ...at });
}

/** Express types a path parameter as `string | string[]`; anything but a string is "not found". */
function idParam(req: Request): string | undefined {
  const { id } = req.params;

  return typeof id === "string" ? id : undefined;
}

function asRecord(value: unknown): Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value) ? (value as Record<string, unknown>) : {};
}

function asStringRecord(value: unknown): Record<string, string> {
  const out: Record<string, string> = {};

  for (const [key, entry] of Object.entries(asRecord(value))) {
    if (typeof entry === "string") {
      out[key] = entry;
    }
  }

  return out;
}
