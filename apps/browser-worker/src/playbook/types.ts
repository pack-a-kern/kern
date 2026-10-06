/**
 * A playbook: everything this worker knows about a website, sent with the request.
 *
 * Nothing here is compiled in. The worker has no knowledge of any site — it interprets what it is
 * given, which is what lets one container serve a tax portal, a screenshot service and whatever
 * comes next without a deploy between them.
 *
 * ## Why the vocabulary is closed
 *
 * There is no step that runs caller-supplied JavaScript, and there will not be. That single
 * omission is what keeps a playbook *configuration* rather than *code*: it can only compose
 * behaviours this worker already implements and was reviewed for. A playbook cannot invent a
 * capability, which is why accepting one from the network is a different proposition from
 * accepting a script.
 */

/** The format's own version, so it can change without breaking callers that send the old shape. */
export const PLAYBOOK_VERSION = 1;

/**
 * Where a playbook may send the browser.
 *
 * Checked before every navigation and again on every redirect, because a redirect is a navigation
 * the page chose rather than the playbook. `"*"` is allowed and means any host — written out, so a
 * playbook that wants the whole web says so where a reader can see it, and no playbook gets there
 * by omission.
 */
export type AllowList = readonly string[];

/**
 * How the browser presents itself.
 *
 * Every field maps to something Playwright already takes when a context is created. They are here
 * unused as much as used: a proxy or a device is one field reaching a function that exists, rather
 * than a change to how contexts are made.
 */
export interface RunContext {
  /** A Playwright device descriptor — "iPhone 13". Sets viewport, user agent and scale together. */
  readonly device?: string;
  readonly viewport?: { readonly width: number; readonly height: number };
  readonly userAgent?: string;
  readonly locale?: string;
  readonly timezoneId?: string;
  /** Unused today. Present so adding it later is a field, not a redesign. */
  readonly proxy?: { readonly server: string; readonly username?: string; readonly password?: string };
  /** Resource types to refuse — "image", "font", "media". A page loads faster without them. */
  readonly block?: readonly string[];
}

/** Where a selector is looked for, when it is not the main frame. */
export interface Scope {
  /** A frame by name or URL fragment. Portals put their login form in one more often than not. */
  readonly frame?: string;
}

export interface GotoStep {
  readonly goto: string;
  readonly timeout?: number;
}

export interface FillStep {
  readonly fill: {
    readonly selector: string;
    readonly value: string;
    /**
     * This value is a secret.
     *
     * Suppresses every form of capture while the step runs, and redacts the value from anything
     * this worker says afterwards. A password in a screenshot is the likeliest way a run leaks, and
     * the hardest to notice, so it is refused structurally rather than left to whoever writes the
     * playbook.
     */
    readonly sensitive?: boolean;
    readonly timeout?: number;
  } & Scope;
}

export interface ClickStep {
  readonly click: { readonly selector: string; readonly timeout?: number } & Scope;
}

export interface SelectStep {
  readonly select: { readonly selector: string; readonly value: string; readonly timeout?: number } & Scope;
}

/**
 * Wait until the page says which of several things happened, and report which.
 *
 * The heart of the format. A login does not succeed or fail — it arrives at one of several pages,
 * and only the page can say which. Each branch names an `outcome` the caller understands, so
 * "awaiting a code" and "wrong password" are the playbook author's words rather than this worker's.
 */
export interface WaitForStep {
  readonly waitFor: {
    readonly any: readonly {
      readonly selector: string;
      readonly outcome: string;
      /**
       * What this branch is waiting for the selector to become. `visible` by default.
       *
       * `absent` is how "the form is gone" is written, which is the honest signal for a sign-in: a
       * portal decides for itself where to land afterwards, and a playbook that named the destination
       * would be guessing at it. Safe to read after a click, because Playwright waits for the
       * navigation a click triggers before this is evaluated — measured against a stalled server, not
       * assumed.
       */
      readonly state?: "visible" | "absent";
      /** A selector whose text explains the outcome — an error message to pass back. */
      readonly message?: string;
      /**
       * True when this branch means "carry on with the sequence" rather than "stop here".
       *
       * Without it a `waitFor` is always the last thing a sequence does, which makes a guard
       * inexpressible: "make sure we are still signed in, *then* read the table" has to become two
       * sequences and two round-trips, with the caller joining them back together. Worse, the obvious
       * workaround — extract first and check afterwards — reads an empty table off a login page and
       * reports it as an empty inbox.
       *
       * The branch still names an `outcome`, because a run that ends while waiting on it (a later step
       * throwing) should still say which way the page went.
       */
      readonly continue?: boolean;
    }[];
    readonly timeout?: number;
  } & Scope;
}

/** Rows and fields out of the page, as structured data rather than HTML. */
export interface ExtractStep {
  readonly extract: {
    readonly rows: string;
    readonly fields: Readonly<Record<string, { readonly selector?: string; readonly text?: string; readonly attr?: string }>>;
    readonly into?: string;
  } & Scope;
}

export interface ScreenshotStep {
  readonly screenshot: { readonly fullPage?: boolean; readonly into?: string } & Scope;
}

/** Click something that produces a file, and keep what came back. */
export interface DownloadStep {
  readonly download: { readonly selector: string; readonly into?: string; readonly timeout?: number } & Scope;
}

export interface UploadStep {
  readonly upload: { readonly selector: string; readonly files: readonly string[]; readonly timeout?: number } & Scope;
}

export type Step =
  | GotoStep
  | FillStep
  | ClickStep
  | SelectStep
  | WaitForStep
  | ExtractStep
  | ScreenshotStep
  | DownloadStep
  | UploadStep;

export interface Playbook {
  readonly version: number;
  readonly name: string;
  readonly allow: AllowList;
  readonly context?: RunContext;
  /**
   * Named sequences, run one at a time against the same session.
   *
   * Separate rather than one list because a flow can pause: credentials, then a person goes to read
   * an email, then the code. The browser stays open between them and the caller decides when the
   * next part runs.
   */
  readonly sequences: Readonly<Record<string, readonly Step[]>>;
}

/** What a run answers. `outcome` is the playbook's own word, or `completed` if nothing named one. */
export interface RunResult {
  readonly outcome: string;
  readonly data: Readonly<Record<string, unknown>>;
  readonly message?: string;
  readonly timingMs: number;
}
