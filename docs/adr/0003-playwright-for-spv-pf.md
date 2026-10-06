# ADR 0003 — Playwright for SPV PF flows not covered by an official API

**Status:** accepted, 2026-09-07

## Context

ANAF's Spațiul Privat Virtual (SPV) is the mandatory channel for PF tax correspondence and
declaration submission. ANAF publishes some web services (`static.anaf.ro` service list,
`BLUEPRINT.md` §37), but §13.1 is explicit that the PF portal has flows — login with a safety code,
inbox browsing, D212 submission for a private individual — that are not fully covered by a
documented, stable API. Building on an undocumented private endpoint as a load-bearing dependency
would violate `docs/AGENTS.md` §1.1 ("never invent an ANAF endpoint you have not verified") the
moment ANAF changes it without notice, because there would be no contract to have violated in the
first place.

## Decision

1. **Official API/web-service first, always.** Any SPV operation ANAF documents and exposes
   through a stable service is implemented against that service. Playwright is used only for the
   portal-only flows that have no such documented equivalent.
2. Playwright drives an **ephemeral browser context per session** (`browser-worker` container,
   `browser_net`, no DB credentials — `BLUEPRINT.md` §3.3, §3.4). No Chrome profile is reused across
   tax subjects; download folders are ephemeral; the container/pod is recycled after a sensitive
   session.
3. **No permanently persisted SPV password.** The flow in §13.2: the user initiates `Connect SPV`,
   Laravel opens an `SpvAuthAttempt`, credentials are passed to the browser worker over a
   short-lived internal channel and are not written to durable storage as plaintext. If ANAF
   challenges with an OTP/safety code, the browser worker enters `AWAITING_USER_CHALLENGE`, Reverb
   notifies the UI, the user supplies the code, and the code itself is never logged and carries a
   very short TTL (§13.2 steps 6–9).
4. Session persistence, in order of preference per §13.3: (1) a reusable token/session artifact if
   ANAF's flow legally and technically supports one; (2) encrypted browser storage state with a
   short TTL, scoped per subject, only if that is stable and acceptably safe; (3) plain
   re-authentication with fresh user input. Permanent password storage purely for convenience is
   explicitly out of scope — a future "background sync without the user present" feature is a
   separate risk/compliance decision, not something this ADR authorizes implicitly.
5. Selector policy (§13.5): prefer role/label/accessibility selectors; fragile CSS/XPath selectors
   are a last resort and trigger `docs/AGENTS.md` rule 16 in review. A daily synthetic DOM-contract
   test runs against the real portal with no real taxpayer data, specifically to detect ANAF
   changing the DOM before it silently breaks real submissions (§13.5, §23 alerting).
6. The browser worker is reached only through the internal contract in §13.4
   (`POST /v1/spv/sessions`, `.../credentials`, `.../challenge`, `.../actions/sync`,
   `.../actions/submit-d212`, `DELETE .../sessions/{id}`), authenticated service-to-service
   (mTLS preferred, plus a short-TTL signed JWT), never reachable directly by the browser/PWA.

## Consequences

**Good:**
- No dependency on an undocumented, unstable private API as critical infrastructure — the failure
  mode when ANAF changes something is "the adapter breaks and is fixed," not "declarations
  silently fail or misfile."
- Ephemeral, credential-isolated browser contexts mean one tax subject's compromised session
  cannot leak into another's, and a compromised `browser-worker` container has no DB credentials to
  pivot with.
- No standing password store means there is nothing durable to steal that unlocks a taxpayer's
  ANAF account; the worst case of a worker compromise during an active session is bounded to that
  one session's TTL.
- The DOM-contract synthetic test converts "ANAF changed something" from a silent, user-facing
  failure into an internal alert (§23, §38-G) before it reaches a real filing.

**Bad:**
- Playwright automation of a third-party portal is inherently brittle compared to a documented
  API: ANAF can change markup, add a new challenge type, rate-limit automated traffic, or block
  headless browsers outright, with no notice and no versioned contract to detect the change ahead
  of time except the synthetic monitor.
- Running a real browser per session is materially more expensive (CPU, memory, container
  lifecycle) than an HTTP client call, and scales worse under load.
- No standing session token means every sync may require live user interaction (password + OTP),
  which is a real UX cost and a real limitation on "background sync" as a future feature —
  intentionally, per §13.3, but it is a cost, not a free win.
- OTP relay through Reverb is a new real-time dependency in the critical path of authentication:
  if Reverb is down, SPV login cannot complete, not just "a live update is delayed."
- A DOM-contract synthetic test can produce false confidence: it can pass while a *behavioral*
  change (not a structural one) breaks a real submission — e.g. ANAF adds a new validation step
  that only appears with real, non-synthetic data.

## Alternatives considered

- **Build against undocumented private ANAF endpoints observed via reverse-engineering.**
  Rejected outright — `docs/AGENTS.md` rule 1 forbids relying on an unverified/undocumented ANAF
  endpoint as a dependency, and it would fail without warning the moment ANAF changes internal
  implementation details that were never a contract.
  the moment ANAF changed an implementation detail with no contract in place.
- **Persist SPV passwords encrypted at rest for convenience.** Rejected — §13.3 and §34
  (non-goals) explicitly rule out permanent SPV password storage; it maximizes blast radius of a
  single DEK compromise for no proportionate benefit, and turns a UX convenience into a standing
  liability.
- **Skip Playwright entirely and require the user to file manually on the ANAF portal.**
  Rejected as contrary to the product's purpose (automating D212 filing, §12, §M9–M10) — it would
  reduce the product to a calculator with no filing capability, which the blueprint explicitly
  treats as insufficient (§0: "Produsul NU este un formular D212").

## References

- `BLUEPRINT.md` §13 (SPV PF via Playwright, full flow), §3.3/§3.4 (network segmentation, browser
  hardening), §23 (SPV-related alerts), §34 (non-goals: no permanent SPV password storage), §38-G
  (architectural success criterion for a changed SPV DOM).
- `docs/AGENTS.md` rules 5, 13, 16, 20 (credential handling, external sends, selector policy,
  threat-model note requirement for SPV work).
- ANAF service listing referenced in `BLUEPRINT.md` §37: static.anaf.ro service list, SPV PF/PJ
  enrollment page — used as the discovery starting point for what is actually documented, not as
  a source of undocumented endpoints to build against.
