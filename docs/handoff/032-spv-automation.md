# 032 — SPV automation (credential flow, session lifecycle, two-transport inbox sync)

**Milestone:** M10   **Status:** complete (machinery only — no ANAF portal navigation, by design)
**Date:** 2026-09-08

## What was built

`Kern\TaxRomania\Spv` (`BLUEPRINT.md` §13, §6.11) plus an extension of `apps/spv-browser`'s session
lifecycle (`docs/handoff/004-python-browser-services.md`). This is the highest-risk module in the
product — it handles a taxpayer's ANAF credentials — and per `docs/reference/anaf-spv-integration.md`
(verified 2026-09-08) **no ANAF SPV portal DOM, selector, field name, or error code has ever been
observed by this project**, and the PF username/password/OTP flow has **no documented API at
all**. Both documented ANAF auth routes require a qualified digital certificate. So this task
builds the machinery `BLUEPRINT.md` §13.2 describes — the credential channel, the MFA gate, the
session state machine, the OTP TTL, audit events for every step, cross-subject isolation — and
leaves the one thing that requires observed portal behaviour (actually driving the browser through
a login form) behind an explicit, pluggable, unimplemented seam. Nothing ANAF-specific was
invented anywhere: no URL, DOM selector, field name, or error code beyond the two ANAF actually
documents (`listaMesaje`, `descarcare`).

### The credential flow (`BLUEPRINT.md` §13.2), end to end

1. `RegisterSpvConnection` — "Connect SPV." Calls `Kern\Platform\Identity\Support\SpvMfaPolicy::
   ensureStrongAuthentication()` first, unconditionally; throws `MfaNotEnrolledException` for a
   user with no confirmed TOTP method and no passkey. Creates `spv_connections` — never a
   `password_ciphertext` column, `SpvCredentialPolicy::EPHEMERAL_ONLY` records that structurally.
2. `InitiateSpvAuthAttempt` — re-checks MFA (it could have been un-enrolled since registration),
   calls `apps/spv-browser`'s `POST /v1/spv/sessions` to open an ephemeral worker session, records
   the local `spv_sessions` row (`state = CREATED`).
3. `SubmitSpvCredentials` — the password crosses into the worker here, over one synchronous
   internal HTTP call (`SensitiveString::reveal()` is called exactly once, at the point the HTTP
   request body is built) — never queued, never logged, never written to any column. Worker
   response drives `Kern\TaxRomania\Spv\Services\ApplyPortalAuthResult`, which sets `spv_sessions.state`
   and — on `AWAITING_USER_CHALLENGE` — broadcasts `SpvAuthChallengeRequired` (session id and
   subject id only).
4. `SubmitSpvChallengeCode` — enforces `config('spv.challenge_ttl_seconds')` **itself, before ever
   calling the worker**: an expired code is never forwarded, the session is failed and torn down,
   and `SpvChallengeExpiredException` is thrown. On success, `ApplyPortalAuthResult` moves the
   connection to `ACTIVE` and broadcasts `SpvAuthenticated`.
5. `DestroySpvSession` — idempotent teardown (`BLUEPRINT.md` §13.2 step 11), calls the worker's
   `DELETE .../sessions/{id}`.
6. `RevokeSpvConnection` — the natural counterpart to connecting: disconnects SPV.

Every one of the six actions above records exactly one audit event
(`Kern\TaxRomania\Audit\Services\AuditChainService::record()`), payload-hashed, never carrying a
credential or code.

### Two-transport inbox sync (`docs/reference/anaf-spv-integration.md`'s "real optimisation")

`Kern\TaxRomania\Spv\Contracts\SpvInboxSyncTransport` has two real implementations, picked by
`SpvConnection::$auth_method` in `SyncSpvInbox` — never conflated:

