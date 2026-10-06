# 048 — The browser worker takes playbooks; SPV gets its endpoints and screen

**Milestone:** M10   **Status:** complete (except the selectors, which need one observed session)
**Date:** 2026-10-06
**ADR:** [0019](../adr/0019-the-browser-worker-takes-playbooks.md)

## What was built

**`apps/spv-browser` → `apps/browser-worker`.** It no longer knows any website. A *playbook* sent
with each request carries the destinations, the selectors and what each page looks like when
something has happened. Nine verbs — `goto`, `fill`, `click`, `select`, `waitFor`, `extract`,
`screenshot`, `download`, `upload` — and no step that runs caller-supplied JavaScript, which is the
property that makes a playbook safe to accept over a network. See ADR 0019 for the reasoning and the
alternatives, including why Browserless was rejected on licence.

**SPV's HTTP layer.** The module had a complete domain layer with nothing in front of it: no
controllers, no routes, and `/spv` rendering a placeholder. Seven endpoints now, a connections
screen, and a three-step sign-in dialog — credentials, the emailed code, and the browser held open
between them.

**`PlaybookBrowserSessionClient`** implements the contract the actions already used, so swapping
transports was a binding rather than a rewrite. ANAF lives in
`packages/tax-romania/config/playbooks/anaf_spv.php`.

## Threat-model note (required by `docs/AGENTS.md` §1 rule 20)

**A password is never stored, and now never leaves.** `SpvCredentialPolicy` has one case and
forbids persisting it; this adds the other half. Secrets travel beside a playbook as
`{{ secret.name }}` placeholders, resolved the moment before the value is typed. Every message
leaving the worker is redacted first — Playwright quotes values back in the errors it throws, and
those errors would otherwise become the response body. Longest secret first, so a short one inside a
longer one cannot leave half of it readable.

**Capture is refused rather than filtered.** A step marked `sensitive` ends screenshots and tracing
for the rest of the run, not for itself: once a password is in the page, a screenshot three steps
later still shows it. A later screenshot is refused out loud, so a caller expecting an image is told
why there is none.

**Credentials never reach a queue.** Every credential route is synchronous. A queued job serialises
its arguments into Redis, which would be the password at rest that the policy exists to forbid.

**Destinations are declared per playbook**, checked before every navigation and again on every
redirect. A host admits its subdomains and nothing that merely ends with the same letters —
`evil-anaf.ro` is not admitted by `anaf.ro`. `file:`, `data:` and `javascript:` are refused outright.
`"*"` exists and must be written out.

**The worker's own surface** is unchanged in shape: no published ports, read-only filesystem, no
`env_file`, and one network carrying no database or object-store credentials. It now receives exactly
one variable, named explicitly in `compose.yaml`.

## Tests

```
apps/browser-worker          46 passed   (real Chromium, pages served from memory)
apps/api  SPV suite          31 passed
apps/api  sqlite lane       936 passed
apps/api  postgres lane    1029 passed
```

The worker's tests run inside the image, where the browsers are — the same arrangement
`docker/python/Dockerfile` makes, added to `make test` and `make lint`.

## Renaming

Nothing points at SPV any more:

| was | is |
|---|---|
| `SPV_BROWSER_BASE_URL` | `BROWSER_WORKER_URL` (defaulted; unset is fine) |
| `SPV_BROWSER_AUTH_TOKEN` | `BROWSER_WORKER_TOKEN` (empty means no header sent) |
| `SPV_BROWSER_TIMEOUT_SECONDS` | `BROWSER_WORKER_TIMEOUT_SECONDS` |
| config `spv.browser_worker.*` | `browser_worker.*`, its own file |
| setting `spv.request_timeout_seconds` | `browser_worker.timeout_seconds` |
| compose service `spv-browser` | `browser-worker` (this is the DNS name) |

Handoff notes were left alone: they are dated records of what was true when written.

## Deleted

`HttpSpvBrowserSessionClient`, the worker's `/v1/spv/*` routes, `portalAuthenticator.ts` and
`notImplemented.ts`. Nothing resolved them after the binding changed.

## Open questions

1. **The selectors are placeholders.** Nobody has observed ANAF's pages, and rule 1 forbids writing
   against a DOM that has not been seen — in a config file exactly as in code. `tools/observe-spv-page.js`
   collects the structure without reading any field's value. What it prints goes into the playbook,
   and nothing else changes.

2. **Authentication between the app and the worker is off by default** (`BROWSER_AUTH_MODE=network`
   in `compose.yaml`). Defensible while only the application can reach the port — nothing is
   published to the host, and `browser_net` holds two services — but it is a deployment's decision
   to revisit if that ever stops being true. Setting `BROWSER_WORKER_TOKEN` on both sides turns it
   on.

3. **A certificate is still installation-wide**, read from the filesystem by env. Per-taxpayer
   certificates would mean the application holding a qualified signing credential, which is a scope
   decision rather than a technical one and wants its own ADR before anybody builds it.
