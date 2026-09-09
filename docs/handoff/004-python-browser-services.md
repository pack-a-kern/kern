# 004 — Processing plane (FastAPI) and SPV browser worker (Playwright) skeletons

**Milestone:** M0   **Status:** complete
**Date:** 2026-09-07

## What was built

Two standalone services, per `BLUEPRINT.md` §1.2/§3.1/§13.4 and `docs/AGENTS.md`:

1. **`apps/processing`** — the FastAPI service shared by the `python-api` and `python-worker`
   containers. Python 3.14, `uv`-managed. Holds no Laravel DB credentials and writes to no
   Laravel table (§3.3). Entry point: `src/processing/main.py` (`app = FastAPI(...)`, `GET
   /health`). Also ships the AI provider abstraction required now by ADR 0007 (interface + config
   plumbing only, per the task brief — no prompts, no extraction logic, no OCR).
2. **`apps/spv-browser`** — a TypeScript/Express service exposing exactly the six-endpoint HTTP
   contract from `BLUEPRINT.md` §13.4, backed by a real (but ANAF-behaviour-free) ephemeral-session
   lifecycle: one Playwright Chromium + one browser context per session, ephemeral download
   directory, TTL-based auto-expiry, explicit destroy. Entry point: `src/server.ts`.

No ANAF-specific navigation, selectors, URLs, field names, or error codes were written anywhere,
per the explicit constraint in the task brief and `docs/AGENTS.md` §1 rule 1. The four portal
routes (`/credentials`, `/challenge`, `/actions/sync`, `/actions/submit-d212`) validate that the
target session exists, then return HTTP 501 with `{"error": "not_implemented", ...}`.

## Files created / changed

### `apps/processing/`
- `pyproject.toml` — deps (exact lower+upper bounds per `docs/DEPENDENCY-POLICY.md`), ruff/pyright/pytest config.
- `uv.lock` — committed.
- `src/processing/main.py` — FastAPI app, `/health`.
- `src/processing/logging_config.py` — JSON log formatter with a **fixed field allowlist**
  (`_ALLOWED_EXTRA_FIELDS`); anything not on the list is dropped, not serialized. This is the
  actual no-PII-in-logs mechanism, not a convention someone has to remember.
- `src/processing/middleware.py` — `CorrelationIdMiddleware`: reuses/mints `X-Request-Id`,
  echoes it, logs one `http_request` line per request (method/path/status/duration only).
- `src/processing/ai/config.py` — `AISettings` (`AI_PROVIDER`/`AI_BASE_URL`/`AI_MODEL`/`AI_API_KEY`).
- `src/processing/ai/schemas.py` — `ChatMessage`/`ChatCompletionRequest`/`ChatCompletionResult`
  (strict Pydantic DTOs mirroring the OpenAI-compatible wire shape; `role` excludes `tool` on
  purpose — the extraction model this feeds is never given tools, `BLUEPRINT.md` §14.5).
- `src/processing/ai/client.py` — `OpenAICompatibleClient` (httpx2-based), `AIProviderError`.
- `src/processing/ai/structured.py` — `parse_structured_output()` / `StructuredOutputError`: the
  structured-output enforcement seam. Invalid output is a hard failure, never a best-effort save
  (`BLUEPRINT.md` §14.4).
- `tests/` — 21 tests: health/correlation-id, logging allowlist behaviour, AI config loading,
  the HTTP client (via `httpx2.MockTransport`, no network), structured-output parsing, and a
  dependency-import smoke test for PyMuPDF/OpenCV on Python 3.14.
- `.gitignore` — `.venv/`, caches.

### `apps/spv-browser/`
- `package.json` / `package-lock.json` — exact-pinned deps, committed lockfile.
- `tsconfig.json` / `tsconfig.build.json` (src → `dist/`, the real build) / `tsconfig.test.json`
  (src+tests → `dist-test/`, so `node --test` has compiled test files to run — Node 20.19 has no
  native TS execution, and `dist/` must stay test-free for a clean production image).
- `eslint.config.js` — flat config, `typescript-eslint` strict+stylistic type-checked configs.
- `src/logger.ts` — same allowlisted-JSON-log pattern as the Python side, using
  `AsyncLocalStorage` for the correlation id.
