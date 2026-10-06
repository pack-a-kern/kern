import { PLAYBOOK_VERSION, type Playbook, type Step } from "./types.js";

/**
 * Checks a playbook before a browser is opened for it.
 *
 * Hand-written rather than a schema library, for the same reason the vocabulary is closed: there
 * are nine verbs and the rules are specific — a selector must be a non-empty string, an outcome
 * must be named, an allow-list may not be empty by accident. A general validator would express
 * that in as much code as this and add a dependency to justify.
 *
 * Every failure names the path it failed at. A playbook is written by hand and arrives over HTTP;
 * "sequences.signIn[2].fill.selector must be a non-empty string" is the difference between a fix
 * and a guess.
 */

export class PlaybookInvalidError extends Error {
  public constructor(public readonly problems: readonly string[]) {
    super(`The playbook is not valid: ${problems.join("; ")}`);
    this.name = "PlaybookInvalidError";
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function text(value: unknown): value is string {
  return typeof value === "string" && value.trim() !== "";
}

/** The nine verbs. A step naming anything else is refused rather than ignored. */
const VERBS = ["goto", "fill", "click", "select", "waitFor", "extract", "screenshot", "download", "upload"] as const;

function checkStep(step: unknown, path: string, problems: string[]): void {
  if (!isRecord(step)) {
    problems.push(`${path} must be an object`);

    return;
  }

  const verbs = VERBS.filter((verb) => verb in step);
  const [verb] = verbs;

  if (verbs.length !== 1 || verb === undefined) {
    // Two verbs in one step would leave the order between them undefined, and nought would do
    // nothing at all — both are a mistake in writing rather than a shape to interpret.
    problems.push(`${path} must name exactly one of: ${VERBS.join(", ")}`);

    return;
  }

  const body: unknown = step[verb];

  if (verb === "goto") {
    if (!text(body)) {
      problems.push(`${path}.goto must be a non-empty URL`);
    }

    return;
  }

  if (!isRecord(body)) {
    problems.push(`${path}.${verb} must be an object`);

    return;
  }

  if (verb === "waitFor") {
    const branches: unknown = body.any;

    if (!Array.isArray(branches) || branches.length === 0) {
      problems.push(`${path}.waitFor.any must list at least one branch`);

      return;
    }

    branches.forEach((branch, index) => {
      if (!isRecord(branch) || !text(branch.selector) || !text(branch.outcome)) {
        problems.push(`${path}.waitFor.any[${String(index)}] needs a selector and an outcome`);
      }
    });

    return;
  }

  if (verb === "extract") {
    if (!text(body.rows)) {
      problems.push(`${path}.extract.rows must be a non-empty selector`);
    }

    if (!isRecord(body.fields) || Object.keys(body.fields).length === 0) {
      problems.push(`${path}.extract.fields must name at least one field`);
    }

    return;
  }

  if (verb === "upload") {
    if (!text(body.selector)) {
      problems.push(`${path}.upload.selector must be a non-empty selector`);
    }

    if (!Array.isArray(body.files) || body.files.length === 0 || !body.files.every(text)) {
      problems.push(`${path}.upload.files must list at least one path`);
    }

    return;
  }

  if (verb === "screenshot") {
    return;
  }

  // fill, click, select, download: all need something to act on.
  if (!text(body.selector)) {
    problems.push(`${path}.${verb}.selector must be a non-empty selector`);
  }

  if ((verb === "fill" || verb === "select") && typeof body.value !== "string") {
    problems.push(`${path}.${verb}.value must be a string`);
  }
}

export function validatePlaybook(value: unknown): Playbook {
  const problems: string[] = [];

  if (!isRecord(value)) {
    throw new PlaybookInvalidError(["the playbook must be an object"]);
  }

  if (value.version !== PLAYBOOK_VERSION) {
    problems.push(`version must be ${String(PLAYBOOK_VERSION)}`);
  }

  if (!text(value.name)) {
    problems.push("name must be a non-empty string");
  }

  // Never defaulted. A playbook with no allow-list would be one that may go anywhere, and that has
  // to be a sentence somebody wrote — `allow: ["*"]` — rather than a line they left out.
  if (!Array.isArray(value.allow) || value.allow.length === 0 || !value.allow.every(text)) {
    problems.push('allow must list at least one host, or "*" for any');
  }

  if (!isRecord(value.sequences) || Object.keys(value.sequences).length === 0) {
    problems.push("sequences must name at least one sequence");
  } else {
    for (const [name, steps] of Object.entries(value.sequences)) {
      if (!Array.isArray(steps) || steps.length === 0) {
        problems.push(`sequences.${name} must list at least one step`);

        continue;
      }

      steps.forEach((step, index) => {
        checkStep(step, `sequences.${name}[${String(index)}]`, problems);
      });
    }
  }

  if (problems.length > 0) {
    throw new PlaybookInvalidError(problems);
  }

  return value as unknown as Playbook;
}

/** The steps of one sequence, or a refusal naming what was asked for. */
export function sequenceOf(playbook: Playbook, name: string): readonly Step[] {
  const steps = playbook.sequences[name];

  if (steps === undefined) {
    throw new PlaybookInvalidError([`the playbook has no sequence named ${name}`]);
  }

  return steps;
}
