# 030 — PWA frontend (Milestone 7, user-facing half)

**Milestone:** M7   **Status:** partial — installable PWA, auth, capture/inbox and Reverb wiring
complete and real; extraction review is UI-only pending two backend endpoints that do not exist yet
**Date:** 2026-09-08

## What was built

The React/Inertia/TypeScript PWA frontend on top of the Laravel 13 scaffold
(`docs/handoff/002-laravel-scaffold.md`), the authentication API
(`docs/handoff/017-authentication.md`) and the document pipeline
(`docs/handoff/022-document-pipeline.md`, `docs/handoff/027-contract-reconciliation.md`). Every
screen is real and wired to the actual `apps/api` HTTP/Reverb surface — nothing is a mockup — except
the one screen (extraction review) whose backend endpoints do not exist yet, which is built as a
complete, honestly-labelled UI shell rather than faked data (see "What is NOT done").

Entry points:

- `GET /login` → `resources/js/pages/auth/login.tsx` — password login, TOTP/recovery-code
  challenge, and passwordless passkey login (`@simplewebauthn/browser` +
  `Kern\Platform\Identity\Support\Webauthn\PasskeyManager`'s endpoints), plus account registration.
- `GET /dashboard` (behind `auth`) → `resources/js/pages/dashboard.tsx` — MFA status, TOTP
  enrollment, passkey registration, recovery-code regeneration, session list/revocation, the
  document-AI-consent toggle, and the "active tax subject" picker (see Key decision #1).
- `GET /documents` (behind `auth`) → `resources/js/pages/documents/inbox.tsx` — the document
  inbox, live-updated over Reverb.
- `GET /documents/capture` (behind `auth`) → `resources/js/pages/documents/capture.tsx` — camera/
  file capture with client-side image compression and an offline upload queue.
- `GET /documents/{document}/review` (behind `auth`) → `resources/js/pages/documents/review.tsx` —
  the extraction-review screen; real document metadata, illustrative-only proposed fields (see Key
  decision #2).
- `public/manifest.webmanifest`, `public/sw.js`, `public/icons/*`, `public/offline.html` — the
  installable-PWA layer.

## Files created / changed

**PWA shell**
- `apps/api/public/manifest.webmanifest` — name, icons (192/512/maskable), `start_url: /dashboard`,
  `display: standalone`, shortcuts to capture/inbox.
- `apps/api/public/sw.js` — hand-written service worker (see Key decision #3): precaches the
  offline page + whatever `public/build/manifest.json` (Vite's own build manifest) lists at install
  time, cache-first for `/build/**` (content-hashed, immutable), network-first-with-offline-fallback
  for navigations, and an explicit never-cache pass-through for `/api/**` and `/broadcasting/**`.
- `apps/api/public/offline.html` — static, session-free fallback page for a failed navigation.
- `apps/api/public/icons/{icon-192,icon-512,icon-maskable-512}.png` — generated placeholder app
  icons (ImageMagick, blue field + white "T"), not any real brand asset.
- `apps/api/resources/views/app.blade.php` — added the manifest link, theme-color/apple PWA meta
  tags, and favicon/apple-touch-icon links. Service worker registration is **not** inline script —
  it's `resources/js/lib/registerServiceWorker.ts`, called once from `app.tsx`.
- `apps/api/resources/js/lib/registerServiceWorker.ts` — registers `/sw.js`; no-ops (never throws)
  where `serviceWorker` isn't supported.
- `apps/api/resources/js/app.tsx` — calls `registerServiceWorker()`.

**HTTP/validation core**
- `apps/api/resources/js/lib/http.ts` — `apiRequest()`: the single fetch wrapper for `/api/v1/**`
  (same-origin credentials, `X-CSRF-TOKEN` on every mutating verb, typed `ApiError` with
  `status`/`errors`/`reason`/`isRateLimited`). Resolves to the raw response text; callers validate
  the shape themselves.
- `apps/api/resources/js/lib/validate.ts` — a small, dependency-free runtime-validation library
  (`vString`/`vNumber`/`vBoolean`/`vEnum`/`vNullable`/`vOptional`/`vArray`/`vObject`/`parseJson`),
  used at every API boundary per `BLUEPRINT.md` §19.5 (see Key decision #4).
- `apps/api/resources/js/lib/csrf.ts` — reads the `<meta name="csrf-token">` Blade already renders.
- `apps/api/resources/js/types/auth.ts`, `types/documents.ts` — typed `parse*()` functions for
  every response shape this app consumes, each built from the actual controller source (see
  "Sources consulted" — every field name was read from the real PHP, not guessed).
- `apps/api/resources/js/api/authApi.ts`, `api/documentsApi.ts` — one typed function per endpoint.

**Realtime**
- `apps/api/resources/js/lib/echo.ts` — Laravel Echo + Reverb (`broadcaster: 'reverb'`), a lazily
  created singleton connection, `subscribeToDocumentsChannel(subjectId, handlers)` (private channel
  `tax-subjects.{subjectId}.documents`, all five lifecycle events, unsubscribe function returned).
- `apps/api/resources/js/hooks/useDocumentsChannel.ts` — the component-lifecycle wrapper.

**Offline capture queue**
- `apps/api/resources/js/lib/db.ts` — hand-written IndexedDB wrapper, two stores: `captureQueue`
  (transient, holds the file `Blob` until upload succeeds) and `knownDocuments` (the exact fields
  `ShowDocumentController` returns — no filename, no OCR text, no PII beyond what the server itself
  already returns).
- `apps/api/resources/js/lib/imageCompression.ts` — canvas-based resize/re-encode (long edge capped
  at 2200px, JPEG quality 0.86); `computeTargetDimensions()` factored out as pure, unit-tested math.
- `apps/api/resources/js/lib/subjectStore.ts` — the "active tax subject id" convenience
  (`localStorage`; see Key decision #1 for why this exists and why it's safe to store).
- `apps/api/resources/js/hooks/useUploadQueue.ts` — enqueue → IndexedDB → upload → promote to
  `knownDocuments` → delete from queue; retries on reconnect; permanent (`RejectionReason`) failures
  are never auto-retried.
- `apps/api/resources/js/hooks/useOnlineStatus.ts` — thin `online`/`offline` event hook.

**Pages/components**
- `resources/js/pages/auth/login.tsx`, `dashboard.tsx`, `documents/{capture,inbox,review}.tsx`,
  `pages/home.tsx` (added a "Login" link; unchanged Inertia component name, so
  `tests/Feature/HomePageTest.php` — outside this task's scope to touch — still passes).
- `resources/js/components/{Nav,StatusBadge,ErrorText}.tsx`.

**Routing**
- `apps/api/routes/web.php` — added `login` (named, for `Authenticate`'s default redirect target),
  `dashboard`, `documents.inbox`, `documents.capture`, `documents.review` (behind Laravel's
  default `auth` middleware alias — no `bootstrap/app.php` change needed, see Key decision #5).

**Build/test tooling**
- `apps/api/package.json` — added `laravel-echo` 2.4.0, `pusher-js` 8.6.0,
  `@simplewebauthn/browser` 14.0.0 (runtime); `vitest` 4.1.11, `jsdom` 27.0.0,
  `fake-indexeddb` 6.2.5, `@testing-library/{react,dom,jest-dom,user-event}`, `@types/node` 26.5.0
  (dev/test). Every version pinned exact, every one checked against CI's pinned Node (`20.19.6`,
  `.github/workflows/ci.yml`) — see Key decision #6 for why `vitest`/`jsdom`/`jest-dom` are *not*
  the newest available majors.
- `apps/api/vite.config.js` — switched `defineConfig` import from `vite` to `vitest/config` (a
  strict superset re-export) and added a `test` block, so Vitest and Vite share one config file.
- `apps/api/resources/js/test/setup.ts` — `@testing-library/jest-dom/vitest` + `fake-indexeddb/auto`.
- Seven `*.test.{ts,tsx}` files (45 tests total) — see "How to verify".

## Key decisions

1. **There is no HTTP endpoint to list, create, or fetch a user's `TaxSubject`(s).** Read
   `routes/api.php` in full: only `POST/GET /v1/subjects/{subject}/documents*` and
   `POST/DELETE /v1/subjects/{subject}/document-ai-consent` exist, all requiring a `{subject}` id
   the caller already knows. `App\Domain\Subjects\Actions\RegisterPfSubject` exists as a domain
   action but no controller calls it. Building that endpoint is `app/Http/**`/`routes/api.php` —
   outside this task's file scope, and touching PHP under `app/Domain/**` was explicitly
   prohibited. **Workaround, not a fix**: `resources/js/lib/subjectStore.ts` lets a user who
   already knows their `tax_subject_id` (obtained out of band) enter it once; it's remembered in
   `localStorage` as an opaque ULID pointer, the same category of "which workspace" convenience any
   multi-tenant SPA keeps client-side — not one of `BLUEPRINT.md` §4.3's mandatory-encryption fields
   (CNP, IBAN, address, …), so this does not violate §1.3/§17's "no sensitive fiscal data in
   LocalStorage." The dashboard's "Subiect fiscal activ" panel says exactly this limitation aloud
   to the user rather than pretending it's a real subject picker.

2. **The extraction-review screen shows real document metadata but illustrative-only proposed
   fields, with Accept/Correct visibly disabled.** `App\Http\Controllers\Api\V1\Documents\
   ShowDocumentController`'s own docblock says extraction content "never crosses this boundary; a
   future review endpoint is explicitly out of this task's scope" — confirmed by reading the
   controller and `routes/api.php`: there is no `GET`-extraction-content, no accept, and no correct
   endpoint anywhere. `docs/AGENTS.md` §2 forbids inventing an API or writing "a plausible-looking
   placeholder that reads as real." So `documents/review.tsx` fetches and shows the real
   `DocumentSummary`, listens for the real `DocumentExtractionProposed` Reverb event (so it can
   truthfully say "a notification arrived"), and renders a fixed, clearly labelled
   ("Exemplu ilustrativ") three-field example with disabled Accept/Correct buttons carrying a
   tooltip naming exactly why they're disabled. Also: **there is no document-download/preview
   endpoint either** (`docs/handoff/022`'s own "What is NOT done" says so explicitly), so the
   "Sursă" (source) panel says so instead of showing a blank box.

3. **`public/sw.js` is hand-written, not `vite-plugin-pwa`.** Evaluated `vite-plugin-pwa@1.3.0`
   (MIT, supports Vite `^8.0.0`) first. Chose to hand-write instead, for the same reason
   `docs/handoff/022-document-pipeline.md` decision #3 hand-wrote a ClamAV client rather than adding
   a dependency: `docs/DEPENDENCY-POLICY.md` §4 orders "standard library" above "a well-maintained
   third-party package," and this app's caching needs (precache the offline page + Vite's own
   build output, cache-first for hashed assets, never cache `/api/**`) are fully expressible with
   the plain Cache/Fetch APIs. The precache list is **not** a hand-maintained filename guess — the
   service worker fetches `public/build/manifest.json` (Vite's real build manifest) at install time
   and precaches exactly what's actually there, so it can never silently go stale after a rebuild.
4. **Runtime validation is a ~100-line hand-written combinator library, not `zod`.** Same reasoning
   as #3: the ~15 response shapes this app validates are all flat-ish objects/enums/arrays; a
   general-purpose schema library is not "the standard library isn't enough," per
   `docs/DEPENDENCY-POLICY.md` §4's ordering. `resources/js/lib/validate.ts` is fully unit-tested
   (`validate.test.ts`).
5. **No `bootstrap/app.php` change was needed for the new page routes.** `Illuminate\Foundation\
   Configuration\Middleware` registers the `auth`/`guest` aliases by default; `$middleware->alias([...])`
   in `bootstrap/app.php` only *adds* to that set (confirmed by reading the framework's own
   `Middleware` class, not assumed) — it does not replace it. `routes/web.php` only needed to name
   a `login` route so `Authenticate::redirectTo()` has somewhere to send an unauthenticated visit.
6. **Test tooling is pinned one minor/major behind the newest release, deliberately.**
   `.github/workflows/ci.yml` pins Node `20.19.6` for both the lint and build jobs. `vitest@5.0.0`/
   `jsdom@30.0.1`/`@testing-library/jest-dom@7.0.1` (the newest available at the time of writing)
   all declare `engines.node >= 22`, which would break silently in CI. Checked each package's
   `engines` field directly against the npm registry (not assumed) and picked the newest version
   still declaring Node 20 support: `vitest@4.1.11`, `jsdom@27.0.0`, `@testing-library/jest-dom@6.9.1`
   (all released the same week as their newer counterparts, not stale). `docs/DEPENDENCY-POLICY.md`
   §2's "current" requirement is read here as "current within the runtime constraint this repo
   already committed to," not "absolute latest regardless of compatibility."
7. **Auth-boundary transitions (login success, MFA success, passkey success, logout) use
   `window.location.assign(...)`, a full browser navigation, never Inertia's client-side router.**
   `resources/js/lib/csrf.ts`'s docblock explains why: the CSRF token embedded in
   `app.blade.php`'s `<meta>` tag is only refreshed by a full document load, and logout is the one
   endpoint that actually rotates it (`LogoutController`'s `session()->regenerateToken()`). Every
   other in-app navigation (dashboard ↔ inbox ↔ capture ↔ review) uses Inertia's `<Link>`/router
   normally.
8. **The offline capture queue is whole-file retry, not byte-range resumable upload.**
   `App\Http\Controllers\Api\V1\Documents\UploadDocumentController` is a single multipart `POST`
   with no `Content-Range`/chunk-id support (confirmed by reading it — no such handling exists).
   "Resume upload" (`BLUEPRINT.md` §1.4) is implemented as: the file is durably queued in IndexedDB
   before any network call, survives an app close/crash/lost connection, and is retried
   automatically on reconnect — but an interruption mid-upload re-sends the whole file, not a byte
   range. A real resumable-upload protocol (tus, S3 multipart, or a bespoke chunked endpoint) would
   need a server-side change outside this task's scope.
9. **A permanently-rejected capture (`RejectionReason`) is a distinct queue state (`'rejected'`),
   never silently retried.** The same file would fail the same `FileSecurityValidator` check every
   time; auto-retrying it forever would waste requests and hide the real problem from the user.
   Transient failures (`'failed'` — network/server errors) are retried automatically on reconnect.

## How to verify

All commands run for real, from `apps/api/`, output pasted verbatim.

```
$ rm -rf node_modules package-lock.json && npm install
added 277 packages, and audited 278 packages in 10s
found 0 vulnerabilities

$ rm -rf node_modules && npm ci
added 281 packages, and audited 282 packages in 3s
found 0 vulnerabilities

$ npx tsc --noEmit
(no output — no errors)

$ npx eslint resources/js
(no output — no errors)

$ npx prettier --check resources/js
Checking formatting...
All matched files use Prettier code style!

$ npx vitest run
 Test Files  7 passed (7)
      Tests  45 passed (45)
   Duration  1.11s

$ npm run build
✓ 612 modules transformed.
public/build/manifest.json              0.38 kB │ gzip:   0.18 kB
public/build/assets/app-Y5q6Mz-J.css   41.54 kB │ gzip:   7.96 kB
public/build/assets/app-CKDygrFL.js   435.96 kB │ gzip: 133.38 kB
✓ built in 407ms

$ npm audit --audit-level=high
found 0 vulnerabilities
```

**PWA static-asset delivery** (`php -S 127.0.0.1:8099 -t public`, static files only — see the
honesty note below for what this does *not* prove):
```
GET /manifest.webmanifest → 200, Content-Type: application/manifest+json
  name: TaxOS România
  icons: [('/icons/icon-192.png','192x192','any'), ('/icons/icon-512.png','512x512','any'),
          ('/icons/icon-maskable-512.png','512x512','maskable')]
  start_url: /dashboard   display: standalone
GET /sw.js → 200, Content-Type: application/javascript
GET /icons/icon-192.png → 200, Content-Type: image/png, verified via `file`: PNG 192x192
GET /build/manifest.json → 200, real Vite manifest (confirms sw.js's install-time precache
  logic reads the real file/css keys this build actually produced, not a guess)
```
`node --check public/sw.js` → no syntax errors. `python3 -m json.tool` on
`manifest.webmanifest` → valid JSON with every field the Web App Manifest spec requires for
installability (`name`, `icons` incl. a 512×512 `any` and a `maskable`, `start_url`, `display:
standalone`).

**Honesty note — what was *not* run, and why**: a real Lighthouse/Chrome PWA-installability audit
and an end-to-end login→dashboard→capture→inbox round trip against the live Laravel app were **not
performed**. This sandbox has no Chromium/Lighthouse binary, and bringing up the full
`docker compose` stack (the `app`/`reverb`/`queue` containers, migrations, a seeded user) shares the
same Postgres instance other agents may be using concurrently — `docs/AGENTS.md` §7b explicitly
warns against `migrate:fresh` against a shared database. The static-serving check above confirms
every file the browser needs for installability is syntactically valid and served with the correct
MIME type; it does not confirm Chrome's own installability heuristics pass, or that the Echo/Reverb
handshake succeeds against a live `reverb` container, or that the WebAuthn JSON round-trips
correctly against `web-auth/webauthn-lib`'s real serializer output. These are real, unverified
integration risks — see "Open questions."

## Interfaces exposed to other modules

Nothing under `app/Domain/**` or `app/Http/**` was touched or is exposed by this task — this is a
pure consumer of the existing HTTP/Reverb contracts documented in handoffs 017/022/027. The one
thing a future backend task should treat as a contract this frontend now depends on:

- `resources/js/lib/subjectStore.ts`'s `localStorage` key `kern.activeSubjectId` — whoever builds
  the real "list/register tax subjects" endpoint should have the dashboard call it and drop this
  workaround, not add a second mechanism next to it.
- `resources/js/types/documents.ts`'s `DocumentLifecycleEvent`/`parseDocumentLifecycleEvent` is the
  frontend's one parser for all five Reverb broadcast shapes — extend it, don't duplicate it, if a
  sixth event is ever added to `App\Domain\Documents\Events\*`.

## What is NOT done

- **No subject registration/listing screen** — blocked on a backend endpoint that doesn't exist
  (Key decision #1). The PF-registration flow (`App\Domain\Subjects\Actions\RegisterPfSubject`,
  including CNP entry) has no frontend at all.
- **No working extraction-review submission** — blocked on two backend endpoints that don't exist
  (Key decision #2): reading `document_extractions`' proposed fields, and
  `DocumentExtraction::review()`'s accept/correct transition.
- **No document preview/download** — blocked on a backend endpoint that doesn't exist
  (`docs/handoff/022`'s own "What is NOT done").
- **No true byte-range/chunked resumable upload** — Key decision #8; the queue is durable and
  auto-retries, but re-sends the whole file after an interruption.
- **The document inbox only shows documents this specific device uploaded or was told about via
  Reverb while online** — there being no list-documents endpoint, `knownDocuments` (IndexedDB) is
  the only source of the list; a document uploaded from a second device is invisible here until a
  list endpoint exists. The inbox screen states this limitation to the user directly.
- **No Lighthouse/real-browser installability audit, no live Reverb/WebAuthn integration test** —
  see the "Honesty note" above.
- **No password-reset flow** — matches `docs/handoff/017`'s own "What is NOT done" (the backend
  doesn't have one either).
- **No accountant/delegate-access UI** — not asked for; `BLUEPRINT.md` §33 lists it as a future
  design constraint, not an MVP requirement.
- Did not touch `app/Domain/**`, `app/Http/**`, `routes/api.php`, `routes/channels.php`,
  `bootstrap/**`, `docs/STATUS.md`, or any other module's files — all explicitly out of this task's
  scope.

## Open questions

1. **Is the WebAuthn JSON contract between `@simplewebauthn/browser` and `web-auth/webauthn-lib`'s
   Symfony serializer actually compatible byte-for-byte?** Both follow the WebAuthn spec's JSON
   serialization convention (base64url ArrayBuffers), and `PasskeyManager`'s options output was read
   directly to confirm field names, but no live round trip was run (no running backend — see
   "Honesty note"). Whoever first exercises passkey login/registration against a real server should
   treat this as the first thing to verify, not assume proven.
2. **Does the Reverb `authEndpoint: '/broadcasting/auth'` route actually exist and behave as
   assumed?** `docs/handoff/022-document-pipeline.md` decision #4 confirms `withRouting(channels:
   ...)` registers `Broadcast::routes()` (which defaults to that path under `auth:web` semantics
   matching the surrounding `web` group), but this was not exercised against a live Reverb server
   in this task.
3. **Should the "active subject" mechanism become a real subject-switcher once the list/register
   endpoints exist?** Left as the obvious next step, not built speculatively now
   (`docs/CODE-QUALITY.md` §2 — no scaffolding for an endpoint that doesn't exist yet).
4. Is 2200px/0.86 JPEG quality (`resources/js/lib/imageCompression.ts`) actually the right
   trade-off for the real OCR/native-text pipeline (`docs/handoff/019-pdf-text-extraction.md`)? No
   golden-image OCR-accuracy test was run against the Python service — chosen as a reasonable,
   documented default, not empirically tuned.

## Sources consulted

- `BLUEPRINT.md` §1.3, §1.4, §16, §17, §19.5 — the literal brief for this task.
- `docs/AGENTS.md` in full — especially §2 (never fabricate — Key decision #2), §6 (module
  boundaries), rule 15 (no polling where Reverb exists — `useDocumentsChannel`/`useUploadQueue`
  never poll; the two "catch-up" effects in `inbox.tsx` run exactly once per mount, not on an
  interval).
- `docs/CODE-QUALITY.md`, `docs/DEPENDENCY-POLICY.md` — read in full before adding any dependency;
  every addition justified above and pinned exact per §1.
- `docs/handoff/002-laravel-scaffold.md` — the existing Inertia/React/TS/Tailwind scaffold and its
  verification commands, followed exactly.
- `docs/handoff/017-authentication.md` — read in full; every `authApi.ts` function's request/
  response shape was cross-checked against the real controllers in
  `apps/api/packages/platform/src/Http/Controllers/Auth/*.php`, not the handoff prose alone.
- `docs/handoff/022-document-pipeline.md`, `docs/handoff/027-contract-reconciliation.md` — read in
  full; `documentsApi.ts`/`types/documents.ts` match `UploadDocumentController`/
  `ShowDocumentController`/`DocumentAiConsentController` and the five `App\Domain\Documents\
  Events\*` broadcast payloads exactly, read from the real PHP source, not the handoff summaries.
- Source files read directly (not assumed from any handoff): `apps/api/routes/api.php`,
  `routes/channels.php`, `bootstrap/app.php`, every controller under
  `app/Http/Controllers/Api/V1/**`, `packages/platform/src/Identity/Support/Webauthn/PasskeyManager.php` and
  `WebauthnCredentialRepository.php` (the WebAuthn JSON contract), `app/Domain/Documents/Events/*`.
- npm registry queried live (`npm view <pkg> version/engines/license/time.modified`) for every
  dependency added — `laravel-echo`, `pusher-js`, `@simplewebauthn/browser`, `vitest`, `jsdom`,
  `fake-indexeddb`, `@testing-library/*`, `@types/node`, and the rejected `vite-plugin-pwa`
  candidate — not from training-data memory.
- `.github/workflows/ci.yml` — read to confirm the pinned Node version (`20.19.6`) that Key
  decision #6's test-tooling versions had to stay compatible with.
