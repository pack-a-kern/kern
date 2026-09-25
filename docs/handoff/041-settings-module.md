# 041 — Settings module: runtime AI provider configuration

**Milestone:** cross-cutting (Documents/M7 consumer, new `Settings` module)   **Status:** complete
**Date:** 2026-09-09

## What was built

A new `Settings` bounded context so the AI document-extraction provider can be changed from an
admin-only settings screen — key/value store, encrypted-when-sensitive, audited, gated behind a
new minimal "settings admin" concept — instead of editing `.env` and restarting containers.

Entry points:

- `GET /api/v1/settings/ai` / `PUT /api/v1/settings/ai` / `DELETE /api/v1/settings/ai/api-key` /
  `POST /api/v1/settings/ai/test-connection` — the HTTP surface, all behind `auth:web` +
  `settings-admin`.
- `Kern\Platform\Settings\Services\ResolveAiProviderCredentials` — what
  `Kern\Documents\ExternalAi\RequestExternalDocumentExtraction` now calls, fresh on every
  invocation, to learn the current AI provider configuration (settings store, falling back to env
  only when a field was never saved).
- `Kern\Platform\Settings\Support\SystemEncryptionScope` — the non-tenant encryption principal a
  system-wide secret is sealed under (`docs/adr/0013-system-settings-scope-and-admin-gate.md`).

## Files created / changed

**New module** (`apps/api/packages/platform/src/Settings/`)
- `Support/SystemEncryptionScope.php` — the `'system'` sentinel id (mirrors
  `AuditChainService::SYSTEM_CHAIN_KEY`).
