# browser-worker

A browser you can send instructions to. It opens a real Chromium, does what a **playbook** tells it,
and gives back structured data — or, when asked, a page exactly as its server sent it. It knows
nothing about any website: every site-specific fact arrives with the request.

Nothing in it is specific to the application that happens to run it today.

## Why a playbook rather than code

A playbook is configuration: URLs, selectors, and what the page looks like when each thing has
happened. It is sent with the request, so changing a selector is an edit to a file wherever the
caller keeps it — no rebuild of this service, no deploy.

What makes that safe to accept over the network is the one thing a playbook **cannot** do: there is
no step that runs caller-supplied JavaScript, and there will not be. A playbook composes ten verbs
this worker already implements. It can never invent a capability, which is what separates accepting
configuration from accepting a script.

## The API

```
POST   /v1/sessions              open a browser                  → 201 { session_id, expires_at }
POST   /v1/sessions/{id}/run     run a sequence in it            → { outcome, data, timing_ms, session_expires_at }
DELETE /v1/sessions/{id}         close it                        → { destroyed }
POST   /v1/run                   open, run one sequence, close   → { outcome, data, timing_ms }
POST   /v1/source                the same, answered with the page a `source` step kept (raw body)
GET    /v1/sessions              what is open
GET    /v1/stats                 counts, durations, queue depth
GET    /health                   unauthenticated
```

Every `/v1` call carries `Authorization: Bearer <BROWSER_AUTH_TOKEN>` (see Configuration).

A session exists for one reason: **the pause**. A login that emails a code needs the browser still
open while a person reads their inbox. A search that reads several pages of results in one browser
uses one too. Everything else uses `/v1/run` or `/v1/source`, which open a browser for the one request
and close it on the way out, whatever happened.

### A one-shot run

```http
POST /v1/run
Content-Type: application/json

{
  "playbook": { …see below… },
  "sequence": "search",
  "secrets":  { "url": "https://example.com/search?q=tax" }
}
```

```json
{
  "outcome": "found",
  "data": { "results": [ { "title": "First result", "link": "/item/1" } ] },
  "timing_ms": 1840
}
```

### A page as its server sent it

```http
POST /v1/source
Content-Type: application/json

{ "playbook": { … }, "sequence": "page", "secrets": { "url": "https://example.com/item/1" } }
```

```http
HTTP/1.1 200 OK
Content-Type: text/html; charset=utf-8
X-Source-Url: https://example.com/item/1

<!doctype html>…the bytes the server sent, not Chromium's rendering of them…
```

The body is the response to the sequence's last `goto` before its `source` step, so a caller that
downloads pages directly and one that reads them through this worker store and parse the same thing.
`X-Source-Url` is where it was served from, after any redirect.

### A browser kept open between sequences

```http
POST /v1/sessions                       { "playbook": { … } }                         → { "session_id": "3f…", … }
POST /v1/sessions/3f…/run               { "playbook": { … }, "sequence": "signIn", "secrets": { … } }
          … a person reads the code from their e-mail …
POST /v1/sessions/3f…/run               { "playbook": { … }, "sequence": "challenge", "secrets": { "code": "123456" } }
DELETE /v1/sessions/3f…
```

A session left alone is closed after `BROWSER_SESSION_TTL_MS` of idleness; each run in it renews that.

### When it says no

| status | `error` | meaning |
|---|---|---|
| 401 | — | missing or wrong token |
| 403 | `destination_refused` | a `goto` names a host the playbook does not allow |
| 404 | `unknown_session` | the session expired, was closed, or the worker restarted |
| 413 | `source_too_large` | the page is larger than `BROWSER_MAX_SOURCE_BYTES` |
| 422 | `playbook_invalid` | the playbook does not parse; `problems` says where |
| 422 | `secret_missing` | a `{{ secret.x }}` the request did not send |
| 422 | `step_failed` | a step could not be done — the page was not what the playbook described |
| 422 | `source_missing` | `/v1/source` for a sequence with no `source` step |
| 502 | `source_status` | the page's server refused it (`status` says how); its error page is not handed back |
| 502 | `run_failed` | the run broke for another reason, said in `message` |
| 503 | `at_capacity` | every browser or queue slot is taken; try again shortly |

