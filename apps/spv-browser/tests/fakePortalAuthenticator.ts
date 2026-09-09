import type {
  PortalAuthenticator,
  PortalAuthOutcome,
  PortalCredentials,
} from "../src/portal/portalAuthenticator.js";

/**
 * Proves the real credential/challenge machinery (session-state transitions, ordering, the
 * response shape) without any ANAF portal behaviour — this fake never touches a browser and
 * decides outcomes purely from the input it was given, deterministically.
 *
 * Records every call it received (username/password/code included) so a test can assert *this
 * fake* was called with the right thing — a separate concern from asserting the process's own
 * logs never contain a secret (`tests/noSensitiveLogging.test.ts`), which spies on `console.*`
 * instead of this class.
 */
export function createFakePortalAuthenticator(): {
  readonly authenticator: PortalAuthenticator;
  readonly credentialCalls: readonly PortalCredentials[];
  readonly challengeCalls: readonly string[];
} {
  const credentialCalls: PortalCredentials[] = [];
  const challengeCalls: string[] = [];

  const authenticator: PortalAuthenticator = {
    submitCredentials(_sessionId: string, credentials: PortalCredentials): Promise<PortalAuthOutcome> {
      credentialCalls.push(credentials);
      return Promise.resolve({ outcome: "awaiting_challenge" });
    },
    submitChallengeCode(_sessionId: string, code: string): Promise<PortalAuthOutcome> {
      challengeCalls.push(code);
      if (code === "000000") {
        return Promise.resolve({ outcome: "authenticated" });
      }
      return Promise.resolve({ outcome: "failed", reason: "invalid_code" });
    },
  };

  return { authenticator, credentialCalls, challengeCalls };
}
