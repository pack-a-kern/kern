import type { Frame, Locator, Page, Response } from "playwright";

import { assertAllowed } from "./allowList.js";
import type { Secrets } from "./secrets.js";
import type { PageSource, Playbook, RunResult, Scope, Step } from "./types.js";

/** Long enough for a portal that generates a document, short enough to fail a hung page. */
const DEFAULT_TIMEOUT_MS = 15_000;

/**
 * The most a `source` step keeps, unless the deployment says otherwise (`BROWSER_MAX_SOURCE_BYTES`).
 *
 * The same ceiling the application puts on a page it downloads itself, so a page is never too large
 * one way and fine the other.
 */
export const DEFAULT_MAX_SOURCE_BYTES = 10 * 1024 * 1024;

export class StepFailedError extends Error {
  public constructor(message: string, public readonly step: number) {
    super(message);
    this.name = "StepFailedError";
  }
}

/** A page larger than this worker will hand back. Refused whole: half a page is not a page. */
export class SourceTooLargeError extends Error {
  public constructor(public readonly limit: number) {
    super(`The page is larger than the ${String(limit)} bytes this worker hands back.`);
    this.name = "SourceTooLargeError";
  }
}

/**
 * Runs one sequence of a playbook against an open page.
 *
 * ## What it will not do
 *
 * Every branch below is one of the ten verbs. There is no path that evaluates a string, so a
 * playbook cannot reach anything this file does not already do — which is what makes it safe to
 * accept one over the network from a caller that is trusted only to be the application.
 *
 * ## Capture and secrets
 *
 * A step marked `sensitive` turns capture off for the whole run, permanently: once a password has
 * been typed it is in the page, so a screenshot taken three steps later still shows it. Turning it
 * back on would be a judgement about where the value stopped being visible, which nothing here can
 * make correctly.
 */
export class Interpreter {
  public constructor(
    private readonly page: Page,
    private readonly playbook: Playbook,
    private readonly secrets: Secrets,
    private readonly maxSourceBytes: number = DEFAULT_MAX_SOURCE_BYTES,
  ) {}

  /** Set by any `sensitive` step, and never unset. */
  private tainted = false;

  /**
   * What the server answered the last `goto` with, for a `source` step to keep.
   *
   * Forgotten by a click: a click may navigate, and the response it led to is not this one. A `source`
   * after it then refuses, rather than handing back the page before the click as if it were the page
   * after.
   */
  private lastResponse: Response | null = null;

  private kept: PageSource | undefined;

  /** Whether a screenshot may still be taken — read by the caller for traces as well. */
  public get captureAllowed(): boolean {
    return !this.tainted;
  }

  public async run(steps: readonly Step[]): Promise<RunResult> {
    const started = Date.now();
    const data: Record<string, unknown> = {};
    // What the last guard said while letting the sequence carry on. Kept so a run that goes on to
    // throw still reports which way the page had gone, rather than only that something broke.
    let passed: { outcome: string; message?: string } | null = null;

    for (const [index, step] of steps.entries()) {
      const outcome = await this.step(step, index, data);

      if (outcome === null) {
        continue;
      }

      const { carryOn, ...decided } = outcome;

      // A `waitFor` that names an outcome ends the sequence: the page has said which way it went, and
      // the steps after it belong to one branch or the other, not to both.
      //
      // Unless the branch that matched was marked `continue`, which is how a guard is written. "Still
      // signed in" is not a destination; it is permission to go on to the next step.
      if (carryOn !== true) {
        return { ...decided, data, timingMs: Date.now() - started, ...this.source() };
      }

      passed = decided;
    }

    return { ...(passed ?? { outcome: "completed" }), data, timingMs: Date.now() - started, ...this.source() };
  }

  /** Returns an outcome when the page decided one, otherwise null to carry on. */
  private async step(
    step: Step,
    index: number,
    data: Record<string, unknown>,
  ): Promise<{ outcome: string; message?: string; carryOn?: boolean } | null> {
    if ("goto" in step) {
      const url = this.secrets.fill(step.goto);

      assertAllowed(url, this.playbook.allow);
      this.lastResponse = await this.page.goto(url, { timeout: step.timeout ?? DEFAULT_TIMEOUT_MS, waitUntil: "domcontentloaded" });

      // The page it landed on, not the one it was sent to: a redirect is a navigation the page
      // chose, and a check that only covered the first would be a check on intent.
      assertAllowed(this.page.url(), this.playbook.allow);

      return null;
    }

    if ("fill" in step) {
      if (step.fill.sensitive === true) {
        this.tainted = true;
      }

      await this.locator(step.fill.selector, step.fill).fill(this.secrets.fill(step.fill.value), {
        timeout: step.fill.timeout ?? DEFAULT_TIMEOUT_MS,
      });

      return null;
    }

    if ("click" in step) {
      this.lastResponse = null;
      await this.locator(step.click.selector, step.click).click({
        timeout: step.click.timeout ?? DEFAULT_TIMEOUT_MS,
      });
      assertAllowed(this.page.url(), this.playbook.allow);

      return null;
    }

    if ("select" in step) {
      await this.locator(step.select.selector, step.select).selectOption(this.secrets.fill(step.select.value), {
        timeout: step.select.timeout ?? DEFAULT_TIMEOUT_MS,
      });

      return null;
    }

    if ("waitFor" in step) {
      return await this.waitForAny(step.waitFor, index);
    }

    if ("extract" in step) {
      data[step.extract.into ?? "rows"] = await this.extract(step.extract);

      return null;
    }

    if ("screenshot" in step) {
      if (!this.captureAllowed) {
        // Refused rather than skipped quietly: a caller expecting an image and receiving nothing
        // would look for a bug, and the reason is a rule rather than a failure.
        throw new StepFailedError(
          "A screenshot cannot be taken after a sensitive value has been entered on this page.",
          index,
        );
      }

      const shot = await this.page.screenshot({ fullPage: step.screenshot.fullPage ?? false });

      data[step.screenshot.into ?? "screenshot"] = shot.toString("base64");

      return null;
    }

    if ("source" in step) {
      this.kept = await this.keep(index);

      return null;
    }

    if ("download" in step) {
      const [download] = await Promise.all([
        this.page.waitForEvent("download", { timeout: step.download.timeout ?? DEFAULT_TIMEOUT_MS }),
        this.locator(step.download.selector, step.download).click(),
      ]);

      data[step.download.into ?? "download"] = {
        filename: download.suggestedFilename(),
        path: await download.path(),
      };

      return null;
    }

    await this.locator(step.upload.selector, step.upload).setInputFiles([...step.upload.files], {
      timeout: step.upload.timeout ?? DEFAULT_TIMEOUT_MS,
    });

    return null;
  }