Every message is redacted before it leaves (see Secrets).

## A playbook

```yaml
version: 1
name: example

# Where this browser may go — every request it makes, not only the pages a step opens (see "Where a
# browser may go"). A name admits its own subdomains: `example.com` allows `www.example.com`, not
# `evil-example.com`. "*" means any public host, written out so it is a decision someone made.
allow:
  - example.com

context:
  engine: chromium                    # or firefox / webkit — Chromium when unnamed; match the userAgent
  device: "iPhone 13"                 # or viewport / userAgent / locale / timezoneId
  block: ["image", "font", "stylesheet", "media"]   # resource types the page does not need
  proxy:                              # optional — go out through a proxy of your own (see below)
    server: "http://mobile-proxy.example.net:8000"
    username: "{{ from your own config }}"
    password: "{{ from your own config }}"

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
          - { selector: "form#login", state: absent, outcome: authenticated }

  search:
    - goto: "{{ secret.url }}"
    - waitFor:
        any:
          - { selector: ".result", outcome: found, continue: true }   # a guard: carry on
          - { selector: ".no-results", outcome: none }                 # ends the sequence
    - extract:
        rows: ".result"
        fields:
          title: { selector: "h2", rendered: true }
          link:  { selector: "a", attr: "href" }
        into: results

  page:
    - goto: "{{ secret.url }}"
    - source: true
```

A playbook's `proxy` credentials are the caller's to keep: put them in its own configuration and fill
them in when the playbook is sent, never in a file that is committed.

### The verbs

`goto` · `fill` · `click` · `select` · `waitFor` · `extract` · `screenshot` · `download` · `upload` · `source`

Each takes an optional `timeout`, and an optional `frame` for pages that put their form in one.

- **`extract`** reads rows and fields as data, not HTML. A field reads `textContent` by default;
  `rendered: true` reads the text as the page shows it (`innerText`), with a line break where the page
  breaks one — so words a `<br>` separates stay apart (`…2015<br>privind…` is "2015 privind", not
  "2015privind"). `attr` reads an attribute instead.
- **`source: true`** keeps the page as its server answered the last `goto`, byte for byte — not
  Chromium's document after its scripts ran. Only `/v1/source` sends it back. A click forgets it: a
  `source` after a click refuses, rather than handing back the page from before the click — unless
  the click captured.
- **`click`** with `capture: { url: "https://host/path", query: { rs: "1D" } }` keeps the first
  response the click makes the page fetch at that address — before its query — with each of those
  query values, for a `source` after it: what a chart loads when its range is picked, say. The host
  must be one `allow` names; it is listened for before the click.
- **`waitFor`** races its branches; see Outcomes.

### Outcomes

A login does not succeed or fail — it arrives at one of several pages, and only the page can say
which. `waitFor.any` races the branches and reports whichever appeared, under the name the playbook
gave it. "awaiting_challenge" is the author's word, not this worker's. A branch marked `continue` is a
guard: the sequence goes on past it, which is how "make sure we are on the results page, then read
them" is written.

## Where a browser may go: the gate

Every browser this worker opens goes out through a gate of its own — a small HTTP proxy on loopback
inside the worker, started with the browser and closed with it, holding that playbook's `allow` list
(`src/network/egressProxy.ts`). Chromium is launched to send **every** request through it.

```
                       the worker's container
  ┌───────────────────────────────────────────────────────────┐
  │  Chromium ──► gate (127.0.0.1, one per browser) ──────────┼──► the site
  │               · host in the playbook's `allow`?            │
  │               · resolves to a public address?              │
  └───────────────────────────────────────────────────────────┘
```

The gate sees what no step does: each hop of a redirect, and every script, frame, image and request a
page makes on its own. With `allow: [example.com]`:

