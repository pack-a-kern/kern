# 017 — Authentication

**Milestone:** M1 (final piece)   **Status:** complete
**Date:** 2026-09-08

## What was built

The full authentication layer `BLUEPRINT.md` §4.7 asks for, on top of `docs/handoff/011-identity-tenancy.md`'s `users` table and `docs/handoff/005-crypto-core.md`'s envelope encryption — nothing here duplicates either:

1. **Password auth** — Argon2id (`config/hashing.php`, not Laravel's unpublished bcrypt default), looked up by email blind index, with genuine user-enumeration resistance (timing and message).
2. **Passkeys / WebAuthn** — registration and passwordless login, via `web-auth/webauthn-lib` integrated directly (not a Laravel wrapper — see "Key decisions" #1 for why the task's named dependency, `laragear/webauthn`, was not used).
3. **TOTP** (`spomky-labs/otphp`) as the MFA fallback, with a hand-rolled clock-skew window (library reason in "Key decisions" #4).
4. **Recovery codes** — hashed (Argon2id via `Hash::make()`), single-use, countable.
5. **Session management** — Redis-backed session cookie, rotated on login and on MFA-enrollment (privilege change), device/session listing, single and bulk revoke, logout.
6. **Rate limiting** on every unauthenticated auth endpoint, keyed so one account's lockout never touches another's.
7. **`Kern\Platform\Identity\Support\SpvMfaPolicy`** — the enforceable "MFA required before SPV" capability check Milestone 10 will call; SPV itself does not exist yet.
8. Every event above is recorded on the audit chain (`App\Domain\Audit`, `docs/handoff/006-audit-chain.md`), with `security_events` rows for the failure-reason cases that table exists for.

Entry points:
- `Kern\Platform\Identity\Actions\{RegisterUser,AttemptPasswordLogin,CompleteMfaChallenge,EnrollTotp,ConfirmTotpEnrollment,GenerateRecoveryCodes,CompletePasskeyRegistration,CompletePasskeyLogin,RevokeSession,RevokeAllSessions}` — one class per use case, framework/session-free (see "Key decisions" #2).
- `Kern\Platform\Identity\Support\Webauthn\PasskeyManager` — the WebAuthn ceremony orchestrator (`creationOptionsFor()`, `verifyRegistration()`, `requestOptionsForEmail()`, `verifyAssertionForEmail()`).
- `Kern\Platform\Identity\Support\Totp\TotpVerifier`, `Support\RecoveryCodes\RecoveryCodeGenerator`, `Support\SessionRegistrar`, `Support\SpvMfaPolicy`.
- `routes/api.php` — `POST /api/v1/auth/{register,login,mfa/challenge,passkeys/login/options,passkeys/login}` (public, rate-limited) and, behind `auth:web`, `logout`, `passkeys/registration/{options,}`, `mfa/{status,totp/enroll,totp/confirm,recovery-codes/regenerate}`, `sessions` (`GET`, `DELETE /{id}`, `DELETE`).

## Files created / changed

Migrations (`apps/api/database/migrations/`)
- `2026_09_08_600000_create_user_passkeys_table.php` — `credential_id`/`public_key` deliberately **plaintext** (base64), not `Casts\Encrypted` — see that migration's docblock: a WebAuthn public key isn't a secret, and the credential id must be exactly matchable.
- `2026_09_08_600001_create_user_mfa_methods_table.php` — `secret_ciphertext` encrypted under **the owning `User`'s own DEK** (`user_id`, not this row's own id — see "Key decisions" #3).
- `2026_09_08_600002_create_user_recovery_codes_table.php` — `code_hash` is `Hash::make()`, not `Casts\Encrypted` (a one-way hash is strictly stronger for a value that only ever needs verifying, never redisplaying).
- `2026_09_08_600003_create_user_sessions_table.php` — `id` **is** the real session-store id (not a surrogate); `ip_address`/`user_agent` plaintext (not in `BLUEPRINT.md` §4.3's mandatory-encryption list, ordinary session-security metadata).

Config
- `config/auth.php` — session guard `web` / provider `users` → `Kern\Platform\Identity\Models\User`. No `Auth::attempt()`/token guard anywhere (see "Key decisions" #2).
- `config/hashing.php` — **new, not in the original scope list, but required**: `HASH_DRIVER=argon2id`. Without it, `Hash::make()` silently uses Laravel's unpublished bcrypt default, which is not what `BLUEPRINT.md` §4.7 asks for — found by an actual failing test (`str_starts_with($hash, '$argon2id$')`), not by inspection.
- `config/webauthn.php` — relying-party name/id, allowed origins (defaults from `APP_URL`), ceremony timeout, challenge TTL.
- `config/identity.php` — TOTP digits/period/clock-skew, recovery-code count/entropy, every rate-limit's `max_attempts`/`decay_seconds` (`docs/CODE-QUALITY.md` §4 — no magic numbers in the code itself).

Identity (`apps/api/packages/platform/src/Identity/`)
- `Enums/MfaMethodType.php` — `TOTP` only (schema allows more later, same pattern as `SubjectType`).
- `Models/User.php` — added `Illuminate\Contracts\Auth\Authenticatable` + `Illuminate\Auth\Authenticatable` trait, `getAuthPasswordName()` override (see "Key decisions" #5 for why this is a method override, not a property), and `passkeys()`/`mfaMethods()`/`recoveryCodes()`/`sessions()` relations.
- `Models/{UserPasskey,UserMfaMethod,UserRecoveryCode,UserSession}.php`.
- `Exceptions/{InvalidCredentialsException,InvalidMfaCodeException,EmailAlreadyRegisteredException,MfaNotEnrolledException,TotpNotEnrolledException,TotpAlreadyEnrolledException,SessionNotFoundException}.php` — every constructor takes only structural arguments, never a credential/token, matching `App\Support\Crypto\Exceptions`' own convention.
- `DataTransferObjects/{PasswordLoginResult,TotpEnrollment}.php`.
- `Actions/*` (ten classes, listed above) — each does exactly one use case plus its own audit event(s); none touch `Illuminate\Http\Request`/session directly (see "Key decisions" #2).
- `Support/Totp/TotpVerifier.php` — see "Key decisions" #4 for the hand-rolled clock-skew window.
- `Support/RecoveryCodes/RecoveryCodeGenerator.php` — 10× 80-bit hex codes, grouped `XXXXX-XXXXX`.
- `Support/SessionRegistrar.php` — the only class that touches the real session store's handler directly (`destroy()`), for revoking a session that is not the caller's own.
- `Support/SpvMfaPolicy.php` — `hasStrongAuthentication()`/`ensureStrongAuthentication()`; confirmed TOTP **or** a registered passkey both satisfy it (see its own docblock for why neither is required on top of the other).
- `Support/Webauthn/{ChallengeStore,WebauthnCredentialRepository,WebauthnSerializer,PasskeyManager}.php` + `Support/Webauthn/Exceptions/{ChallengeExpiredException,PasskeyVerificationException}.php`.
- `IdentityServiceProvider.php` — binds the WebAuthn support singletons and every named rate limiter (`RateLimiter::for(...)`) — see its own docblock for exactly how each limiter is keyed.

HTTP (`apps/api/packages/platform/src/Http/Controllers/Auth/`)
- `RegistrationController`, `LoginController`, `MfaChallengeController`, `PasskeyRegistrationController`, `PasskeyLoginController`, `TotpController`, `RecoveryCodeController`, `SessionController`, `LogoutController`, `MfaStatusController`.
- `Concerns/EstablishesAuthenticatedSession.php` — the one place `Auth::login()` + `session()->regenerate()` + `SessionRegistrar::register()` happen, shared by the three ways a login can finish (password-only, password+MFA, passkey).

Routes / bootstrap
- `apps/api/routes/api.php` — new file, every route rate-limited by name (unauthenticated ones) or gated by `auth:web`.
- `apps/api/bootstrap/app.php` — `routes/api.php` is registered via a `then:` callback wrapped in the **`web`** middleware group at prefix `/api`, not Laravel's own stateless `api` group — this project's auth is session-cookie based (see "Key decisions" #2), so `/api/v1/*` needs `StartSession`/`EncryptCookies`/CSRF exactly like the rest of the app.
- `apps/api/bootstrap/providers.php` — registered `IdentityServiceProvider`.
- `apps/api/deptrac.php` — added `Identity -> Audit` (Identity's actions call `AuditChainService::record()`, the same sanctioned pattern `docs/handoff/006-audit-chain.md` documents for `Subjects`).

Dependencies (`apps/api/composer.json`/`composer.lock`)
- **`web-auth/webauthn-lib` `^5.3.8`** — MIT, actively maintained, PHP `>=8.2`. See "Key decisions" #1 for why this replaces the task brief's named `laragear/webauthn`.
- **`spomky-labs/otphp` `^11.5.0`** — as specified. Not abandoned, checked 2026-09-08.
- Both pulled real transitive dependencies already present via `laravel/framework` (Symfony serializer/property-info/uid, `phpdocumentor/reflection-docblock`) or newly added by webauthn-lib itself (`spomky-labs/cbor-php`, `spomky-labs/pki-framework`, `web-auth/cose-lib`, `symfony/{serializer,property-info,property-access,type-info}`) — `composer audit`: 0 advisories.

Tests
- `apps/api/tests/Fakes/FakeWebauthnAuthenticator.php` — a **real** ECDSA P-256 key pair producing genuinely valid, signed WebAuthn attestation/assertion responses (hand-built CBOR via `spomky-labs/cbor-php`, `openssl_sign`) — `AuthenticatorAttestationResponseValidator`/`AuthenticatorAssertionResponseValidator` run for real against its output in `PasskeyTest.php`, nothing is mocked.
- `apps/api/tests/Pest.php` — added `Feature/Domain/Identity` to the `RefreshDatabase` list, plus three shared test helpers: `bootstrapIdentityCryptoForTests()` (fakes `KeyManagementService`/`BlindIndexHasher`, same pattern `BlindIndexLookupTest.php` established), `carryCookiesFrom()` and `jsonString()`/`jsonStringList()` — see "Key decisions" #6, this is genuinely load-bearing, not boilerplate.
- `apps/api/tests/Feature/Domain/Identity/{RegistrationTest,PasswordLoginTest,MfaTest,SessionManagementTest,PasskeyTest,SpvMfaPolicyTest,AuditTrailTest}.php`, `Support/Totp/TotpVerifierTest.php` — 42 tests, all real, all executed (see "How to verify").

## Key decisions

1. **`laragear/webauthn` (the package this task's brief and `docs/reference/verified-versions.md` named) was not used — `composer require` itself flags it abandoned**: "Package laragear/webauthn is abandoned, you should avoid using it. Use laravel/passkeys instead." This directly contradicts `docs/DEPENDENCY-POLICY.md` §3 ("Never adopt an abandoned package") regardless of what the task brief assumed — the verified-versions.md entry was wrong (or the package was abandoned after that check), and I corrected that file rather than silently deviating from it. `laravel/passkeys` (the suggested replacement) is real but v0.2.1, pre-1.0, released very recently — a thin wrapper around the same underlying library. Rather than pick between "known abandoned" and "very young, unproven wrapper," I depended directly on what both wrappers themselves use — **`web-auth/webauthn-lib`** (MIT, mature, continuously released through 5.4.x-dev) — and wrote this project's own thin Laravel integration (`Support/Webauthn/*`) against it. This is more integration work than a wrapper would have been, but it is a well-maintained, non-abandoned dependency with full control over how credentials are stored (plaintext public key, per that migration's docblock) — exactly the kind of judgment call `docs/AGENTS.md` §11 asks to make deliberately rather than mechanically following an instruction that turned out to rest on a stale premise.

2. **Every `Action` class is completely session/`Illuminate\Http\Request`-free.** `AttemptPasswordLogin` returns `PasswordLoginResult{user, mfaRequired}` and never calls `Auth::login()`; `CompletePasskeyLogin`/`CompleteMfaChallenge` return/mutate domain state only. **Establishing a session (`Auth::login()`, `session()->regenerate()`, `SessionRegistrar::register()`) is the HTTP layer's job**, done once in `Concerns\EstablishesAuthenticatedSession`, called from the three controllers where a login actually finishes. This is also why `config/auth.php` never calls `Auth::attempt()`: that method's built-in credential-checking has no hook for this project's own timing-safe dummy-hash comparison (`AttemptPasswordLogin`'s own docblock) or its two-step MFA gate, so it would have to be bypassed anyway — a custom guard-free flow was more honest than fighting the built-in one.

3. **`UserMfaMethod.secret_ciphertext` is encrypted under the owning `User`'s DEK (`encryptionSubjectId()` returns `user_id`), not a DEK of the `UserMfaMethod` row's own.** This mirrors `PfProfile`'s relationship to its `TaxSubject` — a TOTP secret belongs to the *user*, not to this join-row, so it shares the exact same DEK `users.email_ciphertext` already uses. Uses `App\Support\Crypto\Concerns\FillsSubjectForeignKeyFirst`, overriding `subjectForeignKeyName()` to `user_id` (the trait's default is `tax_subject_id`) — see that trait's own docblock for the attribute-assignment-order trap this closes. Unlike `User`/`TaxSubject`'s own self-referential DEK, this needed **no** two-phase-write dance: the referenced `User` row already exists by the time a `UserMfaMethod` is created, so a single `UserMfaMethod::create([...])` call works.

4. **TOTP clock-skew tolerance is hand-rolled, not `OTPHP\TOTP::verify()`'s own `$leeway`.** That parameter is in *seconds* and must be strictly less than the period (`$leeway < $period`), so it cannot express "tolerate one whole adjacent 30-second period" — `leeway = 30` on a 30-second period throws `InvalidParameterException`. `TotpVerifier::verify()` instead checks `now - N·period .. now + N·period` directly (`N = config('identity.totp.clock_skew_periods')`), reusing the library's own constant-time `compareOTP()`/`hash_equals()` for every candidate. Proven with a fixed reference timestamp (not `time()`) so the test is not flaky at period boundaries.

5. **`User`'s password-column override is a `getAuthPasswordName()` method override, not a `$authPasswordName` property override.** Tried the property first (matching `Illuminate\Auth\Authenticatable`'s own field name) — PHP raised a **fatal** "define the same property... definition differs and is considered incompatible" error, because the trait already declares `$authPasswordName = 'password'` and PHP does not allow a using class to redeclare a trait property with a different default value. Found empirically (a real fatal error under Postgres, not by inspection) — the method-override form has no such restriction.

6. **Two real, non-obvious Laravel testing-harness bugs had to be understood and fixed in the test suite itself, not the application** — both found by an actual failing/misleading test, not by inspection, and both now documented at length in `tests/Pest.php`'s own docblocks so no future agent rediscovers them by trial and error:
   - `postJson()`/`getJson()`/`deleteJson()` (`MakesHttpRequests::json()`) send **no cookies at all** unless `withCredentials()` was called first (`prepareCookiesForJsonRequest()` returns `[]` otherwise — this mirrors a real `fetch()`'s default `credentials: 'omit'`). Every multi-request Identity flow needs this.
   - `withCookie()` treats its value as **plaintext** and has the test client encrypt it again; replaying an already-`EncryptCookies`-encrypted Set-Cookie value through it double-encrypts and the server recovers garbage (silently falls back to a fresh random session, no error). `withUnencryptedCookies()` is the correct helper for replaying a real cookie value.
   Both are wrapped in one helper, `carryCookiesFrom($response)`, used everywhere a test's assertions depend on which session id a later request is on (device/session listing, fixation checks). Login-only flows that don't care about the specific session id (e.g. checking `mfa_required` pending state, or `Auth::check()`) still "work" without it, because the session `Store`/`Auth` guard are container singletons whose in-memory attributes persist across calls within one Pest test regardless of id churn — this is a real, separate testing-harness quirk, called out explicitly in `SessionManagementTest.php` so it is never mistaken for evidence the *application* doesn't need cookie-based session continuity in production (it does).

7. **A real production bug, found only by the strict-cookie-continuity test above, not by inspection: revoking your own *current* session via `DELETE /sessions/{id}` (or `DELETE /sessions?keep_current=false`) did not actually stick.** `SessionRegistrar::revoke()` correctly destroys the session-store entry, but `Illuminate\Session\Middleware\StartSession::handleStatefulRequest()` unconditionally re-persists the request's in-memory session attributes back to that same id at the end of *every* request — silently resurrecting the just-destroyed entry when the destroyed session is the one serving the current request. Fixed in `SessionController::destroy()`/`destroyAll()`: when the target is the caller's own current session, `$request->session()->invalidate()` is called immediately after, which flushes the in-memory attributes and rotates to a fresh id before `StartSession` gets a chance to re-save the old one (this also correctly logs the caller out, which is the right outcome for "I just revoked the session I'm using"). Regression-tested in `SessionManagementTest.php` ("it revokes a specific session by id..." / "...including the current one...") by asserting directly against `$handler->read($sessionId)`, not a second simulated HTTP call (see decision #6 for why that would prove nothing here).

8. **Passkey login identifies the account by email first — never a fully discoverable/resident-key flow.** `PasskeyManager::requestOptionsForEmail()`/`verifyAssertionForEmail()` key the in-flight challenge by the email's **blind index** (already a non-reversible HMAC — no new secret introduced) rather than a user id, since no session exists yet at that point. The endpoint always returns valid-shaped options (empty `allowCredentials` for an unknown email or one with no registered passkey) so the response itself cannot be used to enumerate accounts — proven in `PasskeyTest.php`.

9. **`SpvMfaPolicy::hasStrongAuthentication()` accepts a confirmed TOTP method *or* a registered passkey, not TOTP specifically.** A WebAuthn passkey ceremony already combines possession with (typically) local biometric/PIN verification (`AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED`) — requiring a *separate* TOTP enrollment on top of an already-registered passkey would add friction with no security benefit. An **unconfirmed** TOTP enrollment does not count (`UserMfaMethod::isConfirmed()`).

10. **`config/hashing.php` and `Feature/Domain/Identity` in `tests/Pest.php`'s `RefreshDatabase` list are both outside the literal file-scope list, and both necessary** — the former is the only way `BLUEPRINT.md` §4.7's "Argon2id" requirement is actually true rather than assumed (Laravel's own default is bcrypt when this file doesn't exist); the latter is the same isolation fix `docs/handoff/016-postgres-suite-stabilisation.md` applied to `Fx`/`TaxKnowledge`/`Subjects`, needed here for the same reason (fixed rows / unique blind indexes colliding across tests on a persistent Postgres connection).

## How rate limiting is keyed

Full reasoning in `IdentityServiceProvider`'s own docblock. Summary: `login-per-account-ip` is keyed on `{email blind index}|{ip}` (never raw email), so an attacker spamming one victim's email from many IPs never exhausts a single bucket, and `login-per-ip` (IP alone) independently caps how many *accounts* one IP can probe. Both are attached together on the login route. `mfa-challenge-per-user`, `passkey-ceremony-per-ip`, and `register-per-ip` follow the same "never let one identity's attempts affect another's" principle — see `config/identity.php`'s `rate_limits` for every threshold.

## User-enumeration resistance

`AttemptPasswordLogin::__invoke()` always performs exactly one `Hash::check()` call before deciding anything — against the real `password_hash` if the account exists and has one, or against a stable dummy Argon2id hash (`Cache::rememberForever('identity:dummy-password-hash', ...)`, computed once, not per-request) otherwise. `Hash::check()`'s cost is dominated by recomputing Argon2id over the stored hash's own parameters, independent of whether the comparison ultimately matches — so "unknown email" and "wrong password for a real account" take the same code path and (per `PasswordLoginTest.php`'s timing test) roughly the same wall-clock time, and return the byte-identical error message. Passkey login's `options()` endpoint gets the analogous treatment (decision #8).

## How to verify

Host PHP is 8.3; this tree needs 8.5 (Pest 5 requires `^8.4`) — used the project's own `anaf-app` image (rebuilt after the `composer.json`/`composer.lock` change), matching every prior M1 handoff's documented approach.

```text
$ composer validate --strict          # run on host — composer.json's platform pin (8.5.10) makes this safe
./composer.json is valid

$ composer audit
No security vulnerability advisories found.
```

```text
$ docker run ... anaf-app -c 'vendor/bin/pint --test'
  PASS .......................................................... 336 files

$ docker run ... anaf-app -c 'php -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G'
 [OK] No errors                                     # level 9, no baseline, whole app + tests

$ docker run ... anaf-app -c 'vendor/bin/deptrac analyse --config-file=deptrac.php'
  Violations 0, Skipped violations 0, Uncovered 290, Allowed 85, Warnings 0, Errors 0
```

Migrations, real Postgres (`pgvector/pgvector:pg18-trixie`), fresh DB:

```text
$ php artisan migrate:fresh --force
  ... (28 migrations, including this task's four) ... all DONE

$ php artisan migrate:rollback --step=4 --force && php artisan migrate --force
  # rolls back cleanly (drops user_sessions, user_recovery_codes, user_mfa_methods,
  # user_passkeys in that order) and re-applies cleanly
```

Full suite, real Postgres, run twice to prove no state leaks (`docs/handoff/016-postgres-suite-stabilisation.md`'s standing lesson):

```text
$ docker run ... anaf-app -c 'vendor/bin/pest'   # run 1
  Tests:    7 skipped, 350 passed (983 assertions)
  Duration: 58.10s

$ docker run ... anaf-app -c 'vendor/bin/pest'   # run 2, identical DB state beforehand
  Tests:    7 skipped, 350 passed (983 assertions)   # byte-identical to run 1
  Duration: 63.37s
```

This task's own 42 tests, isolated:

```text
$ docker run ... anaf-app -c 'vendor/bin/pest tests/Feature/Domain/Identity'
  Tests:    42 passed (233 assertions)
```

**A concurrent agent's `App\Domain\TaxKnowledge` migration (`2026_09_08_301001_create_source_sections_table.php`, a self-referential `parent_id` foreign key added inside its own `Schema::create` block) was broken for part of this session** — `migrate`/`migrate:fresh` failed before reaching this task's own migrations at all, blocking every verification run. Not this task's file scope; not fixed here. Worked around for interim verification by running against a scratch copy of `apps/api` with the six broken/dependent TaxKnowledge migration files temporarily removed (never touched the shared repository) — that copy's own suite: `312 passed, 38 failed` (all 38 in `TaxKnowledge`/`Fx`, the exact, expected consequence of removing their tables from a copy, not a regression), `42 passed` for `Feature/Domain/Identity` both times. The other agent fixed it later in the same session; **the final numbers above (350 passed, 0 failed, run twice) are against the real, current, shared repository**, not the scratch workaround.

## Interfaces exposed to other modules

- `Kern\Platform\Identity\Support\SpvMfaPolicy::{hasStrongAuthentication(User): bool, ensureStrongAuthentication(User): void}` — **the entry point Milestone 10 must call** before creating an `SpvConnection` (`BLUEPRINT.md` §6.11): `app(SpvMfaPolicy::class)->ensureStrongAuthentication($user)`, catching `Kern\Platform\Identity\Exceptions\MfaNotEnrolledException` and surfacing "enroll MFA first" — the same "throw, let the HTTP layer translate" contract `TenantContext::authorizeAndActivate()` already uses.
- `Kern\Platform\Identity\Models\User` — now `Illuminate\Contracts\Auth\Authenticatable`; `Auth::user()`/`$request->user()` return it directly on any `auth:web` route.
- `Kern\Platform\Identity\Support\SessionRegistrar` — `register()`/`touch()`/`revoke()`/`revokeAllForUser()`, for any future feature needing to know about or terminate a user's sessions.
- Every `Actions\*` class listed above is a plain, container-resolvable, constructor-injectable class — no service provider bindings needed (same "application service" pattern as `RegisterPfSubject`).
- Audit event vocabulary this module owns (all on the `system` chain, `subjectId: null`): `UserRegistered`, `AuthLoginSucceeded`, `AuthLoginFailed`, `AuthPasswordVerifiedPendingMfa`, `AuthMfaChallengeFailed`, `AuthRecoveryCodeUsed`, `AuthSessionRevoked`, `AuthAllSessionsRevoked`, `MfaEnrollmentStarted`, `MfaEnrolled`, `RecoveryCodesRegenerated`, `PasskeyRegistered`.
- `security_events` rows written here: `event_type` `LOGIN_FAILED` (`context_json: {method: 'password'|'passkey'}`) and `MFA_CHALLENGE_FAILED`.

## What is NOT done

- **No password-reset/forgot-password flow** — not asked for in this task's brief; `config/auth.php` deliberately carries no `passwords` broker config since nothing uses it yet.
- **No "disable MFA" or "replace an already-confirmed TOTP method" flow** — `EnrollTotp` refuses (`TotpAlreadyEnrolledException`) if a confirmed method already exists; building the disable/replace flow is a reasonable follow-up but wasn't asked for.
- **No WebAuthn resident-key/fully-discoverable login** — every passkey flow identifies the account by email first (decision #8); a true "tap your key, no email" flow is a different UX decision this task didn't make.
- **No `laravel/passkeys` or any Laravel-specific WebAuthn wrapper** — deliberate, decision #1; this module's own `Support/Webauthn/*` is the integration layer.
- **No rate limiting on `GET /sessions` or the authenticated MFA/session endpoints** beyond the ones named in `config/identity.php` — an authenticated user hammering their own account's read endpoints is a much smaller concern than the unauthenticated ones this task did rate-limit; not built.
- **No frontend** — this is API-only, matching every other M1 deliverable; `docs/STATUS.md` already records no frontend exists yet.
- Did not touch `apps/api/app/Domain/TaxKnowledge/**`, `apps/processing/**`, or `docs/STATUS.md` (explicitly out of scope for this task).

## Known issue found outside this task's scope (not fixed here, and since resolved by another agent)

Recorded here only for the record, since it blocked verification for part of this session: `2026_09_08_301001_create_source_sections_table.php` (TaxKnowledge) added a self-referential `parent_id` foreign key inside its own `Schema::create()` call, which Postgres rejects ("there is no unique constraint matching given keys for referenced table") — broke `migrate`/`migrate:fresh` entirely until fixed by that module's own agent later in this session. No action needed from a future reader; confirmed resolved in "How to verify" above (the final full-suite run is against the real, current, already-fixed repository).

## Open questions

- Should `EnrollTotp`'s refusal to replace a confirmed method (decision above) be relaxed once a "disable MFA" flow exists, so re-enrollment becomes a single step? Left as-is since no such flow was asked for.
- `web-auth/webauthn-lib`'s `AttestationStatementSupportManager` here only registers `none` (the default) — direct/indirect attestation (device-certificate verification) is not supported. Fine for this project's threat model (login strength comes from the signature check, not manufacturer attestation), but worth a deliberate decision if a future compliance requirement needs attestation chains.
- Should the `mfa-challenge-per-user` rate limiter be re-keyed once a "pending MFA" identity is available server-side (right now it falls back to IP-only, since the user isn't `Auth`-authenticated yet at that point — see `IdentityServiceProvider`)? Still provides real protection (per-IP), just coarser than the name implies.

## Sources consulted

- `BLUEPRINT.md` §4.7 (this task's literal brief), §4.1/§4.3/§4.4 (threat model, encrypted fields, blind indexes), §5.1 (`users` schema), §6.1 (`user_passkeys`/`user_mfa_methods`/`user_recovery_codes`/`user_sessions` table names), §21.4 (security tests).
- `docs/AGENTS.md` §6 (module boundaries, sanctioned cross-module traffic), §8 (blind-index formula, no PII in logs, mandatory cross-tenant/security tests), §11 (escalate instead of guessing — applied to decision #1).
- `docs/DEPENDENCY-POLICY.md` §1–4 — pin format (caret, not exact, for Composer — corrected after an initial mistake caught by `composer validate --strict`'s own warning), abandoned-package prohibition (decision #1), justification requirement.
- `docs/CODE-QUALITY.md` §4 (no magic numbers — every rate limit/TOTP parameter lives in `config/identity.php`).
- `docs/handoff/005-crypto-core.md` — `Casts\Encrypted`, `HasUuidPrimaryKey`/`FillsSubjectForeignKeyFirst` ordering traps, read in full before writing `UserMfaMethod`.
- `docs/handoff/011-identity-tenancy.md` — `users` table, `User::createWithEmail()`, `TenantContext`, the `security_events` cross-module-write convention (`DB::table(...)->insert(...)`, never importing `Subjects`' model).
- `docs/handoff/006-audit-chain.md` — `AuditChainService::record()`, `SYSTEM_CHAIN_KEY`, the `Identity -> Audit` deptrac grant this task added.
- `docs/handoff/016-postgres-suite-stabilisation.md` — "run the suite against Postgres before claiming done," the `RefreshDatabase`-per-directory pattern this task extended to `Feature/Domain/Identity`.
- `docs/reference/verified-versions.md` — read before adding any dependency; corrected in this task (decision #1) rather than trusted blindly.
- Library source read directly, not assumed, for every claim in "Key decisions": `vendor/web-auth/webauthn-lib/src/{AuthenticatorDataLoader,CeremonyStep/{CheckSignature,CheckAllowedOrigins,CheckUserHandle,CheckAllowedCredentialList},Denormalizer/*}.php` (exact byte layout of `authenticatorData`, COSE key CBOR structure, origin/RP-ID/user-handle verification logic — used to build `Tests\Fakes\FakeWebauthnAuthenticator` correctly on the first fully-working attempt after fixing the origin mismatch), `vendor/spomky-labs/otphp/src/{OTP,TOTP}.php` (`verify()`'s `$leeway` semantics — decision #4), `vendor/laravel/framework/src/Illuminate/{Auth/Authenticatable,Foundation/Testing/Concerns/MakesHttpRequests,Session/Middleware/StartSession,Cookie/Middleware/EncryptCookies}.php` (decisions #5, #6, #7 — all three were empirical findings from real fatal errors/failing tests, confirmed against the actual framework source afterward, not guessed).