- `AnafCertificateApiTransport` — ANAF's **documented** `listaMesaje`/`descarcare` web service,
  authenticated by qualified digital certificate (`Http::withOptions(['cert' => [...]])`). Real,
  implementable code today; **untestable against the live endpoint** — no certificate is available
  in this environment. Fails closed (`SpvCertificateNotConfiguredException`) if no certificate path
  is configured; never attempts an unauthenticated call. Treats ANAF's `{"eroare": ...}` shape as
  an error regardless of HTTP status (the reference doc's own explicit warning).
- `BrowserPortalInboxSyncTransport` — opens a worker session, calls `.../actions/sync`, which is
  and remains `501 not_implemented` (no portal navigation exists). Always throws
  `SpvSyncUnavailableException` today; the opened worker session is still torn down in a `finally`
  block, so a failed sync attempt never leaks a browser resource.

`SyncSpvInbox` records an `spv_sync_runs` row (`RUNNING -> SUCCEEDED|FAILED`), broadcasts
`SpvSyncStarted`/`SpvSyncCompleted` (counts only, never a message title), and upserts
`spv_messages` idempotently on `(tax_subject_id, external_id_blind_index)`.

### `apps/spv-browser` extension

The session lifecycle from M0 (`docs/handoff/004-python-browser-services.md`) is extended, not
replaced:

- `src/portal/portalAuthenticator.ts` — the pluggable seam. `PortalAuthenticator` interface
  (`submitCredentials`/`submitChallengeCode`); the one shipped implementation,
  `UnimplementedPortalAuthenticator`, throws immediately for both methods — credentials/codes are
  never even assigned to a variable that outlives the call. A future milestone with observed
  portal behaviour swaps in a real implementation at exactly one call site (`server.ts`'s
  `createApp(...)` call) — nothing else changes.
- `src/sessions/types.ts`/`sessionManager.ts` — `SpvSessionStatus` extended to
  `created | awaiting_challenge | authenticated | failed | destroyed`;
  `SessionManager.updateStatus()` added.
- `src/routes/sessions.ts` — `/credentials` and `/challenge` now real, generic state-machine
  handlers: validate ordering (`credentials` requires `created`, `challenge` requires
  `awaiting_challenge`, else `409 invalid_state`), validate the body shape (`400 bad_request`),
  call the authenticator, map its outcome to the persisted status and the response, and still
  answer `501 not_implemented` for the shipped adapter (identical wire shape to before).
  `/actions/sync` and `/actions/submit-d212` are untouched — still `501`, no portal navigation.
- `src/middleware/internalAuth.ts` — a shared-secret bearer token (`SPV_BROWSER_AUTH_TOKEN`)
  guarding every `/v1/spv/*` route (never `/health`), fails closed if unconfigured. This is the
  identical compromise `apps/processing/src/processing/internal_auth.py` already documents for
  itself: `BLUEPRINT.md` §13.4's mTLS/signed-JWT scheme has never been decided anywhere in this
  codebase, and standing one up unilaterally here would be inventing infrastructure this task has
  no authority over.

## Files created / changed

**Migrations** (`apps/api/database/migrations/2026_09_09_100000`–`100003`): `spv_connections`,
`spv_sessions`, `spv_sync_runs`, `spv_messages` — see each file's own docblock for the two
justified additions beyond `BLUEPRINT.md` §6.11's literal column list (`spv_messages.
external_id_ciphertext`, needed to ever call `descarcare` again; `spv_sessions.
challenge_requested_at`, needed to measure the OTP TTL).

**`Kern\TaxRomania\Spv`** (`apps/api/packages/tax-romania/src/Spv/`):
- `Enums/{SpvAuthMethod,SpvCredentialPolicy,SpvConnectionStatus,SpvSessionState,SpvSyncRunStatus}.php`.
- `ValueObjects/SensitiveString.php` — wraps a password/OTP code; `reveal()` is the only way out;
  `__toString()`/`__debugInfo()` both redact (proven against real `var_dump`/`print_r` output, not
  assumed — see "Key decisions" #1).
- `Models/{SpvConnection,SpvSession,SpvSyncRun,SpvMessage}.php`.
- `DataTransferObjects/{SpvBrowserSession,PortalAuthResult,SpvInboxMessage,
  RegisterSpvConnectionInput,SubmitSpvCredentialsInput,SubmitSpvChallengeCodeInput}.php`.
- `Contracts/{SpvBrowserSessionClient,SpvInboxSyncTransport}.php`.
- `Transport/{HttpSpvBrowserSessionClient,AnafCertificateApiTransport,
  BrowserPortalInboxSyncTransport}.php`.
- `Services/ApplyPortalAuthResult.php` — the one place a `PortalAuthResult` becomes
  `spv_sessions`/`spv_connections` writes plus the matching broadcast.
- `Actions/{RegisterSpvConnection,RevokeSpvConnection,InitiateSpvAuthAttempt,
  SubmitSpvCredentials,SubmitSpvChallengeCode,DestroySpvSession,SyncSpvInbox}.php`.
- `Events/{SpvAuthChallengeRequired,SpvAuthenticated,SpvSyncStarted,SpvSyncCompleted}.php` +
  `Events/Concerns/BroadcastsOnSpvChannel.php`.
- `Exceptions/{InvalidSpvSessionTransitionException,SpvChallengeExpiredException,
  SpvWorkerUnavailableException,SpvPortalAutomationUnavailableException,
  SpvCertificateNotConfiguredException,SpvSyncUnavailableException,
  SpvAuthMethodMismatchException}.php`.
- `SpvServiceProvider.php` — binds `SpvBrowserSessionClient -> HttpSpvBrowserSessionClient`,
  `AnafCertificateApiTransport` (config-driven constructor args).

**`apps/spv-browser/`**:
- `src/portal/portalAuthenticator.ts` (new) — `PortalAuthenticator`, `PortalAuthOutcome`,
  `PortalAutomationNotImplementedError`, `UnimplementedPortalAuthenticator`.
- `src/middleware/internalAuth.ts` (new) — `createInternalAuthMiddleware()`.
- `src/sessions/types.ts`, `src/sessions/sessionManager.ts` — extended status union, added
  `updateStatus()`.
- `src/routes/sessions.ts`, `src/app.ts`, `src/server.ts` — wired as described above.
- `eslint.config.js` — added `dist-test/**` to `globalIgnores` (a real, pre-existing gap: `dist-test/`
  is only produced by `npm test`'s `pretest` build step, so `eslint .` run *before* the first test
  run never saw it; this task's own verification run *did*, and would fail lint on compiled JS
  artifacts every time a future agent lints after testing without a clean checkout in between).
- `tests/fakePortalAuthenticator.ts` (new) — deterministic fake proving the real state machine.
- `tests/app.test.ts` — extended: internal-auth-token enforcement (missing header, wrong token,
  unconfigured server), the full credential+challenge state machine against the fake adapter,
  invalid-ordering rejections, malformed-body rejection. Removed `challenge` from the
  "still-501-against-Unimplemented" table — see "Key decisions" #2, a real bug this task's own
  verification caught.
- `tests/noSensitiveLogging.test.ts` (new) — spies on the real `console.log`/`console.error` sinks
  across the full flow including a malformed-JSON body on `/credentials`; asserts a password/code
  marker never appears in any captured line.

**Modified outside the strict path list, small and additive** (same justification pattern every
prior handoff in this codebase uses):
- `apps/api/config/spv.php` (new) — worker base URL/timeout/auth token, the OTP TTL (engineering
  default, flagged below), ANAF web-service base URL/timeout/certificate path/lookback window.
- `apps/api/deptrac.php` — added the `Spv` layer, `accesses($support, $subjects, $identity, $audit)`.
- `apps/api/tests/Pest.php` — added `Feature/Domain/Spv` to the `RefreshDatabase` list.
- `apps/api/bootstrap/providers.php` — registered `SpvServiceProvider`.
- `apps/api/routes/channels.php` — added `tax-subjects.{subjectId}.spv`, the identical
  authorization rule the `.documents` channel already uses.

**Tests** (`apps/api/tests/`) — 9 files, 29 tests:
- `Feature/Domain/Spv/{CrossTenantIsolationTest,RawDatabasePlaintextTest,SpvMfaGateTest,
  CredentialFlowTest,InboxSyncTest,NoSensitiveLoggingTest,RevokeSpvConnectionTest}.php`.
- `Unit/Domain/Spv/{SensitiveStringTest,BroadcastPayloadContainsNoPiiTest}.php`.

`apps/spv-browser/tests/` — 29 tests total (up from 22): `app.test.ts`, `sessionManager.test.ts`,
`logger.test.ts`, `noSensitiveLogging.test.ts` (new), plus the two new fakes.

No new Composer or npm dependencies (`composer audit`/`npm audit` both clean) — `SensitiveString`,
`internalAuth.ts`'s `timingSafeEqual`, and everything else use only what was already present or
the standard library.

## Key decisions

1. **`SensitiveString::__debugInfo()` redaction was verified against real `var_dump()`/`print_r()`
   output, not assumed.** PHP's `__debugInfo()` magic method is documented as a `var_dump()` hook;
   whether it also governs `print_r()` (which normally shows private properties verbatim, marked
   `[value:ClassName:private]`) was not something to guess given how much this class's entire job
   is preventing exactly that leak. Ran a 5-line PHP 8.3 script directly: both `print_r()` and
   `var_dump()` honour `__debugInfo()` identically. `tests/Unit/TaxRomania/Spv/SensitiveStringTest.php`
   encodes this as a real regression test.

2. **`apps/spv-browser`'s own test suite caught a real design bug during this task's own
   verification, not by inspection**: `tests/app.test.ts`'s pre-existing table of "every portal
   action still returns 501 against `UnimplementedPortalAuthenticator`" included `challenge`
   against a freshly-created session. Once `/challenge` correctly requires `awaiting_challenge`
   (checked *before* the authenticator is ever consulted — "never forward a code except when a
   challenge was actually issued" is itself part of the machinery this task is meant to build), a
   session that never received credentials can never reach that state via the shipped adapter (its
   `/credentials` call always throws first) — so `/challenge` on a fresh session now correctly
   answers `409 invalid_state`, never `501`. Removed `challenge` from that table; the
   409-on-premature-challenge behaviour is covered by its own dedicated test.

3. **Internal auth between Laravel and `apps/spv-browser` is a shared-secret bearer token, not
   `BLUEPRINT.md` §13.4's "mTLS preferred, plus a short-TTL signed JWT."** This mirrors
   `apps/processing`'s own `internal_auth.py`, which documents the identical reasoning: nobody has
   decided a mTLS/JWT scheme anywhere in this codebase (no certificate authority, no token issuer,
   no key rotation policy this task has authority over), and standing one up unilaterally here
   would itself be inventing unverified infrastructure (`docs/AGENTS.md` rule 1's spirit, applied
   to infra rather than an ANAF fact). A constant-time-compared shared secret, failing closed when
   unconfigured, is the minimum control satisfying "must not be callable unauthenticated" without
   guessing at a scheme. Whoever designs the final internal-auth scheme for every Laravel↔worker
   call should replace this uniformly across `apps/processing` and `apps/spv-browser`, not extend
   it per-service.

4. **The password/OTP code is revealed exactly once, synchronously, inside the single HTTP request
   that needs it — never queued.** `docs/AGENTS.md` rule 5 forbids a credential in a queue payload;
   `SubmitSpvCredentials`/`SubmitSpvChallengeCode` are plain, synchronous action classes (no
   `ShouldQueue`), matching `BLUEPRINT.md` §13.2 step 3's "credentials are transmitted through an
   internal encrypted/short-lived channel" literally — the channel is the HTTP call itself, made
   once, whose body is never persisted anywhere on the Laravel side.

5. **The OTP/challenge-code TTL (`spv.challenge_ttl_seconds`, default 300s) is an engineering
   default, not a blueprint value** — the identical situation `docs/handoff/004`'s Open question
   #2 records for the browser session TTL itself (`BLUEPRINT.md` §3.4 requires a TTL, names no
   number). Enforced **before** the worker is ever called (defence in depth on top of whatever a
   future real portal adapter enforces on its own side): an expired code is never forwarded, full
   stop.

6. **`SyncSpvInbox` injects both concrete transports directly, never `SpvInboxSyncTransport`
   through the container.** There is no single "the" implementation to bind — the choice between
   `AnafCertificateApiTransport` and `BrowserPortalInboxSyncTransport` is a runtime decision this
   class itself makes from `SpvConnection::$auth_method`, not a compile-time container binding.
   The interface exists so a caller depending on "give me messages for this connection" (a future
   HTTP layer) can be written against one seam without knowing there are two transports.

7. **`spv_messages.external_id_ciphertext` is a justified addition beyond `BLUEPRINT.md` §6.11's
   literal column list** (which names only the blind index). Without storing the real external
   message id (encrypted), this codebase could never call ANAF's documented `descarcare?id=<id>`
   for that message again — the blind index is one-way by design (§4.4). Mirrors the identical
   `external_id_ciphertext`/`external_id_blind_index` pair `financial_transactions` already
   establishes for the same "encrypted but still exact-lookup/reference-capable" shape.

8. **No HTTP/API controller layer.** This task's path scope (`apps/api/packages/tax-romania/src/Spv/**`) and
   `docs/AGENTS.md` §10 ("do not edit files another agent is actively working on") both point the
   same way: `app/Http/**` is another agent's concurrent work per this task's own brief. Every
   action here is a plain, container-resolvable class — the same boundary `docs/handoff/
   028-d212-declarations.md` drew for `Domain/Declarations`. A future HTTP layer calls
   `app(RegisterSpvConnection::class)($request->user(), $input)` etc. directly.

## How to verify

Per `docs/AGENTS.md` §7b, created a dedicated database (`005 crypto core`/`006 audit chain`/
`007 PDF licence swap` were listed active in `docs/STATUS.md` → *Files currently being worked on*
at the time; none overlap this task's paths, but a fresh database avoids any shared-write risk
regardless) and ran everything against the project's own `anaf-app`/`anaf-postgres-1` containers on
`anaf_data_net`, `--user root` for the same bind-mount ownership reason prior handoffs document.
Dropped the database after verification completed.

```text
$ docker exec anaf-postgres-1 psql -U kern -d postgres -c 'CREATE DATABASE kern_spv;'
CREATE DATABASE

$ docker run --rm -v "$PWD/apps/api":/app -w /app --user root --entrypoint sh anaf-app -c \
    'vendor/bin/pint --test'
  PASS .......................................................... 771 files

$ docker run --rm -v "$PWD/apps/api":/app -w /app --user root --entrypoint sh anaf-app -c \
    'php -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G'
 [OK] No errors                                              # level 9, no baseline, whole project

$ docker run --rm -v "$PWD/apps/api":/app -w /app --user root --entrypoint sh anaf-app -c \
    'vendor/bin/deptrac analyse --config-file=deptrac.php'
  Violations 0, Skipped violations 0, Uncovered 631, Allowed 317, Warnings 0, Errors 0

$ docker run --rm --network anaf_data_net -v "$PWD/apps/api":/app -w /app --user root \
    -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_DATABASE=kern_spv \
    -e DB_USERNAME=kern -e DB_PASSWORD=<POSTGRES_PASSWORD> -e APP_KEY=base64:<random> \
    --entrypoint sh anaf-app -c 'php artisan migrate --force'
  ... 2026_09_09_100000_create_spv_connections_table .............. DONE
  ... 2026_09_09_100001_create_spv_sessions_table ................... DONE
  ... 2026_09_09_100002_create_spv_sync_runs_table ................... DONE
  ... 2026_09_09_100003_create_spv_messages_table ..................... DONE

$ ... -c 'php artisan migrate:rollback --step=4 --force && php artisan migrate --force'
  # all four down() then up() again, clean both directions

$ ... -c 'vendor/bin/pest tests/Feature/TaxRomania/Spv tests/Unit/TaxRomania/Spv'   # run 1
  Tests:    29 passed (150 assertions)
$ ... # run 2 — identical
  Tests:    29 passed (150 assertions)

$ ... -c 'vendor/bin/pest'   # whole project, run 1
  Tests:    3 warnings, 10 skipped, 728 passed (2150 assertions)
$ ... # run 2 — identical
  Tests:    3 warnings, 10 skipped, 728 passed (2150 assertions)

$ composer validate --strict && docker run --rm -v "$PWD":/app -w /app composer:2 composer audit
./composer.json is valid
No security vulnerability advisories found.

$ docker exec anaf-postgres-1 psql -U kern -d postgres -c 'DROP DATABASE kern_spv;'
DROP DATABASE
```

`apps/spv-browser` (host Node 20.19.6, matching the pinned `engines`):

```text
$ npm ci
added 175 packages, and audited 176 packages in 1s
found 0 vulnerabilities

$ npx eslint .
(no output — clean)

$ npx tsc --noEmit
(no output — clean)

$ npm run build
> tsc -p tsconfig.build.json

$ npm test   # run 1
1..29
# pass 29
# fail 0

$ npm test   # run 2 — identical
1..29
# pass 29
# fail 0

$ npm audit --audit-level=high
found 0 vulnerabilities
```

A real bug was found and fixed *during* this verification (§"Key decisions" #2) — invisible until
the state-ordering machinery was actually exercised end to end, exactly the class of defect
`docs/AGENTS.md` §7b warns real execution (not inspection) catches.

## Interfaces exposed to other modules

```php
use Kern\TaxRomania\Spv\Actions\{
    RegisterSpvConnection, RevokeSpvConnection, InitiateSpvAuthAttempt,
    SubmitSpvCredentials, SubmitSpvChallengeCode, DestroySpvSession, SyncSpvInbox,
};
use Kern\TaxRomania\Spv\Enums\{SpvAuthMethod, SpvConnectionStatus, SpvSessionState, SpvSyncRunStatus};
use Kern\TaxRomania\Spv\ValueObjects\SensitiveString; // wrap a raw password/OTP before it ever
                                                  // reaches this module
```

- **`Kern\Platform\Identity\Support\SpvMfaPolicy` is this module's one upstream gate** — every
  connection-establishing action calls `ensureStrongAuthentication()` before touching the database.
- **A future HTTP/API layer** calls these actions directly (`app(RegisterSpvConnection::class)
  ($request->user(), $input)`) — no controller exists yet, matching Declarations' precedent.
- **A future D212-submission transport** (`Kern\TaxRomania\Declarations\Enums\SubmissionTransport::
  SPV_BROWSER`/`ANAF_API`, `docs/handoff/028-d212-declarations.md`) plugs in once
  `.../actions/submit-d212` has a real portal adapter — untouched by this task, by design (out of
  scope: "No D212 submission flow beyond the interface").
- **Broadcast channel** `tax-subjects.{subjectId}.spv` — `SpvAuthChallengeRequired`,
  `SpvAuthenticated`, `SpvSyncStarted`, `SpvSyncCompleted`. Status/progress/counts only, proven by
  `tests/Unit/TaxRomania/Spv/BroadcastPayloadContainsNoPiiTest.php`.
- **`Kern\TaxRomania\Spv\Contracts\{SpvBrowserSessionClient,SpvInboxSyncTransport}`** — the seams a
  future milestone with observed ANAF portal behaviour extends. Swapping in a real adapter means:
  (a) implement `apps/spv-browser`'s `PortalAuthenticator` with real Playwright navigation and pass
  it to `createApp()` in `server.ts` instead of `UnimplementedPortalAuthenticator`; (b) wire a real
  `.../actions/sync` handler; neither requires touching `Kern\TaxRomania\Spv`'s Laravel-side code at all
  — the wire contract (JSON shapes documented in `HttpSpvBrowserSessionClient`'s own docblock) is
  already the full, real, tested contract.

## What is NOT done

- **No ANAF SPV portal navigation of any kind.** `UnimplementedPortalAuthenticator` throws for
  both `/credentials` and `/challenge`; `.../actions/sync` and `.../actions/submit-d212` remain the
  M0 `501` stubs. By design — `docs/AGENTS.md` §1 rule 1 and this task's own brief forbid inventing
  any selector, URL, field name, or error code the real portal has not been observed to use.
- **`AnafCertificateApiTransport` has never been exercised against the real ANAF endpoint.** No
  qualified digital certificate is available in this environment. The code is real and follows the
  documented contract exactly (`docs/reference/anaf-spv-integration.md`), but its behaviour against
  a live response — especially `data_creare`'s actual date format, which the reference doc does not
  specify — is unverified. This transport throws rather than guesses if the format PHP's
  `DateTimeImmutable` constructor cannot parse it; see that class's own docblock.
- **No `webserviced.anaf.ro` reachability/host-currency check.** The reference doc itself flags
  this: "whether `webserviced.anaf.ro` is the current production host... has not been called,
  because doing so requires a qualified certificate we do not have." `config('spv.anaf_api.
  base_url')` defaults to that documented (2018) URL; if it has moved, only a certificate holder
  can discover the real one, the same way `docs/handoff/016`'s BNR endpoint move was discovered.
- **No mTLS/signed-JWT internal auth** for Laravel↔`apps/spv-browser` — a shared-secret bearer
  token instead, matching `apps/processing`'s own documented compromise (Key decisions #3).
- **No D212 submission flow.** Out of scope by this task's own brief; `.../actions/submit-d212`
  is untouched.
- **No HTTP/API controller or route layer** for this module — domain actions only, matching
  Declarations' precedent. `app/Http/**` was another agent's concurrent scope.
- **No real Playwright browser exercised in this session** (same limitation `docs/handoff/004`
  already recorded — no cached Chromium binaries in this sandbox). `docker/playwright/Dockerfile`
  installing Chromium is a Docker-image concern outside this task's path scope; nothing here
  changes browser launch behaviour, only the HTTP layer around the existing session lifecycle.
- Did not touch `docs/STATUS.md` (explicit instruction), `packages/tax-romania/src/Subjects/**`,
  `packages/documents/src/**`, or `app/Http/**` (all explicitly another agent's concurrent work per
  this task's brief).

## Open questions

- **`spv.challenge_ttl_seconds` (default 300s)** — an engineering default, not a blueprint value.
  Needs a deliberate decision before production, the same open item `docs/handoff/004` raised for
  the browser session TTL itself (which this task leaves untouched at 15 minutes).
- **`spv.anaf_api.messages_window_days` (default 60)** — `listaMesaje?zile=<days>`'s lookback
  window; ANAF's own documentation specifies the parameter but not a recommended value.
- **The final Laravel↔worker internal-auth scheme.** Both `apps/processing` and `apps/spv-browser`
  independently converged on the identical shared-secret-bearer-token compromise for the identical
  reason (no mTLS/JWT infrastructure decision exists anywhere in this codebase). Whoever makes that
  decision should update both services together, not evolve them independently.
- **Whether `apps/spv-browser`'s Docker image has Chromium installed** — unverified in this
  session (no image build/run performed); `docker/playwright/Dockerfile` is outside this task's
  path scope. Relevant only once a real `PortalAuthenticator` needs to actually launch a browser.
- **The real SPV portal DOM/selectors/challenge-type variety** — cannot be discovered without
  either a live PF test account (with explicit authorization to automate against it) or a
  synthetic-monitor-first approach per ADR 0003 §5. This is the actual blocking item for
  Milestone 10's remaining work, not anything this task could resolve.

## Sources consulted

- `BLUEPRINT.md` §13 (in full — SPV PF via Playwright), §6.11 (schema), §4.7 (MFA gate), §4.10
  (secret handling), §3.3/§3.4 (network segmentation, browser hardening), §16 (broadcast
  status/progress-only rule), §26 M10 (DoD, including cross-subject isolation), §28 (hard
  constraints — rules 1, 5, 12, 15, 16, 20 directly govern this task).
- `docs/AGENTS.md` — read in full; §0, §1 rules 1/5/12, §2 (never fabricate), §7b (own database,
  real defect classes — both realized during this task's own verification), §8 (encryption/
  tenancy rules), §10 (working with other agents).
- `docs/adr/0003-playwright-for-spv-pf.md` — read in full: the official-API-first principle, the
  ephemeral-context/no-standing-password decision, the internal contract shape (§13.4), the
  selector-policy/synthetic-monitor requirement for whenever real navigation is built.
- `docs/reference/anaf-spv-integration.md` — read completely, twice, as instructed: the exact
  documented `listaMesaje`/`descarcare` contract (including the "error is HTTP-200-shaped JSON"
  warning, applied verbatim in `AnafCertificateApiTransport`), the OAuth-certificate-only auth
  routes, and the explicit "NOT established" list this handoff's own "What is NOT done" mirrors.
- `docs/handoff/004-python-browser-services.md` — read in full: the existing `apps/spv-browser`
  session contract, the `BrowserContextFactory`/fake-factory testing pattern reused for
  `PortalAuthenticator`, the TTL open question this task does not resolve.
- `docs/handoff/017-authentication.md` — read in full: `SpvMfaPolicy`'s exact contract and "how
  Milestone 10 uses this" section, used verbatim.
- `docs/handoff/028-d212-declarations.md` — read in full: the `SubmissionTransport` seam this
  module will eventually plug into, the "no HTTP/API controller layer" precedent reused here, the
  "no real D212 form content" boundary this task respects by building no submission logic at all.
- `docs/CODE-QUALITY.md` — DRY/no-dead-code discipline (e.g. dropping the blueprint's unused
  `SpvAuthMethod::OTHER` case, matching `RentalContractStatus`'s precedent), the object-comparison
  trap (not triggered here — no `==`/`!=` on objects in this module).
- Existing code read directly before writing anything: `Kern\Documents\Extraction\
  {TextExtractionGateway,HttpTextExtractionGateway}` (the HTTP-gateway pattern this module's
  `HttpSpvBrowserSessionClient` mirrors exactly), `Kern\Documents\Events\Concerns\
  BroadcastsOnDocumentsChannel` and `routes/channels.php` (the broadcast-channel pattern),
  `Kern\TaxRomania\Rental\{Models\*, Actions\CreateRentalContract}` (denormalised `tax_subject_id`,
  single-write-with-explicit-FK Eloquent conventions), `App\Support\Crypto\{Casts\Encrypted,
  Concerns\{HasUuidPrimaryKey,FillsSubjectForeignKeyFirst}}`, `Kern\TaxRomania\Subjects\Tenancy\
  {ScopedToTaxSubject,TaxSubjectScope,TenantContext}`, `Kern\Platform\Identity\Support\SpvMfaPolicy`
  and `Models\{User,UserMfaMethod}`, `apps/processing/src/processing/internal_auth.py` (the
  internal-auth compromise this task's `internalAuth.ts` mirrors), `apps/spv-browser/src/
  {logger.ts, middleware/{requestLogging,errorHandler}.ts, sessions/*}` (the existing
  no-PII-in-logs mechanism and session lifecycle this task extends rather than replaces),
  `apps/api/deptrac.php`, `apps/api/tests/Pest.php`, `apps/api/bootstrap/providers.php`.