- `src/middleware/{correlationId,requestLogging,errorHandler}.ts`.
- `src/sessions/types.ts`, `browserContextFactory.ts` (real Playwright: one Chromium + one
  context per session, `downloadsPath` set to a fresh `mkdtemp` dir, both torn down together),
  `sessionManager.ts` (create/get/destroy/TTL-sweep; takes the browser-context factory as a
  constructor argument so tests never touch Playwright or the network).
- `src/notImplemented.ts` — the shared 501 payload shape for the four portal routes.
- `src/routes/{health,sessions}.ts`, `src/app.ts` (wiring), `src/server.ts` (entry point, TTL/port
  from env, graceful `SIGTERM`/`SIGINT` shutdown that closes every open session).
- `tests/` — 22 tests: full HTTP contract (all 6 endpoints incl. 404-before-501 ordering and the
  correlation-id header), session manager lifecycle/isolation/TTL sweep, logger allowlist
  behaviour — all against a fake browser-context factory (see "What is NOT done").
- `.gitignore` — `node_modules/`, `dist/`, `dist-test/`.

## Key decisions

1. **`httpx2`, not `httpx`, for the AI client.** Starlette 1.6.0 (pulled in by FastAPI 0.141.1)
   deprecates `httpx` inside `starlette.testclient` in favour of a new package, `httpx2`
   (`github.com/pydantic/httpx2`, BSD-licensed, actively released — 16 releases through
   2026-08-18, vs. classic `httpx`'s last release 2024-12-06). Using classic `httpx` would emit a
   `DeprecationWarning` on every `TestClient` import, which this project's `filterwarnings =
   ["error"]` turns into a test-collection failure. `httpx2`'s public API is a near-exact mirror
   of `httpx` (`AsyncClient`, `MockTransport`, `Response`, `codes`, …), confirmed by installing it
   and inspecting `dir(httpx2)`. Standardised on `httpx2` everywhere rather than depending on
   both. **This needs an ADR** (out of my scope to write — `docs/adr/` wasn't in my assigned
   paths); flagging for whoever owns that directory.
2. **TypeScript pinned to `6.0.3`, not the `latest` `7.0.2`.** TypeScript 7 is a real, current
   stable release (native/Go-ported compiler; confirmed via `npm view typescript dist-tags` —
   `latest: 7.0.2`, distinct from `beta`/`rc` tags). However `typescript-eslint@8.70.0`'s
   `peerDependencies` caps at `typescript: ">=4.8.4 <6.1.0"` — it does not yet support TS7. Since
   TS-aware linting requires `typescript-eslint`, and no alpha/beta/RC is allowed as a substitute,
   `6.0.3` (released 2026-04-16, the newest release inside that supported range) is the correct
   pin today. **Also needs an ADR**, same reasoning as above.
3. **AI provider config contract (ADR 0007), exact shape for Milestone 7 to build on:**
   - Env vars: `AI_PROVIDER` (free-text label, observability only — never branches behaviour),
     `AI_BASE_URL` (`HttpUrl`; `/v1/chat/completions` is appended by the client), `AI_MODEL`
     (sent as the `model` field), `AI_API_KEY` (optional `SecretStr`; sent as `Authorization:
     Bearer <key>` when present, omitted entirely when absent — for an unauthenticated local
     vLLM/Ollama endpoint).
   - `processing.ai.config.AISettings` loads these (pydantic-settings, `env_prefix="AI_"`).
   - `processing.ai.client.OpenAICompatibleClient` — one client for every backend speaking the
     OpenAI-compatible shape. `OpenAICompatibleClient.from_settings(settings)` is the normal
     construction path. `.complete(ChatCompletionRequest) -> ChatCompletionResult` raises
     `AIProviderError` on any non-2xx response or unrecognised response shape — never silently
     retried into a guess.
   - `processing.ai.structured.parse_structured_output(result, YourPydanticModel)` validates a
     completion's `content` as JSON against any Pydantic model, raising `StructuredOutputError`
     on mismatch. M7 is expected to build prompts/schemas around this seam, not replace it.
   - No provider SDK dependency (`openai`, `anthropic`, etc.) exists in this project by design.
4. **No `respx`/`supertest`/`vitest`/`jest` added.** Python AI-client tests use
   `httpx2.MockTransport` directly; TypeScript HTTP tests bind the real `http.Server` to port 0
   and use the platform `fetch`; TypeScript tests run on Node's built-in `node:test` runner
   (compiled first via `tsconfig.test.json`, since Node 20.19 cannot execute `.ts` natively).
   Fewer moving parts, no version drift between a mocking library and the HTTP client it mocks.
5. **`SessionManager` takes its browser-context factory as a constructor argument.** One
   production implementation (`createPlaywrightBrowserContext`) and one test fake — not
   speculative, both are exercised now. This is what let session lifecycle (create/get/destroy/
   TTL sweep/isolation) be fully tested without Chromium binaries in this sandbox (see below).
6. **SPV session TTL default (15 minutes, `SPV_SESSION_TTL_MS` env override) is an engineering
   default I chose, not a value from the blueprint.** `BLUEPRINT.md` §3.4 says browser sessions need
   a TTL but does not give a number. Flagged under Open questions — needs a deliberate decision
   before M10, not a silent carry-forward of my default.
7. **Default port for `apps/spv-browser` is `8090`** (`PORT` env override). Not coordinated with
   whoever defines `docker/docker-compose.yml` — check for a collision or an expected value there.

## How to verify

### `apps/processing` (from `apps/processing/`, with `uv` on `PATH`)

```
$ uv lock --check
Resolved 58 packages in 1ms

$ uv run ruff format --check .
16 files already formatted

$ uv run ruff check .
All checks passed!

$ uv run pyright
0 errors, 0 warnings, 0 informations

$ uv run pytest -q
.....................                                                    [100%]
21 passed in 0.21s

$ uv run pip-audit
No known vulnerabilities found
Name       Skip Reason
---------- -------------------------------------------------------------------------
processing Dependency not found on PyPI and could not be audited: processing (0.1.0)

$ uv sync --frozen
Checked 56 packages in 0.51ms
```

(`processing` itself is skipped by `pip-audit` because it's the local package, not a PyPI
dependency — expected and harmless.)

`uv` was not preinstalled on this host; installed via the official installer
(`curl -LsSf https://astral.sh/uv/install.sh | sh`) into `~/.local/bin`. Python 3.14.7 was
installed via `uv python install 3.14` (also not preinstalled; host default was 3.10.12).

### `apps/spv-browser` (from `apps/spv-browser/`, with Node 20.19.6)

```
$ npm ci
added 175 packages, and audited 176 packages in 1s
found 0 vulnerabilities

$ npx tsc --noEmit
(no output — clean)

$ npx eslint .
(no output — clean)

$ npm run build
> tsc -p tsconfig.build.json

$ npm test
> tsc -p tsconfig.test.json
> node --test dist-test/tests
...
1..22
# tests 22
# pass 22
# fail 0

$ npm audit --audit-level=high
found 0 vulnerabilities
```

## Interfaces exposed to other modules

### `apps/processing`
- `GET /health` → `{"status": "ok", "service": "processing"}`.
- Every response carries `X-Request-Id` (echoes an inbound one if present).
- `processing.ai.config.AISettings`, `processing.ai.client.OpenAICompatibleClient`,
  `processing.ai.client.AIProviderError`, `processing.ai.client.ChatCompletionClient` (the
  `Protocol` seam for substituting a fake in tests), `processing.ai.schemas.{ChatMessage,
  ChatCompletionRequest, ChatCompletionResult}`, `processing.ai.structured.{parse_structured_output,
  StructuredOutputError}` — all re-exported from `processing.ai`.

### `apps/spv-browser`
Exactly the contract from `BLUEPRINT.md` §13.4, plus `GET /health`:

| Method | Path | Behaviour today |
|---|---|---|
| POST | `/v1/spv/sessions` | Creates an ephemeral session. `201 {id, status, createdAt, expiresAt}`. |
| POST | `/v1/spv/sessions/{id}/credentials` | `404` if unknown; else `501 not_implemented`. |
| POST | `/v1/spv/sessions/{id}/challenge` | Same. |
| POST | `/v1/spv/sessions/{id}/actions/sync` | Same. |
| POST | `/v1/spv/sessions/{id}/actions/submit-d212` | Same. |
| DELETE | `/v1/spv/sessions/{id}` | Destroys the session (closes context+browser, deletes downloads dir). `204`, or `404` if unknown. |
| GET | `/health` | `200 {"status": "ok", "service": "spv-browser"}`. |

Every response carries `X-Request-Id`. `SessionManager`, `createPlaywrightBrowserContext`, and the
`BrowserContextFactory` type are exported from `src/sessions/` for M10 to build the real portal
automation against (inject a real, authenticated Playwright `Page` flow behind the existing
session lifecycle rather than replacing it).

## Dependencies added (exact version, license, last release, why)

### `apps/processing` — Python (`uv`, PyPI, checked 2026-09-07)

| Package | Version | License | Last release | Why |
|---|---|---|---|---|
| fastapi | 0.141.1 | MIT | 2026-07-29 | HTTP framework for the service. |
| httpx2 | 2.12.0 | BSD | 2026-08-18 | HTTP client for the AI provider call; required transitively by Starlette 1.6's `TestClient` (see decision #1). |
| opencv-python-headless | 5.0.0.93 | Apache-2.0 | 2026-07-02 | Required now per task brief for the future (M3/M7) document-image pipeline; wheel availability on Python 3.14 verified today by importing it in a real test. |
| pydantic | 2.13.5 | MIT | 2026-08-28 | Strict DTOs/settings validation throughout. |
| pydantic-settings | 2.15.0 | MIT | 2026-08-07 | Env-var-sourced `AISettings`. |
| pymupdf | 1.28.2 | **AGPL-3.0-or-later, dual-licensed with a commercial option from Artifex** | 2026-08-06 | Required now per task brief for future (M3/M7) native PDF text extraction; wheel availability on 3.14 verified today. **See Open questions — this license needs a legal/business decision before it ships in a proprietary service.** |
| uvicorn | 0.52.4 | BSD-3-Clause | 2026-08-19 | ASGI server. |
| pip-audit *(dev)* | 2.10.1 | Apache-2.0 | 2026-06-10 | Dependency vulnerability audit gate (`docs/DEPENDENCY-POLICY.md` §5). |
| pyright *(dev)* | 1.1.411 | MIT | 2026-06-25 | Strict type-checking gate. |
| pytest / pytest-asyncio *(dev)* | 9.1.1 / 1.4.0 | MIT / Apache-2.0 | 2026-06-19 / 2026-05-26 | Test runner + async test support. |
| ruff *(dev)* | 0.16.6 | MIT | 2026-09-03 | Lint + format gate. |

### `apps/spv-browser` — npm (checked 2026-09-07)

| Package | Version | License | Last release | Why |
|---|---|---|---|---|
| express | 5.2.1 | MIT | 2026-08-15 | HTTP routing/middleware; v5 auto-forwards rejected-promise handler errors to error middleware (no `express-async-errors` needed). |
| playwright | 1.63.0 | Apache-2.0 | 2026-09-07 | Browser automation; only its session/context lifecycle is used in M0. |
| typescript *(dev)* | 6.0.3 | Apache-2.0 | 2026-04-16 | Compiler; pinned below `latest` (7.0.2) — see decision #2. |
| typescript-eslint *(dev)* | 8.70.0 | MIT | 2026-09-07 | TS-aware ESLint rules; its peer range is why TS is pinned as above. |
| eslint *(dev)* | 10.10.0 | MIT | 2026-09-04 | Lint engine. |
| @eslint/js *(dev)* | 10.0.1 | MIT | 2026-07-10 | ESLint's own recommended rule set for the flat config. |
| @types/express *(dev)* | 5.0.6 | MIT | 2025-12-01 | Type declarations for Express 5. |
| @types/node *(dev)* | 20.19.43 | MIT | — | Matched to this host's Node runtime (`v20.19.6`); **not** the npm-`latest` major (26.x) — see Open questions on Node version alignment. |

`node:crypto` (`randomUUID`) and `node:async_hooks` (`AsyncLocalStorage`) are used instead of a
`uuid` package or a logging library — both are standard library, no dependency needed.

## What is NOT done

- **No ANAF portal automation.** All four portal-action endpoints are `501 not_implemented`
  stubs by design — Milestone 10 per the task brief.
- **No document extraction / OCR / prompts.** `processing.ai` is interface + config plumbing
  only, as instructed; PyMuPDF and OpenCV are dependencies with an import-smoke-test, not wired
  into any pipeline.
- **No real Playwright browser was launched.** This sandbox has no cached Chromium binaries
  (`~/.cache/ms-playwright` does not exist) and downloading one wasn't warranted for M0 session-
  lifecycle scaffolding. `createPlaywrightBrowserContext` type-checks cleanly against Playwright
  1.63.0's own bundled types (`downloadsPath`, `acceptDownloads` are real, verified options — TS's
  excess-property check on the object literals passed to `launch()`/`newContext()` would have
  failed compilation otherwise) but is **not** exercised by any test; only the fake factory is.
  Whoever builds the `spv-browser` Docker image must run `npx playwright install --with-deps
  chromium` and should add one real smoke test then.
