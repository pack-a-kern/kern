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
 * Every field maps to something Playwright already takes when a context is created, except `proxy`,
 * which is this worker's own egress proxy's to use ({@see UpstreamProxy}), and `engine`, which picks the
 * browser that is launched.
 */
export interface RunContext {
  /**
   * The browser behind the page — Chromium unless named. One that matches the `userAgent`: a page reads
   * its engine from more than the header, and a Firefox name on Chromium is a contradiction it can see.
   */
  readonly engine?: Engine;
  /** A Playwright device descriptor — "iPhone 13". Sets viewport, user agent and scale together. */
  readonly device?: string;
  readonly viewport?: { readonly width: number; readonly height: number };
  readonly userAgent?: string;
  readonly locale?: string;
  readonly timezoneId?: string;
  readonly proxy?: UpstreamProxy;
  /** Resource types to refuse — "image", "font", "media". A page loads faster without them. */
  readonly block?: readonly string[];
}

/** The browsers Playwright drives, each in this worker's image. */
export const ENGINES = ["chromium", "firefox", "webkit"] as const;

export type Engine = (typeof ENGINES)[number];

/**
 * A proxy to go out through — a mobile or residential one, so a site sees that network rather than this
 * server's. `http://host:port` only; the credentials, when it wants them, beside it.
 *
 * Chained behind the worker's egress proxy, never handed to Chromium: the browser still goes out only
 * through the proxy holding the allow-list, which sends on through this one what it lets pass.
 */
export interface UpstreamProxy {
  readonly server: string;
  readonly username?: string;
  readonly password?: string;
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
  readonly click: {
    readonly selector: string;
    /**
     * A response the click makes the page fetch — kept for a `source` step after it, as a `goto`'s is.
     * What a page loads on a click is often the data itself: a chart's prices.
     */
    readonly capture?: Capture;
    readonly timeout?: number;
  } & Scope;
}

/**
 * Which response a click keeps: the first whose address is `url` — scheme, host and path, nothing after
 * it — and whose query has each of `query`'s values. A page often asks the same address several ways.
 */
export interface Capture {
  readonly url: string;
  readonly query?: Readonly<Record<string, string>>;
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

/**
 * One field of an extracted row: the text of the row, or of a descendant, or one of its attributes.
 *
 * `rendered` reads the text as the page shows it (`innerText`) rather than as the markup holds it
 * (`textContent`). The two differ where a `<br>` or a block element separates words: "…2015<br>privind…"
 * is "2015privind" to `textContent`, and a title read that way names a date that is not one.
 */
export interface ExtractField {
  readonly selector?: string;
  readonly text?: string;
  readonly attr?: string;
  readonly rendered?: boolean;
}

/** Rows and fields out of the page, as structured data rather than HTML. */
export interface ExtractStep {
  readonly extract: {
    readonly rows: string;
    readonly fields: Readonly<Record<string, ExtractField>>;
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

/**
 * Keep the page exactly as its server sent it: the last `goto`'s response, or the one a `click` captured,
 * byte for byte.
 *
 * Not what Chromium made of it. `page.content()` is the document after its scripts ran, serialised
 * again — a caller that stores a page "as served" and parses it with rules written against the served
 * HTML needs the bytes the server sent, which are what any HTTP client would have received.
 *
 * Answered by `POST /v1/source` as the response body itself, never inside `data`: a page of several
 * megabytes encoded into JSON is copied again on each side of the wire for nothing.
 */
export interface SourceStep {
  readonly source: true;
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
  | UploadStep
  | SourceStep;

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

/** What a `source` step kept: the response its server sent, as it sent it. */
export interface PageSource {
  readonly body: Buffer;
  readonly contentType: string;
  /** Where the page was served from — after any redirect, each of which the allow-list checked. */
  readonly url: string;
  readonly status: number;
}

/** What a run answers. `outcome` is the playbook's own word, or `completed` if nothing named one. */
export interface RunResult {
  readonly outcome: string;
  readonly data: Readonly<Record<string, unknown>>;
  readonly message?: string;
  readonly timingMs: number;
  /** Set by a `source` step. Only `/v1/source` sends it back. */
  readonly source?: PageSource;
}
