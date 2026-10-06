/**
 * Secrets travel beside a playbook, never inside one.
 *
 * A playbook is configuration — written once, read by people, stored in a repository. A password is
 * none of those things. So a playbook writes `{{ secret.password }}` and the value arrives with the
 * request that needs it, lives as long as that run, and is substituted the moment before Playwright
 * types it.
 *
 * ## Why redaction is built in rather than left to logging
 *
 * The value reaches Playwright, and Playwright puts selectors and sometimes values into the errors
 * it throws. Those errors become HTTP responses. Redacting at the logger would leave the response
 * untouched — so everything this module hands out is redacted on the way back, whatever it was for.
 */

const PLACEHOLDER = /\{\{\s*secret\.([A-Za-z0-9_]+)\s*\}\}/g;

export class MissingSecretError extends Error {
  public readonly secretName: string;

  public constructor(secretName: string) {
    super(`The playbook asks for a secret the request did not send: ${secretName}`);
    this.name = "MissingSecretError";
    this.secretName = secretName;
  }
}

/**
 * The secrets of one run, and the only way their values leave.
 *
 * Holds the values privately and hands back a redactor, so a caller that wants to substitute a
 * template cannot also enumerate what it holds — the two things a run legitimately needs are
 * "fill this in" and "take these out", and neither requires reading the set.
 */
export class Secrets {
  private readonly values: ReadonlyMap<string, string>;

  public constructor(values: Readonly<Record<string, string>>) {
    this.values = new Map(Object.entries(values));
  }

  /** `{{ secret.x }}` replaced by the value, or a refusal naming what was missing. */
  public fill(template: string): string {
    return template.replace(PLACEHOLDER, (_match, name: string): string => {
      const value = this.values.get(name);

      if (value === undefined) {
        throw new MissingSecretError(name);
      }

      return value;
    });
  }

  /**
   * Every secret's value replaced by a marker, wherever it appears.
   *
   * Longest first: a short secret that happens to be a substring of a longer one would otherwise
   * cut the longer one in half and leave the remainder readable.
   */
  public redact(text: string): string {
    let redacted = text;

    for (const value of [...this.values.values()].filter((value) => value !== "").sort((a, b) => b.length - a.length)) {
      redacted = redacted.split(value).join("[redacted]");
    }

    return redacted;
  }

  /** Whether anything needs redacting at all — a run with no secrets skips the work. */
  public get isEmpty(): boolean {
    return this.values.size === 0;
  }
}