  /**
   * Whichever branch the page shows first.
   *
   * Raced rather than tried in order: the page is already in one of these states by the time this
   * runs, and checking them in sequence would wait out the timeout of every branch before the one
   * that is true.
   */
  private async waitForAny(
    wait: Extract<Step, { waitFor: unknown }>["waitFor"],
    index: number,
  ): Promise<{ outcome: string; message?: string; carryOn?: boolean }> {
    const timeout = wait.timeout ?? DEFAULT_TIMEOUT_MS;

    const races = wait.any.map(async (branch) => {
      // `hidden` rather than `detached` for `absent`: an element removed from the DOM and one left in
      // it but no longer shown are both "gone" to somebody reading the page, and a portal is free to
      // choose either.
      await this.locator(branch.selector, wait)
        .first()
        .waitFor({ state: branch.state === "absent" ? "hidden" : "visible", timeout });

      const carryOn = branch.continue === true ? { carryOn: true } : {};

      if (branch.message === undefined) {
        return { outcome: branch.outcome, ...carryOn };
      }

      // `exactOptionalPropertyTypes`: an absent explanation is a key that is not there, not a key
      // holding undefined — the two are different to a caller reading the JSON.
      const text = (await this.locator(branch.message, wait).first().textContent())?.trim();

      return text === undefined || text === ""
        ? { outcome: branch.outcome, ...carryOn }
        : { outcome: branch.outcome, message: text, ...carryOn };
    });

    try {
      return await Promise.any(races);
    } catch {
      throw new StepFailedError(
        `The page showed none of: ${wait.any.map((branch) => branch.outcome).join(", ")}`,
        index,
      );
    }
  }

  private async extract(
    extract: Extract<Step, { extract: unknown }>["extract"],
  ): Promise<readonly Record<string, string | null>[]> {
    const rows = await this.locator(extract.rows, extract).all();
    const out: Record<string, string | null>[] = [];

    for (const row of rows) {
      const record: Record<string, string | null> = {};

      for (const [name, field] of Object.entries(extract.fields)) {
        // `selector`/`text` narrow to a descendant; without either, the row itself is the subject.
        const target = field.selector ?? field.text;
        const node = target === undefined ? row : row.locator(target).first();

        // A field a row does not have is null, at once. Read anyway, the locator would wait out the
        // step's timeout for an element that is not coming — once per row, for a field only some rows
        // carry, which is what a page with two layouts of the same result looks like.
        if (target !== undefined && (await node.count()) === 0) {
          record[name] = null;

          continue;
        }

        record[name] =
          field.attr !== undefined
            ? await node.getAttribute(field.attr)
            : field.rendered === true
              ? (await node.innerText()).trim()
              : (await node.textContent())?.trim() ?? null;
      }

      out.push(record);
    }

    return out;
  }

  /**
   * The last `goto`'s response, refused when it is larger than this worker hands back.
   *
   * Checked twice: the size the server declared, before the body is read, and the body itself, because
   * a declared length is the server's word and a missing one is allowed.
   */
  private async keep(index: number): Promise<PageSource> {
    const response = this.lastResponse;

    if (response === null) {
      throw new StepFailedError("There is no page to keep: `source` follows a `goto`, with no click between.", index);
    }

    const declared = Number(response.headers()["content-length"]);

    if (Number.isFinite(declared) && declared > this.maxSourceBytes) {
      throw new SourceTooLargeError(this.maxSourceBytes);
    }

    const body = await response.body();

    if (body.length > this.maxSourceBytes) {
      throw new SourceTooLargeError(this.maxSourceBytes);
    }

    return {
      body,
      contentType: response.headers()["content-type"] ?? "application/octet-stream",
      url: response.url(),
      status: response.status(),
    };
  }

  /** The kept page as a field of the result, or nothing — `exactOptionalPropertyTypes` again. */
  private source(): { source?: PageSource } {
    return this.kept === undefined ? {} : { source: this.kept };
  }

  /** A locator in the main frame, or in the named one. */
  private locator(selector: string, scope: Scope): Locator {
    if (scope.frame === undefined) {
      return this.page.locator(selector);
    }

    const frame: Frame | null =
      this.page.frame({ name: scope.frame }) ?? this.page.frame({ url: (url) => url.href.includes(scope.frame ?? "") });

    if (frame === null) {
      throw new Error(`The page has no frame matching ${scope.frame}`);
    }

    return frame.locator(selector);
  }
}
