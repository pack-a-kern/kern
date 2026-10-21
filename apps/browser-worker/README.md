# browser-worker

A browser you can send instructions to. It opens a real Chromium, does what a **playbook** tells it,
and gives back structured data. It knows nothing about any website — every site-specific fact
arrives with the request.

Nothing in it is specific to the application that happens to run it today.

## Why a playbook rather than code

A playbook is configuration: URLs, selectors, and what the page looks like when each thing has
happened. It is sent with the request, so changing a selector is an edit to a file wherever the
caller keeps it — no rebuild of this service, no deploy.

What makes that safe to accept over the network is the one thing a playbook **cannot** do: there is
no step that runs caller-supplied JavaScript, and there will not be. A playbook composes nine verbs
this worker already implements. It can never invent a capability, which is what separates accepting
configuration from accepting a script.

## The API

```
POST   /v1/sessions              open a browser                  → { session_id, expires_at }
POST   /v1/sessions/{id}/run     run a sequence in it            → { outcome, data, timing_ms }
DELETE /v1/sessions/{id}         close it
POST   /v1/run                   open, run one sequence, close
GET    /v1/sessions              what is open
GET    /v1/stats                 counts, durations, queue depth
GET    /health                   unauthenticated
```

A session exists for one reason: **the pause**. A login that emails a code needs the browser still
open while a person reads their inbox. Everything else uses `/v1/run`.

## A playbook

```yaml
version: 1
name: example

# Every navigation is checked against this, and again on every redirect. "*" is allowed and means
# any host — written out, so a playbook that wants the whole web says so where a reader can see it.
allow:
  - example.com

context:
  device: "iPhone 13"        # or viewport / userAgent / locale / timezoneId / proxy
  block: ["image", "font"]   # resource types the page does not need

sequences:
  signIn:
    - goto: https://example.com/login
    - fill:  { selector: "#user", value: "{{ secret.username }}" }
    - fill:  { selector: "#pass", value: "{{ secret.password }}", sensitive: true }
    - click: { selector: "button[type=submit]" }
    - waitFor:
        any:
          - { selector: "#otp",   outcome: awaiting_challenge }
          - { selector: ".error", outcome: failed, message: ".error" }

  inbox:
    - extract:
        rows: "table.messages tbody tr"
        fields:
          id:      { attr: "data-id" }
          subject: { text: "td.subject" }
        into: messages
```

### The verbs

`goto` · `fill` · `click` · `select` · `waitFor` · `extract` · `screenshot` · `download` · `upload`

Each takes an optional `timeout`, and an optional `frame` for pages that put their form in one.

### Outcomes

A login does not succeed or fail — it arrives at one of several pages, and only the page can say
which. `waitFor.any` races the branches and reports whichever appeared, under the name the playbook
gave it. "awaiting_challenge" is the author's word, not this worker's.

## Secrets

Values are never written in a playbook. It writes `{{ secret.name }}`; the value arrives with the
request, lives as long as the run, and is substituted the moment before it is typed.

Two things follow, both enforced here rather than left to the caller:

- **Every message on the way out is redacted.** Playwright quotes values back in the errors it
  throws, and those errors would otherwise become the response body.
- **`sensitive: true` ends capture for the run.** Once a password is in the page, a screenshot taken
  three steps later still shows it, so capture stops permanently rather than for one step. Asking
  for a screenshot afterwards is refused out loud, not skipped quietly.

## Configuration

| variable | default | |
|---|---|---|
| `PORT` | `8090` | |
| `BROWSER_AUTH_MODE` | `token` | `network` trusts whatever can reach the port |
| `BROWSER_AUTH_TOKEN` | — | required in `token` mode; refuses every request while unset |
| `BROWSER_SESSION_TTL_MS` | `900000` | |
| `BROWSER_CONCURRENCY` | `1` | each run holds a browser and several hundred megabytes |
| `BROWSER_MAX_WAITING` | `8` | beyond this, a run is refused rather than queued |
| `BROWSER_MAX_SESSIONS` | `4` | browsers open at once, for every caller together; beyond this a new one is refused `503 at_capacity` and the open ones keep working. Sized to the container's memory |

`token` is the default deliberately: a missing variable must never be the thing that removes the
only guard in front of a browser.

## Running the tests

They drive a real Chromium, which lives in the image rather than on a developer's machine:

```
docker build -f docker/playwright/Dockerfile --target test -t browser-worker-test .
docker run --rm --shm-size=1gb browser-worker-test npm test
```

## What this is not

No proxy rotation, no stealth, no CAPTCHA solving, no CDP passthrough. Those exist in commercial
browser services because their customers scrape sites that resist them. The seams are here — a
proxy is a field on `context` that reaches a function that already exists — but nothing is built
that has no use.