| the browser asks for | the gate |
|---|---|
| `https://example.com/login` | lets it through |
| `https://www.example.com/app.js` | lets it through — a subdomain of an allowed name |
| `https://cdn.other.net/lib.js` | refuses: not in `allow` |
| a redirect from example.com to `https://evil.example.org/` | refuses, before that address is asked |
| a redirect to `http://192.168.1.1/` or `http://169.254.169.254/` | refuses: a private address |
| `example.com`, if its DNS were made to answer `10.0.0.5` | refuses: resolves into a private network |

**Private networks** are refused whatever `allow` says — loopback, RFC 1918, link-local and the cloud
metadata address, carrier-grade NAT, unique-local IPv6 — so a site allowed by name cannot send the
browser into this server or the machines beside it, by a redirect or by its DNS. The address checked
is the address connected to. The one exception is an IP address `allow` names exactly
(`allow: ["10.0.0.5"]`), which is how a test reaches a server on `127.0.0.1`; `"*"` does not name one.

A refusal is logged as `egress_refused` with the host only — never the path or query, which can carry
what a run was asked to keep to itself. To the browser it is a failed connection, so a refused page
can never be kept by a `source` step as if a site had served it.

### Through a proxy of your own

A playbook may go out through a proxy it names — a mobile or residential one, so a site sees that
network rather than this server's:

```yaml
context:
  proxy: { server: "http://mobile-proxy.example.net:8000", username: "u", password: "p" }
```

It is chained **behind** the gate, never in its place:

```
  Chromium ──► gate (checks every request) ──► your proxy ──► the site
```

The browser still talks only to the gate; what the gate lets pass it sends on through your proxy — a
plain request as a proxied request, https as a `CONNECT` tunnel to the same host — with
`Proxy-Authorization` when you gave credentials. What the gate refuses never reaches your proxy.
Handed to Chromium directly, that proxy would carry the browser round every check above, which is why
a playbook cannot do that.

- `server` must be `http://host:port` — not `https://`, not `socks5://`, and with no credentials
  written into it (they go in `username` and `password`). The traffic inside a tunnel is still the
  browser's own TLS to the site.
- The proxy's own address is the playbook's to choose, a private one included: it is where the
  playbook's author decided the traffic leaves.
- A host is checked against the private ranges as the worker resolves it; your proxy resolves it again
  for itself.

## Secrets

Values are never written in a playbook. It writes `{{ secret.name }}`; the value arrives with the
request, lives as long as the run, and is substituted the moment before it is used. A caller passes
every value it fills in this way — a password, a search term, an address to open — since one more
redacted string costs nothing.

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
| `BROWSER_SESSION_TTL_MS` | `900000` | how long a session may sit idle |
| `BROWSER_CONCURRENCY` | `1` | each run holds a browser and several hundred megabytes |
| `BROWSER_MAX_WAITING` | `8` | beyond this, a run is refused rather than queued |
| `BROWSER_MAX_SOURCE_BYTES` | `10485760` | the largest page a `source` step hands back |
| `BROWSER_MAX_SESSIONS` | `4` | browsers open at once, for every caller together; beyond this a new one is refused `503 at_capacity` and the open ones keep working. Sized to the container's memory |

`token` is the default deliberately: a missing variable must never be the thing that removes the
only guard in front of a browser.

Every browser starts empty — no profile, no cookies, no cache — and everything it had is deleted when
it closes, its downloads folder included. Nothing one caller's browser saw reaches the next.

## Running the tests

They drive a real Chromium, which lives in the image rather than on a developer's machine:

```
docker build -f docker/playwright/Dockerfile --target test -t browser-worker-test .
docker run --rm --shm-size=1gb browser-worker-test npm test
```

## What this is not

No proxy rotation, no stealth, no CAPTCHA solving, no CDP passthrough. Those exist in commercial
browser services because their customers scrape sites that resist them. One proxy per playbook,
behind the gate, is the seam that exists; rotating through several is not built, having no use.
