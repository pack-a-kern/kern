import { ENGINES, PLAYBOOK_VERSION, type Playbook, type Step } from "./types.js";

/**
 * Checks a playbook before a browser is opened for it.
 *
 * Hand-written rather than a schema library, for the same reason the vocabulary is closed: there
 * are ten verbs and the rules are specific — a selector must be a non-empty string, an outcome
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

/** The ten verbs. A step naming anything else is refused rather than ignored. */
const VERBS = ["goto", "fill", "click", "select", "waitFor", "extract", "screenshot", "download", "upload", "source"] as const;

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

  if (verb === "source") {
    // Nothing to configure: it takes the response the last `goto` received. `true` rather than an empty
    // object, because an empty PHP array encodes as `[]` and a step that only works from one caller is
    // not a format.
    if (body !== true) {
      problems.push(`${path}.source must be true`);
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

        return;
      }

      if (branch.continue !== undefined && typeof branch.continue !== "boolean") {
        problems.push(`${path}.waitFor.any[${String(index)}].continue must be true or false`);
      }

      if (branch.state !== undefined && branch.state !== "visible" && branch.state !== "absent") {
        problems.push(`${path}.waitFor.any[${String(index)}].state must be "visible" or "absent"`);
      }

      if (branch.state === "absent" && branch.message !== undefined) {
        // An explanation is read out of the element the branch matched, and this branch matched by the
        // element not being there. Refused rather than returning null, so the author learns which half
        // they meant.
        problems.push(`${path}.waitFor.any[${String(index)}] cannot read a message from an absent element`);
      }
    });

    if (branches.every((branch) => isRecord(branch) && branch.continue === true)) {
      // Every branch carrying on makes the step a wait with no decision in it, which is a `waitFor`
      // the author meant to give an exit and did not. Caught here rather than at run time, where it
      // would look like a page that never said anything.
      problems.push(`${path}.waitFor.any must leave at least one branch that ends the sequence`);
    }

    return;
  }

  if (verb === "extract") {
    if (!text(body.rows)) {
      problems.push(`${path}.extract.rows must be a non-empty selector`);
    }

    if (!isRecord(body.fields) || Object.keys(body.fields).length === 0) {
      problems.push(`${path}.extract.fields must name at least one field`);

      return;
    }

    for (const [name, field] of Object.entries(body.fields)) {
      if (!isRecord(field)) {
        problems.push(`${path}.extract.fields.${name} must be an object`);
      } else if (field.rendered !== undefined && typeof field.rendered !== "boolean") {
        problems.push(`${path}.extract.fields.${name}.rendered must be true or false`);
      } else if (field.rendered === true && field.attr !== undefined) {
        // An attribute has no rendering; asking for both is a field that means one of them.
        problems.push(`${path}.extract.fields.${name} reads an attribute or rendered text, not both`);
      }
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

  if (verb === "click" && body.capture !== undefined) {
    checkCapture(body.capture, `${path}.click.capture`, problems);
  }
}

/** A click's capture: an http(s) address with no query of its own, and the query values as strings. */
function checkCapture(capture: unknown, path: string, problems: string[]): void {
  let url: URL | null;

  try {
    url = isRecord(capture) && text(capture.url) ? new URL(capture.url) : null;
  } catch {
    url = null;
  }

  if (url === null || (url.protocol !== "https:" && url.protocol !== "http:") || url.search !== "" || url.hash !== "") {
    problems.push(`${path}.url must be an http(s) address without a query`);
  }

  const query = isRecord(capture) ? capture.query : undefined;

  if (query !== undefined && !(isRecord(query) && Object.values(query).every((value) => typeof value === "string"))) {
    problems.push(`${path}.query must name its values as strings`);
  }
}

/** A playbook's own proxy: `http://host:port`, with credentials as strings when there are any. */
function checkProxy(proxy: unknown, problems: string[]): void {
  if (proxy === undefined) {
    return;
  }

  if (!isRecord(proxy) || !text(proxy.server)) {
    problems.push("context.proxy must name a server");

    return;
  }

  let server: URL | null;

  try {
    server = new URL(proxy.server);
  } catch {
    server = null;
  }

  // Plain HTTP to the proxy only: what goes through it is the browser's own TLS, so a site's traffic is
  // encrypted either way, and one scheme is one thing to get right.
  if (server?.protocol !== "http:" || server.hostname === "" || server.pathname !== "/" || server.username !== "") {
    problems.push("context.proxy.server must be http://host:port, with credentials as username and password");
  }

  for (const field of ["username", "password"] as const) {
    if (proxy[field] !== undefined && typeof proxy[field] !== "string") {
      problems.push(`context.proxy.${field} must be a string`);
    }
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

  checkProxy(isRecord(value.context) ? value.context.proxy : undefined, problems);

  const engine = isRecord(value.context) ? value.context.engine : undefined;

  if (engine !== undefined && !ENGINES.some((known) => known === engine)) {
    problems.push(`context.engine must be one of: ${ENGINES.join(", ")}`);
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
