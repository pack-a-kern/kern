import assert from "node:assert/strict";
import { describe, it } from "node:test";

import { isAllowed } from "../src/playbook/allowList.js";
import { MissingSecretError, Secrets } from "../src/playbook/secrets.js";
import { PlaybookInvalidError, validatePlaybook } from "../src/playbook/validate.js";

const VALID = {
  version: 1,
  name: "example",
  allow: ["example.com"],
  sequences: {
    signIn: [
      { goto: "https://example.com/login" },
      { fill: { selector: "#user", value: "{{ secret.username }}" } },
      { click: { selector: "button" } },
      { waitFor: { any: [{ selector: "#otp", outcome: "awaiting_challenge" }] } },
    ],
  },
};

describe("validatePlaybook", () => {
  it("accepts a playbook that names its destinations and its steps", () => {
    assert.equal(validatePlaybook(structuredClone(VALID)).name, "example");
  });

  it("refuses a playbook with no allow-list", () => {
    // Never defaulted: a playbook that may go anywhere has to say so, because the dangerous mode
    // must be something somebody wrote rather than something they left out.
    const playbook: Record<string, unknown> = structuredClone(VALID);

    delete playbook.allow;

    assert.throws(() => validatePlaybook(playbook), PlaybookInvalidError);
  });

  it("refuses a step that names no verb, or two", () => {
    const none = structuredClone(VALID);
    const two = structuredClone(VALID);

    (none.sequences.signIn as unknown[])[0] = { nonsense: true };
    (two.sequences.signIn as unknown[])[0] = { goto: "https://example.com", click: { selector: "a" } };

    assert.throws(() => validatePlaybook(none), PlaybookInvalidError);
    // Two verbs leave the order between them undefined, which is a mistake in writing rather than
    // a shape to interpret.
    assert.throws(() => validatePlaybook(two), PlaybookInvalidError);
  });

  it("names the path of every problem it found", () => {
    const playbook = structuredClone(VALID);

    (playbook.sequences.signIn as unknown[])[1] = { fill: { selector: "", value: "x" } };

    try {
      validatePlaybook(playbook);
      assert.fail("expected a refusal");
    } catch (error) {
      assert.ok(error instanceof PlaybookInvalidError);
      assert.match(error.problems.join(" "), /sequences\.signIn\[1\]\.fill\.selector/);
    }
  });

  it("refuses a version it does not know", () => {
    assert.throws(() => validatePlaybook({ ...structuredClone(VALID), version: 99 }), PlaybookInvalidError);
  });
});

describe("isAllowed", () => {
  it("admits a host and its subdomains", () => {
    assert.equal(isAllowed("https://anaf.ro/x", ["anaf.ro"]), true);
    assert.equal(isAllowed("https://spv.anaf.ro/x", ["anaf.ro"]), true);
  });

  it("does not admit a host that merely ends with the same letters", () => {
    // The mistake a suffix check makes, and the reason this compares labels.
    assert.equal(isAllowed("https://evil-anaf.ro/x", ["anaf.ro"]), false);
    assert.equal(isAllowed("https://anaf.ro.evil.com/x", ["anaf.ro"]), false);
  });

  it("admits anything only when the playbook says so out loud", () => {
    assert.equal(isAllowed("https://anywhere.example/x", ["anaf.ro"]), false);
    assert.equal(isAllowed("https://anywhere.example/x", ["*"]), true);
  });

  it("refuses schemes a browser should not be driven to", () => {
    // `file:` reads this container's own disk; the other two are script delivery by another name.
    assert.equal(isAllowed("file:///etc/passwd", ["*"]), true, "“*” is the caller's explicit choice");
    assert.equal(isAllowed("file:///etc/passwd", ["example.com"]), false);
    assert.equal(isAllowed("javascript:alert(1)", ["example.com"]), false);
    assert.equal(isAllowed("data:text/html,<p>x", ["example.com"]), false);
  });
});

describe("Secrets", () => {
  it("fills a placeholder and refuses one it was not given", () => {
    const secrets = new Secrets({ username: "ana" });

    assert.equal(secrets.fill("{{ secret.username }}"), "ana");
    assert.throws(() => secrets.fill("{{ secret.password }}"), MissingSecretError);
  });

  it("redacts every secret from anything on its way out", () => {
    const secrets = new Secrets({ password: "hunter2" });

    // Playwright quotes values back in the errors it throws, and those errors become responses.
    assert.equal(secrets.redact('Timed out filling "hunter2"'), 'Timed out filling "[redacted]"');
  });

  it("redacts the longest secret first", () => {
    // A short secret inside a longer one would otherwise cut the longer one in half and leave the
    // remainder readable.
    const secrets = new Secrets({ short: "pass", long: "password123" });

    assert.equal(secrets.redact("password123"), "[redacted]");
  });
});
