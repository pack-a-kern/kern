export interface NotImplementedPayload {
  readonly error: "not_implemented";
  readonly action: string;
  readonly message: string;
}

/**
 * Every portal-facing endpoint returns this until Milestone 10. Building the
 * real behaviour requires observed ANAF SPV portal navigation, selectors and
 * error codes, which this codebase has not verified — inventing them is
 * forbidden (`docs/AGENTS.md` §1 rule 1).
 */
export function notImplementedPayload(action: string): NotImplementedPayload {
  return {
    error: "not_implemented",
    action,
    message:
      "SPV portal automation requires observed portal behaviour and is not yet built " +
      "(Milestone 10). This endpoint only validates session lifecycle.",
  };
}
