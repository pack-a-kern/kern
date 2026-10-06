# ADR 0019 — The browser worker takes playbooks, not portals

**Status:** accepted
**Date:** 2026-10-06
**Supersedes part of:** [ADR 0003](0003-playwright-for-spv-pf.md) — the transport, not the choice of
Playwright, which stands.

## Context

`apps/spv-browser` existed to drive one website. ANAF's SPV portal was its subject: the routes were
named for it (`/v1/spv/sessions/{id}/credentials`), the session states were its login's states, and
the selectors — once anybody observed them — would have been TypeScript inside the image.

That shape has one cost that only appears later. A portal changes its HTML on its own schedule, and
under that design every such change is a code edit, a container rebuild and a deploy of a service
that drives a browser. The thing most likely to need changing was the thing hardest to change.

It also made a second site a second service, or a second set of routes in the first.

## Decision

The worker interprets a **playbook** supplied with each request: the destinations it may visit, the
selectors to act on, and what each page looks like when something has happened. It holds no
knowledge of any website. `apps/spv-browser` became `apps/browser-worker` to say so.

ANAF now lives in `packages/tax-romania/config/playbooks/anaf_spv.php` — three sequences, sent by
`PlaybookBrowserSessionClient` with every call.

### The vocabulary is closed, and that is the whole safety argument

Nine verbs: `goto`, `fill`, `click`, `select`, `waitFor`, `extract`, `screenshot`, `download`,
`upload`. There is **no step that runs caller-supplied JavaScript**, and there will not be.

That single omission is what separates accepting configuration from accepting a script. A playbook
composes behaviours this worker already implements and was reviewed for; it can never introduce one.
Browserless draws the same line — its declarative scrape API takes selectors and wait conditions as
data and exposes no run-my-code endpoint — and it is the line that makes a playbook safe to take
over a network.

### Outcomes belong to the playbook

A login does not succeed or fail: it arrives at one of several pages, and only the page can say
which. `waitFor.any` races the branches and reports whichever appeared, under the name the playbook
gave it. "Awaiting a challenge" is the author's word. The worker has no opinion about what a portal's
states are called, which is what lets the next portal name them differently and need no change here.

### Destinations are declared, including the open case

Every playbook lists the hosts it may reach, checked before each navigation and again on every
redirect — a redirect is a navigation the page chose. A host admits its subdomains and nothing that
merely ends with the same letters. `"*"` is permitted and must be written out, so a playbook that
wants the whole web says so where a reader can see it and none arrives there by omission.

## Alternatives considered

**Keep the portal compiled in.** Simplest, and the status quo. Rejected because it makes the most
volatile part of the system the most expensive to change.

**Adopt Browserless.** The mature product in this space, self-hostable, with the declarative API this
design borrows from. Rejected on licence: SSPL-1.0 or a commercial licence, and Kern is commercial.
This repository already rejected a source-available licence once — [ADR 0002](0002-envelope-encryption.md)'s KMS choice took
OpenBao specifically to avoid Vault's BUSL — and taking SSPL here would contradict that.

It would also not have removed the work. Their declarative API is single-shot, while an SPV sign-in
pauses in the middle while somebody reads an email; that needs a browser held open across requests,
driven by our own code over CDP. We would have replaced the Express wrapper and kept everything that
was actually missing, plus a licence.

**Selectors as config, flow still compiled.** The middle option, and briefly the recommendation. It
buys the same "edit a file, no rebuild" property with a smaller surface. Rejected because the step
*sequence* changes as often as the selectors do — a portal that adds a consent screen needs a step,
not a selector — and splitting them would put half of one flow in two places.

## Consequences

A selector change is an edit to one config file in `packages/tax-romania`. No rebuild of the worker,
no deploy of it, and no PHP.

A second portal is a second playbook and no worker change at all, as long as it needs nothing outside
the nine verbs. Needing a tenth verb is a worker change — the vocabulary is data's limit, and that is
deliberate.

The worker is now reusable outside this application, and nothing in it knows what a tax authority is.
That was not the goal, but it follows from the shape and is the reason the service was renamed rather
than kept under an SPV name.

What this does **not** do is unblock SPV. The selectors still have to be observed
(`tools/observe-spv-page.js`, `docs/AGENTS.md` §1 rule 1). It changes what that observation produces:
a config file rather than code.
