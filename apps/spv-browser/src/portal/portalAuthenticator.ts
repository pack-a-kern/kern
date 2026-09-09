export interface PortalCredentials {
  readonly username: string;
  readonly password: string;
}

export type PortalAuthOutcome =
  | { readonly outcome: "awaiting_challenge" }
  | { readonly outcome: "authenticated" }
  | { readonly outcome: "failed"; readonly reason: string };

/**
 * The seam `BLUEPRINT.md` §13.2's login/challenge flow runs through, once a real ANAF SPV portal
 * adapter exists. `PortalCredentials`/`code` are passed straight through from the route handler
 * to whichever implementation is bound — never assigned to a `SessionManager`-owned field, so
 * nothing in this process holds a password or challenge code beyond the single call these
 * methods make (`BLUEPRINT.md` §13.2 steps 3/6 — never persisted, never logged).
 *
 * The one implementation shipped today, {@see UnimplementedPortalAuthenticator}, rejects every
 * call: no ANAF SPV portal navigation, selector, or DOM structure has been observed
 * (`docs/AGENTS.md` §1 rule 1, `docs/reference/anaf-spv-integration.md`). A future milestone that
 * has actually watched the real portal replaces this one binding
 * (`src/app.ts`'s `createApp()` parameter) — nothing else in this module changes.
 */
export interface PortalAuthenticator {
  submitCredentials(sessionId: string, credentials: PortalCredentials): Promise<PortalAuthOutcome>;
  submitChallengeCode(sessionId: string, code: string): Promise<PortalAuthOutcome>;
}

export class PortalAutomationNotImplementedError extends Error {
  public constructor(public readonly action: string) {
    super(
      `SPV portal automation for '${action}' is not yet implemented — it requires observed ` +
        "ANAF portal behaviour (Milestone 10, docs/reference/anaf-spv-integration.md).",
    );
    this.name = "PortalAutomationNotImplementedError";
  }
}

/**
 * The only implementation this codebase ships. Both methods discard their arguments immediately
 * and throw — by construction, no credential or challenge code is ever retained, logged, or
 * inspected here.
 */
export class UnimplementedPortalAuthenticator implements PortalAuthenticator {
  submitCredentials(_sessionId: string, _credentials: PortalCredentials): Promise<PortalAuthOutcome> {
    throw new PortalAutomationNotImplementedError("credentials");
  }

  submitChallengeCode(_sessionId: string, _code: string): Promise<PortalAuthOutcome> {
    throw new PortalAutomationNotImplementedError("challenge");
  }
}