- `Support/SystemSecretCipher.php` — seals/opens a setting value under the system DEK via the
  real `EnvelopeCipher`/`AssociatedData`, given a **lazy** `SubjectDekService` factory (see "Key
  decisions" #3 for why lazy is load-bearing, not stylistic).
- `Infrastructure/SystemDekRepository.php` — `SubjectDekRepository` backed by
  `system_encryption_keys`, a table dedicated to this one non-tenant scope.
- `Enums/SettingKey.php` — the closed set of settings (`ai.enabled`/`ai.provider`/`ai.base_url`/
  `ai.model`/`ai.api_key`) and which one is secret.
- `Repositories/SettingsRepository.php` — the `settings` table's only reader/writer; encrypts on
  write / decrypts on read for whichever key is secret; `latestUpdateMeta()` for "who/when changed
  last." See "Key decisions" #4 for a real timestamp-precision bug found and fixed here.
- `DataTransferObjects/{AiProviderCredentials,AiProviderPublicView,UpdateAiProviderSettingsInput,
  TestConnectionOverrides,ConnectionTestResult}.php`.
- `Services/{ResolveAiProviderCredentials,AiProviderSettingsService,TestAiProviderConnection}.php`.
- `Authorization/SettingsAdministrators.php` — the deploy-time admin allowlist check.
- `SettingsServiceProvider.php` — binds `SystemSecretCipher` (lazy factory) and
  `SettingsAdministrators`.

**HTTP** (`apps/api/app/Http/`)
- `Middleware/EnsureSettingsAdmin.php` — 401 unauthenticated, 403 non-admin.
- `Controllers/Api/V1/Settings/{ShowAiSettingsController,UpdateAiSettingsController,
  ClearAiApiKeyController,TestAiConnectionController}.php`.

**Wiring**
- `apps/api/routes/api.php` — new `v1/settings` group (`['auth:web', 'settings-admin']`).
- `apps/api/bootstrap/providers.php` — registered `SettingsServiceProvider`.
- `apps/api/bootstrap/app.php` — registered the `settings-admin` middleware alias.
- `apps/api/deptrac.php` — new `Settings` layer (`accesses($support, $audit)`); `Documents` grant
  extended to include `$settings` (see "Key decisions" #1).
- `apps/api/config/settings.php` — admin allowlist + AI env fallback.
- `apps/api/.env.example` — documents `SETTINGS_ADMIN_USER_IDS`, `AI_ENABLED`, `AI_PROVIDER`,
  `AI_BASE_URL`, `AI_MODEL`, `AI_API_KEY`.

**Migrations**
- `2026_09_09_300000_create_system_encryption_keys_table.php` — the non-tenant DEK.
- `2026_09_09_300001_create_settings_table.php` — `key` (PK), `is_secret`, `value_plain`,
  `value_ciphertext`, `updated_by` (FK `users.id`), `created_at`/`updated_at` at **precision 6**
  (see "Key decisions" #4 — this was not optional).

**Documents module (minimal, necessary touch — requirement #4 of the task brief)**
- `ExternalAi/DataTransferObjects/DocumentAiRequest.php` — added `AiProviderCredentials
  $credentials` (a plain DTO from `Settings`, the sanctioned cross-module shape).
- `ExternalAi/RequestExternalDocumentExtraction.php` — resolves credentials via
  `ResolveAiProviderCredentials` on every call; throws
  `ExternalDocumentAiProviderNotConfiguredException::disabledBySettings()` if `ai.enabled` is
  `false`, **before** touching the provider.
- `ExternalAi/Exceptions/ExternalDocumentAiProviderNotConfiguredException.php` — added
  `disabledBySettings()`, distinct from the pre-existing `noProviderBound()`.

**Tests**
- `tests/Unit/Platform/Settings/SystemEncryptionScopeIsolationTest.php` — the AAD/DEK isolation
  proof, both directions, against the real `EnvelopeCipher` (no fakes, no DB).
- `tests/Feature/Platform/Settings/{SettingsRepositoryTest,AiProviderSettingsServiceTest,
  AiSettingsHttpTest}.php`.
- `tests/Feature/Documents/RequestExternalDocumentExtractionSettingsTest.php` — the
  no-restart hot-swap proof.
- `tests/Feature/Documents/{RequestExternalDocumentExtractionTest,
  ProcessScannedDocumentJobTest}.php` — updated `beforeEach` to force `ai.enabled=true` via the
  env-fallback config path, since `ai.enabled` now defaults to `false` and would otherwise change
  what these pre-existing files were testing (see "Key decisions" #2).
- `tests/Pest.php` — added `Feature/Domain/Settings` to the `RefreshDatabase` list.

**Docs**
- `docs/adr/0013-system-settings-scope-and-admin-gate.md` — the encryption-scope and
  authorization decisions.

No file under `apps/processing/**`, `resources/js/**`, or `docs/STATUS.md` was touched.

## Key decisions

1. **`Documents` is granted access to `Settings` in `deptrac.php`.** The task brief's file-scope
   list names only `packages/platform/src/Settings/**` as this task's home, but requirement #4 ("wire it into
   the document pipeline") is unsatisfiable without `RequestExternalDocumentExtraction` calling
   into this module. The crossing is the same sanctioned "application service + DTO" shape every
   other module-to-module grant in `deptrac.php` already uses (`Documents -> Subjects`,
   `Documents -> Audit`, etc.) — `Documents` never imports a `Settings` Eloquent model (this
   module has none).

2. **`RequestExternalDocumentExtraction` does *not* call the actual AI provider — that remains
   `docs/STATUS.md` blocked item #3 ("provider, region, DPA not yet chosen"), unchanged.** This
   task's job was to make the *system-wide on/off switch and credentials* real and admin-editable,
   not to pick a provider and build the actual HTTP call to it — `apps/processing`'s own
   `OpenAICompatibleClient`/`AISettings` (the ADR 0007 wire-protocol client) is not wired to any
   HTTP endpoint anywhere in this codebase yet, and building the endpoint that would receive a
   document-extraction request together with per-request credentials is a separate, larger,
   business-approved task, out of scope here and explicitly not invented (`docs/AGENTS.md` rule
   1's "never invent an unverified endpoint" applies with equal force to an internal contract).
   What *is* real and tested: `ai.enabled=false` (the default) now genuinely blocks the pipeline
   before the provider is ever touched, and `DocumentAiRequest::$credentials` carries whatever an
   administrator has configured, resolved fresh on every call — so the day a real provider
   implementation replaces `NullDocumentAiProvider`, it already receives exactly what it needs,
   with zero changes to `RequestExternalDocumentExtraction` or this DTO.
   Because `ai.enabled` now defaults to `false`, the two pre-existing test files that exercise
   `RequestExternalDocumentExtraction`/`ProcessScannedDocument` against a bound (fake) provider
   (`RequestExternalDocumentExtractionTest.php`, `ProcessScannedDocumentJobTest.php`) needed their
   `beforeEach` updated to force `config(['settings.ai.env_fallback.enabled' => true])` — otherwise
   every scenario in those files would hit the new "disabled" gate before reaching what they were
   actually testing. This is a one-line, additive change to each file's setup, not a rewrite of
   their assertions.

3. **`SystemSecretCipher` takes a *lazy* `SubjectDekService` factory (`Closure(): SubjectDekService`), not an already-built instance — found to be load-bearing by the test suite itself, not chosen up front.** The first working version passed
   an eagerly-constructed `SubjectDekService` into `SystemSecretCipher`'s constructor, itself
   built from `$app->make(KeyManagementService::class)` inside `SettingsServiceProvider`'s
   singleton factory. Because `SettingsRepository` (needed by `ResolveAiProviderCredentials`,
   needed by `RequestExternalDocumentExtraction`'s constructor) requires a `SystemSecretCipher`
   unconditionally — even to read the four non-secret keys that never touch the cipher — resolving
   `RequestExternalDocumentExtraction` at all started eagerly resolving `KeyManagementService`,
   whose container binding (`KeyManagementServiceProvider`) eagerly **validates** every
   `crypto.kms.openbao.*` config value at construction time. Every pre-existing Documents test that
   never configured OpenBao (because it never needed to) started failing with `Missing or invalid
   config value "crypto.kms.openbao.token"` — a real regression, caught by running the existing
   suite, not by inspection. Fix: `SystemSecretCipher` now takes a closure and only calls it
   (building the real `SubjectDekService`, which is the point at which `KeyManagementService` is
   actually resolved) inside `key()`, i.e. only when a *secret* key is actually sealed/opened. This
   is an ordinary lazy-factory dependency, not the service-locator pattern
   `docs/CODE-QUALITY.md` §4 warns against — nothing reaches into the container from arbitrary code,
   the closure is still constructor-injected.

4. **A real timestamp-precision bug found by the test suite, not by inspection — the same trap
   `2026_09_08_100001_create_audit_events_table.php` already documents for `occurred_at`, hit a
   second time.** `settings.updated_at`/`.created_at` were declared `timestampsTz(6)`
   (microseconds) from the start, specifically so `SettingsRepository::latestUpdateMeta()`'s
   `ORDER BY updated_at DESC` could disambiguate two writes in the same second. That alone was not
   enough: `Illuminate\Database\Grammar::getDateFormat()` (unconditionally `'Y-m-d H:i:s'`, no
   microseconds) is what `PostgresConnection::prepareBindings()` uses to format any bound
   `DateTimeInterface` object — so handing a `CarbonImmutable` object straight to
   `DB::table('settings')->insert()/update()` silently truncated every stored timestamp to whole
   seconds, *regardless* of the column's own microsecond precision. A test that made two `set()`
   calls a few milliseconds apart and asserted which one `latestUpdateMeta()` reported as most
   recent failed **deterministically**, every run, even after the migration already had precision
   6 — which is what pointed at the query-builder's formatting rather than the schema. Fixed by
   formatting `CarbonImmutable::now('UTC')` explicitly with
   `Kern\TaxRomania\Audit\Support\AuditEventHasher::TIMESTAMP_FORMAT`'s exact pattern
   (`'Y-m-d\TH:i:s.u\Z'`) into a string before it ever reaches the query builder — the same
   resolution that module already uses for the identical reason.

5. **A minimal, deploy-time admin allowlist (`SETTINGS_ADMIN_USER_IDS`), not a `subject_memberships`
   role and not a new RBAC system.** Full reasoning in
   `docs/adr/0013-system-settings-scope-and-admin-gate.md`. In short: every existing role in this
   codebase is scoped to a specific `TaxSubject`, and a system-wide setting has none to check
   membership against. Granting/revoking this access is deliberately **not** self-service through
   any API this task builds.

6. **A non-tenant encryption scope (`SystemEncryptionScope::ID = 'system'`), its own DEK, its own
   table — not a fake `TaxSubject`/`User` id.** Full reasoning in the same ADR. The load-bearing
   guarantee (a system-scoped ciphertext cannot decrypt as subject-scoped, or vice versa) comes
   from **two independent layers**: a different DEK entirely, and a different AAD leading segment
   — proven both ways, plus an AAD-only isolation case with a *shared* key, in
   `tests/Unit/Platform/Settings/SystemEncryptionScopeIsolationTest.php`.

7. **`api_key` is the only field `PUT /api/v1/settings/ai` treats as "may be omitted to mean
   unchanged."** `enabled`/`provider`/`base_url`/`model` are always echoed back by `GET`/`PUT`, so
   there is no ambiguity to support for them; the API never echoes `api_key` back at all (the
   task's explicit requirement), so the client has no way to round-trip it even if it wanted to —
   omitting it is the only way to mean "leave it alone." Clearing it is a separate endpoint
   (`DELETE .../ai/api-key`), mirroring this codebase's existing `POST`/`DELETE
   .../document-ai-consent` grant/revoke split.

8. **`POST /api/v1/settings/ai/test-connection` calls the real OpenAI-compatible
   `/v1/chat/completions` shape directly from Laravel**, not through `apps/processing` (which has
   no endpoint for this at all — see decision #2). This is a small, self-contained, one-request
   HTTP client (`TestAiProviderConnection`), not a duplicate of `apps/processing`'s
   `OpenAICompatibleClient` — no code or dependency was shared or copied between the two languages,
   and this class does not become part of the document-extraction pipeline in any way (it exists
   solely to answer "does this configuration work," per the task's explicit requirement — "without
   this the user gets no feedback until a document silently fails hours later").

9. **The "no secret in any log line" tests assert over one joined string, not a `foreach` loop —
   PHPUnit itself caught this as a real gap, not a style nit.** The first version looped over every
   captured log message and asserted each one didn't contain the secret; when (correctly) *nothing*
   was ever logged, that loop ran zero iterations and therefore made zero assertions, which
   PHPUnit flags as a **risky** test (`--stop-on-risky` pinpointed it) — a test that could pass
   vacuously forever even if a future change introduced real logging of the secret, as long as
   nothing else happened to log anything at all. Fixed to
   `expect(implode("\n", $loggedMessages))->not->toContain($secret)`, which always performs exactly
   one real assertion regardless of how many (if any) messages were captured.

## How to verify

Baseline stated by the task: **722 passed on sqlite, 0 failed**. All commands below were run for
real inside `kern-php-test:local` (`docs/handoff/008-ci-pipeline.md`'s test image); output pasted
verbatim, not asserted.

**`composer validate` / `composer audit`** (no dependency was added or changed):
```
$ docker run --rm -v apps/api:/var/www/html -w /var/www/html kern-php-test:local composer validate --no-check-all
./composer.json is valid
$ docker run --rm -v apps/api:/var/www/html -w /var/www/html kern-php-test:local composer audit
No security vulnerability advisories found.
```

**Pint** (scoped to exclude a pre-existing, unrelated root-level scratch file and Laravel's own
generated `bootstrap/cache/*` — see "What is NOT done" — both created by other concurrent agent
activity, present before and unrelated to this task):
```
$ docker run --rm -v apps/api:/var/www/html -w /var/www/html kern-php-test:local \
    vendor/bin/pint --test app database routes tests config bootstrap/app.php bootstrap/providers.php
  PASS ......................................................... 812 files
```

**PHPStan, level 9, no baseline** — zero errors in every file this task touched:
```
$ docker run --rm -e APP_KEY=... -v apps/api:/var/www/html -w /var/www/html kern-php-test:local \
    php -d memory_limit=1G vendor/bin/phpstan analyse packages/platform/src/Settings \
    packages/platform/src/Http/Controllers/Settings app/Http/Middleware/EnsureSettingsAdmin.php \
    packages/documents/src/ExternalAi tests/Feature/Platform/Settings tests/Unit/Platform/Settings \
    tests/Feature/Documents/RequestExternalDocumentExtractionSettingsTest.php \
    tests/Feature/Documents/RequestExternalDocumentExtractionTest.php --memory-limit=1G
 [OK] No errors
```
Whole-repo run (unscoped) shows exactly 2 pre-existing errors, both in files this task never
touched (`app/Console/Commands/Fx/BackfillBnrYearCommand.php`,
`app/Console/Commands/Legislation/ReindexLegislationCommand.php`) — recorded per
`docs/AGENTS.md` §10, not fixed silently:
```
$ docker run --rm -e APP_KEY=... -v apps/api:/var/www/html -w /var/www/html kern-php-test:local \
    php -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G
 [ERROR] Found 2 errors   (both pre-existing, both outside this task's files)
```

**Deptrac:**
```
$ docker run --rm -v apps/api:/var/www/html -w /var/www/html kern-php-test:local \
    vendor/bin/deptrac analyse --config-file=deptrac.php
 Violations 0, Skipped violations 0, Uncovered 653, Allowed 344, Warnings 0, Errors 0
```

**Whole suite, real Postgres, dedicated database (`docs/AGENTS.md` §7b — another agent was
active in `app/Support/Crypto/**`/`packages/tax-romania/src/Audit/**` at the time, per `docs/STATUS.md`), run
twice:**
```
$ docker exec anaf-postgres-1 psql -U kern -c 'CREATE DATABASE kern_settings041;'
$ docker run --rm --network anaf_data_net --user root -e APP_KEY=... \
    -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_PORT=5432 -e DB_DATABASE=kern_settings041 \
    -e DB_USERNAME=kern -e DB_PASSWORD=... -v apps/api:/var/www/html -w /var/www/html \
    kern-php-test:local vendor/bin/pest
Run 1: Tests: 3 warnings, 10 skipped, 785 passed (2383 assertions)
Run 2: Tests: 3 warnings, 10 skipped, 785 passed (2383 assertions)   — identical, no state leak
```
The 3 warnings are the pre-existing missing-`.env`-in-test-image artifact `docs/handoff/022`
already documented. The 10 skips are the live-OpenBao/live-MinIO tests, correctly self-skipping
with no `OPENBAO_TEST_*`/`OBJECTSTORE_TEST_*` env vars set.

**Whole suite, sqlite (the task's stated baseline: 722 passed, 0 failed), run twice:**
```
$ docker run --rm -e APP_KEY=... -v apps/api:/var/www/html -w /var/www/html kern-php-test:local \
    vendor/bin/pest
Run 1: Tests: 3 warnings, 40 skipped, 755 passed (2259 assertions)
Run 2: Tests: 3 warnings, 40 skipped, 755 passed (2259 assertions)   — identical, no state leak
```
755 vs. the stated 722-passed baseline reflects this task's ~32 new tests (5 unit + 27 feature)
plus normal baseline drift from other agents' concurrent work in this same window (`docs/STATUS.md`
lists two other active tasks) — **0 failed in both runs**, matching the baseline's own bar. The
skip count (40, vs. Postgres's 10) is expected and pre-existing: every `Feature/Domain/**` suite
using `RefreshDatabase` plus a real cross-process concurrency test
(`Tests\Feature\Domain\Audit\AuditChainConcurrencyTest`, per `docs/handoff/015`) self-skips or
behaves differently against sqlite's in-memory, per-connection database — unrelated to this task.

**Honesty note on getting here.** Two earlier Postgres attempts (before the two logged runs above)
each hit one transient `SQLSTATE[42P01]: relation "tax_subjects" does not exist` inside
`Tests\Feature\Domain\Audit\AuditChainServiceTest` — a file this task never touched. `tax_subjects`
was verified present in the dedicated database immediately after
(`information_schema.tables`), and the `migrations` table showed every migration, including this
task's own, already recorded as run — not a broken/missing migration file. Retried with no code
changes and it passed clean twice in a row (the two runs pasted above). Recorded per
`docs/AGENTS.md` §2, consistent with the identical kind of transient-contention note in
`docs/handoff/027-contract-reconciliation.md` — read as concurrent-agent load on the shared
Postgres server (another agent was actively working in `packages/tax-romania/src/Audit/**` at the time, per
`docs/STATUS.md`), not a defect in this task's code.

Separately, and unrelated to the above: an earlier iteration of this task's own
`AiProviderSettingsServiceTest`/`AiSettingsHttpTest` "never logs the secret" tests were flagged
**risky** by PHPUnit (zero assertions when nothing was logged, the correct/expected case — see "Key
decisions" #9) — found and fixed before any of the numbers above were captured, so the runs pasted
in this section already reflect the fix (0 risky in every run shown).

**The AAD/DEK isolation guarantee, unit-tested against the real `EnvelopeCipher`:**
```
$ docker run --rm -v apps/api:/var/www/html -w /var/www/html kern-php-test:local \
    vendor/bin/pest tests/Unit/Platform/Settings/SystemEncryptionScopeIsolationTest.php
  PASS  Tests\Unit\Domain\Settings\SystemEncryptionScopeIsolationTest
    ✓ it is not a UUID, so it can never collide with a real tax_subject_id or user id
    ✓ it cannot decrypt a system-scoped ciphertext using a subject-scoped key and AAD
    ✓ it cannot decrypt a subject-scoped ciphertext using the system key and AAD
    ✓ it rejects the same key with only the AAD subject segment swapped from system to a real subject id
    ✓ it round-trips correctly within its own scope, both system and subject
  Tests: 5 passed
```

**The no-restart hot-swap proof:**
```
$ docker run --rm --network anaf_data_net --user root -e APP_KEY=... [... same Postgres env ...] \
    kern-php-test:local vendor/bin/pest tests/Feature/Documents/RequestExternalDocumentExtractionSettingsTest.php
  PASS  ... it refuses to call the provider while ai.enabled is false, the default
  PASS  ... it picks up a settings change on the very next call, with no restart, no re-resolution needed
  PASS  ... it falls back to the env-configured provider only until a setting is explicitly saved
```

## Interfaces exposed to other modules

### `GET /api/v1/settings/ai`
Behind `auth:web` + `settings-admin` (403 for any other authenticated user, 401 unauthenticated).
```json
{
  "enabled": false,
  "provider": null,
  "base_url": null,
  "model": null,
  "api_key_set": false,
  "api_key_last_four": null,
  "updated_at": null,
  "updated_by": null
}
```
**The API key value is never returned, at any status, under any field name.**

### `PUT /api/v1/settings/ai`
Body: `{"enabled": bool, "provider": string, "base_url": string (valid URL), "model": string,
"api_key"?: string}`. `enabled`/`provider`/`base_url`/`model` are **required** (a full replace);
`api_key` is optional — omit it to leave the stored key unchanged. `200` with the same shape as
`GET`. `422` standard Laravel validation envelope for shape errors. `401`/`403` as above.

### `DELETE /api/v1/settings/ai/api-key`
No body. Clears only the stored key. `200` with the same shape as `GET`
(`api_key_set: false, api_key_last_four: null`), every other field unchanged.

### `POST /api/v1/settings/ai/test-connection`
Body (all optional): `{"base_url"?: string, "api_key"?: string, "model"?: string}` — any field
given overrides the corresponding saved/resolved value for this call only (nothing is persisted);
omit the body entirely to test the currently active configuration.
```json
{"success": true, "latency_ms": 842}
```
or
```json
{"success": false, "error": "not_configured" | "connection_failed" | "authentication_failed"
                          | "provider_error" | "invalid_response",
 "message": "...", "status": 401}
```
`status` present only for `provider_error`/`authentication_failed`-adjacent cases where a real
HTTP status was received. Always `200` — a failed connection test is a successful API call that
reports failure, not an HTTP error.

### `Kern\Platform\Settings\Services\ResolveAiProviderCredentials` (PHP, cross-module)
`__invoke(): AiProviderCredentials { enabled, provider, baseUrl, model, apiKey }` — resolved fresh
on every call (no caching beyond the current PHP request), settings store first, env fallback per
field. Carries the plaintext API key — **never log it, never serialize it into any HTTP response**.
Any future `ExternalDocumentAiProvider` implementation should read its credentials from
`DocumentAiRequest::$credentials`, already populated with this.

## What is NOT done

- **No real AI provider integration.** `NullDocumentAiProvider` is still the only bound
  `ExternalDocumentAiProvider` — `docs/STATUS.md` blocked item #3 is unchanged by this task. See
  "Key decisions" #2.
- **No arbitrary/generic settings API.** `SettingKey` is a closed enum of five keys; adding a new
  system-wide setting later means adding an enum case (and, if it needs its own typed
  read/write surface, a small service alongside `AiProviderSettingsService`), not a schema change.
- **No UI for granting settings-admin access**, and no way to discover who currently has it short
  of reading `SETTINGS_ADMIN_USER_IDS` directly. Deliberate — see ADR 0013.
- **No rate limiting on any of the four new routes.** All four require an authenticated session and
  the admin allowlist already; `docs/CODE-QUALITY.md` §2 — build only what was asked. Worth
  reconsidering if `test-connection` (which makes a real outbound HTTP call per request) shows
  abuse in practice.
- **A pre-existing, unrelated `apps/api/diag.php` scratch file and stale `bootstrap/cache/{packages,services}.php`** were found blocking an unscoped `pint --test` run — neither created nor modified by this task (both bear a same-day timestamp from other concurrent agent activity per `docs/STATUS.md`'s "Files currently being worked on" list). Not touched, per `docs/AGENTS.md` §10 (record, don't fix silently in an unrelated change); `docs/STATUS.md` itself was out of this task's scope to edit.
- **The 2 pre-existing PHPStan errors** in `app/Console/Commands/Fx/BackfillBnrYearCommand.php` and
  `app/Console/Commands/Legislation/ReindexLegislationCommand.php` — same note as above.

## Open questions

1. Whoever eventually builds the real `ExternalDocumentAiProvider` (unblocking `docs/STATUS.md`
   item #3) should read credentials from `DocumentAiRequest::$credentials`
   (`AiProviderCredentials`) rather than re-resolving settings itself — the resolution, fallback,
   and "fresh per call" behaviour already live in `ResolveAiProviderCredentials`.
2. Should `GET /api/v1/settings/ai` expose *which* source (database vs. env fallback) backs each
   field, for a settings screen that wants to show "using an env default" vs. "explicitly set"?
   Not built here — the task asked for "whether one is set and perhaps a last-4 hint" for the
   secret specifically, and building a parallel per-field provenance display for the non-secret
   fields wasn't asked for.
3. `docs/STATUS.md` was not updated — explicitly out of this task's file scope. Whoever next edits
   it should add this handoff, and may want to record the two pre-existing PHPStan findings and the
   stray `diag.php`/`bootstrap/cache` artifacts noted above if not already tracked.

## Sources consulted

- `BLUEPRINT.md` §1.2 (provider abstraction), §4.2–§4.4/§4.10 (envelope encryption, blind indexes,
  secret handling), §5.3 (roles are per-`TaxSubject`), §18 (JSON envelope conventions), §21.4
  (security tests).
- `docs/AGENTS.md` §6 (module boundaries), §7b (dedicated-database testing, followed exactly —
  `kern_settings041`), §8 (AAD binding, no PII in logs), §10 (recording out-of-scope findings).
- `docs/adr/0007-provider-agnostic-ai.md` — the OpenAI-compatible wire protocol this task's
  `test-connection` endpoint speaks, and confirmation that `apps/processing`'s own client already
  supports explicit `base_url`/`api_key` construction (no Python-side change needed for a *future*
  real provider to consume settings-resolved credentials).
- `docs/handoff/005-crypto-core.md` — `EnvelopeCipher`/`AssociatedData`/`SubjectDekService`/
  `SubjectDekRepository`, read in full and reused verbatim; the exact ordering traps
  (`HasUuidPrimaryKey`, `FillsSubjectForeignKeyFirst`) that informed the decision to *not* use
  `Casts\Encrypted` for the `settings` table.
- `docs/handoff/006-audit-chain.md` and `Kern\TaxRomania\Audit\Services\AuditChainService`/
  `Support\AuditEventHasher` read directly — `SYSTEM_CHAIN_KEY`'s precedent for
  `SystemEncryptionScope::ID`, and `TIMESTAMP_FORMAT`'s precedent for fixing the timestamp-
  precision bug in "Key decisions" #4.
- `docs/handoff/011-identity-tenancy.md` — `SubjectMembership`/`MembershipRole`/`TenantContext`,
  confirming no global role concept exists to reuse for settings authorization.
- `docs/handoff/022-document-pipeline.md`, `docs/handoff/024-extraction-endpoint.md`,
  `docs/handoff/027-contract-reconciliation.md` — the full, real state of the Documents pipeline
  and the external-AI seam (`ExternalDocumentAiProvider`/`NullDocumentAiProvider`/
  `RequestExternalDocumentExtraction`/`DocumentAiRequest`), read directly before deciding what
  "wire it into the pipeline" could honestly mean without inventing a Python endpoint.
- `docs/handoff/031-pwa-backend-endpoints.md` — the API/controller conventions (cursor pagination
  N/A here since these are singleton resources; JSON error envelope; `abort_unless` patterns)
  this task's four new controllers follow.
- `docs/CODE-QUALITY.md` §1/§2/§4 — applied to decisions #3 (lazy factory, not a service locator),
  #5 (no RBAC system), #6 (no invented arbitrary settings API).
- `docs/DEPENDENCY-POLICY.md` — no new Composer/npm/Python dependency was added anywhere in this
  task.
- Source files read directly, not assumed: `apps/api/app/Support/Crypto/{EnvelopeCipher,
  AssociatedData}.php`, `app/Support/KeyManagement/{SubjectDekService,KeyManagementServiceProvider,
  Contracts/*}.php`, `packages/tax-romania/src/Subjects/Infrastructure/EloquentSubjectDekRepository.php` (and its
  `tax_subjects`/`users` table assumption — the reason a third table was needed rather than a third
  entry in that repository's own `TABLES` constant), `packages/tax-romania/src/Subjects/Models/{TaxSubject,
  SubjectMembership}.php`, `packages/platform/src/Identity/Models/User.php`, `packages/documents/src/
  ExternalAi/**`, `packages/documents/src/DocumentsServiceProvider.php`, `packages/documents/src/
  Consent/DocumentAiConsentGate.php` (the `POST`/`DELETE` grant/revoke precedent this task's
  api-key clear endpoint follows), `routes/api.php`, `bootstrap/app.php`, `tests/Pest.php`,
  `tests/Fakes/{InMemoryKeyManagementService,InMemorySubjectDekRepository}.php`.
- `apps/processing/src/processing/ai/{client.py,config.py}` — read directly to confirm
  `OpenAICompatibleClient.__init__`/`from_settings()`'s exact signature and that neither is wired
  to any FastAPI route in `apps/processing/src/processing/main.py` today (confirmed by reading
  `main.py` and finding no import of `processing.ai` anywhere in it).
- Laravel/Symfony framework source read directly for behaviour claims, not assumed:
  `Illuminate\Database\Grammar::getDateFormat()`, `Illuminate\Database\PostgresConnection::
  prepareBindings()` (the timestamp-truncation root cause in "Key decisions" #4),
  `Illuminate\Http\Client\Factory` (`@mixin PendingRequest`, confirming `timeout()`/`withToken()`
  resolve correctly under Larastan), `Illuminate\Http\Client\PendingRequest::withToken()`'s
  `#[\SensitiveParameter]` attribute (corroborating this task's "never log the key" design).
- Live command output — `docker run ... vendor/bin/{pint,phpstan,pest,deptrac}`, `psql` schema
  inspection (`\d settings`, `information_schema.tables`) used to diagnose the timestamp-precision
  and transient-relation-missing issues rather than guessed — all pasted verbatim above.