- **No Docker wiring.** Owned by another agent per the task brief; `PORT`/`SPV_SESSION_TTL_MS`
  defaults here (8090 / 15 min) need to be reconciled with whatever `docker-compose.yml` expects.
- **No mTLS / signed-JWT internal auth** between Laravel and either service (`BLUEPRINT.md` §13.4).
  Both services currently accept unauthenticated requests — acceptable for M0 scaffolding, not
  for anything that reaches a real environment.
- **No ADR written** for decisions #1 and #2 above — `docs/adr/` was outside my assigned paths.

## Open questions

1. **PyMuPDF's AGPL-3.0-or-later license.** This is a copyleft license with network-use
   disclosure implications (AGPL §13) for a SaaS product; Artifex sells a commercial license as
   the alternative. This needs a legal/business decision before the document pipeline (M3/M7)
   actually ships, not just before this dependency was added — I added it because the task brief
   named it explicitly and `BLUEPRINT.md` §1.2 calls for it, but the license consequence itself
   needs sign-off. Escalating per `docs/AGENTS.md` §11.
2. **SPV session TTL** (`SPV_SESSION_TTL_MS`, default 900000ms/15min) — engineering default, not
   a blueprint value. Needs confirmation before M10.
3. **`apps/spv-browser` default port (8090)** and **`@types/node` major (20.x, matching this
   host, not npm's `latest` 26.x)** — both need reconciling once the Docker/Node runtime image
   for this service is decided.
4. **ADRs for the `httpx2` swap and the TypeScript 6.0.3 pin** should be written by whoever owns
   `docs/adr/` — both are exactly the kind of decision a future agent might otherwise reverse by
   accident (e.g. "upgrade to TS latest" without noticing the `typescript-eslint` peer ceiling).
5. Existing blocked items in `docs/STATUS.md` (#3, external AI provider/region/DPA) directly
   determine `AI_BASE_URL`/`AI_MODEL` in production — not new, just noting the dependency.

## Sources consulted

- `BLUEPRINT.md` §1.2, §3.1, §3.3, §3.4, §13.2, §13.4, §14, §14.4, §14.5, §19.1, §19.4, §19.5.
- `docs/AGENTS.md` (all sections; rule 1 and rule 9 specifically for the ANAF-fabrication and
  dependency-justification constraints).
- `docs/reference/verified-versions.md` (Python/PyMuPDF/opencv/paddlepaddle facts, used as given).
- `docs/DEPENDENCY-POLICY.md`, `docs/CODE-QUALITY.md` (read mid-task per coordinator message).
- PyPI JSON API (`pypi.org/pypi/<package>/json`), checked 2026-09-07, for: fastapi, pydantic,
  pydantic-settings, uvicorn, httpx, httpx2, pymupdf, opencv-python-headless, ruff, pyright,
  pytest, pytest-asyncio, pip-audit — version, license classifiers, last-release timestamp.
- `npm view <package> version|time.modified|license|dist-tags|peerDependencies|engines`, checked
  2026-09-07, for: typescript, @types/node, eslint, typescript-eslint, @eslint/js, express,
  @types/express, playwright.
- `github.com/pydantic/httpx2` project metadata (via PyPI `project_urls`) — confirms authorship
  (Tom Christie) and active maintenance, used only to justify the dependency choice, not for any
  fiscal/ANAF fact.
- Playwright 1.63.0's own shipped TypeScript declarations (`node_modules/playwright`), verified
  by successful `tsc --noEmit` against real option names (`downloadsPath`, `acceptDownloads`) —
  no external Playwright docs page fetched this session.
