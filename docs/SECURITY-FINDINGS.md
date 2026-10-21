# Kern — security findings, 2026-10-19

Five read-only audits of the tree at commit `70bdeaf`: authentication and tenant
isolation, cryptography and secrets, untrusted input, the document pipeline, and
the deployment. Nothing was run against a live instance.

**The verdict in one line.** No audit found a way to read another tenant's data.
The application code holds up; the deployment does not. Every finding below is
in configuration, operations, or one missing check — none of them is a flaw in
the encryption, the upload path, or the tenant scoping on reads.

Items are numbered for working through one at a time. Severity is the auditor's,
kept as given. "Verified" means I read the code myself after the report, not that
it was tested against a running stack.

---

## Fix first

### 1. Redis and Postgres are published on every interface — HIGH

**Status:** Fixed 2026-10-19. Postgres, Redis and Mailpit publish on `127.0.0.1` only; the firewall rule and `kern-port-guard.service` were removed by the operator. Vite stays on every interface for now, by decision — it is dev-only and absent from a production `COMPOSE_FILE`. Confirmed live by the penetration test before the fix.

`compose.services/redis.dev.yaml:22` and `compose.services/postgres.dev.yaml:25`
publish `6379:6379` and `5432:5432` with no `127.0.0.1:` prefix, so both bind
`0.0.0.0`. `compose.dev.yaml:51` and `:111` do the same for the Mailpit UI (8025,
no authentication) and the Vite dev server (5173).

This list is the shipped default: `apps/api/.env.example:192` carries it and
`make env` copies it. The live `apps/api/.env:641` contains exactly that list, on
a host with a public address.

Verified: `iptables -S DOCKER-USER` has one rule, dropping 5432 from everything
but a single source address. There is no rule for 6379, and no `ufw`. Sessions
live in Redis with `SESSION_ENCRYPT=false` (`apps/api/.env:74`), so reaching
Redis means impersonating any signed-in user; it also holds queue payloads.
Nothing was listening when this was written because the stack was down — this
takes effect on the next `make up`.

**Fix:** write the bindings as `127.0.0.1:6379:6379` and make that the default;
have the installer refuse a `COMPOSE_FILE` containing a `.dev.yaml` when the
answers describe a public installation.

### 2. `SUSPENDED` is never enforced — HIGH

**Status:** Fixed 2026-10-19. Refused at password login and in `EstablishesAuthenticatedSession` (all three login paths); suspending revokes every session; `EndInactiveAccountSession` ends any session an inactive account still holds. `User` now carries the column's `ACTIVE` default, without which a fresh model read as suspended. Confirmed live before the fix.

`packages/platform/src/Identity/Actions/AttemptPasswordLogin.php` does not look
at `status`; neither does `CompletePasskeyLogin.php`, `CompleteMfaChallenge.php`,
or any middleware. `UpdateUserStatusController.php` saves the column and stops
there, so existing sessions survive.

Verified: across `apps/api/app` and `packages/*/src` the only status ever acted
on is `DELETED` (the reindex filter, `EraseUser`, impersonation's active-only
check). A suspended account keeps its sessions and can sign in again, with
access to every tenant in `subject_memberships`. No test covers it —
`PlatformPermissionsTest` only asserts you cannot suspend yourself.

**Fix:** check status at login and in an authenticated-request middleware, and
revoke the user's sessions when status leaves `ACTIVE`.

### 3. php-fpm accepts FastCGI from any container — HIGH

**Status:** Fixed 2026-10-19. php-fpm listens on a unix socket on the `php_socket` volume, mounted only by `app` and `edge`; verified live that port 9000 refuses connections from the browser worker and the Python service.

`docker/php/www.conf:13` is `listen = 0.0.0.0:9000` with no
`listen.allowed_clients`. The `app` service joins `edge_net`, `app_net`,
`processing_net`, `browser_net` and `data_net`.

FastCGI is a trusted protocol: a client that reaches it chooses the script and
can set PHP options for the request. The containers that can reach it include
the browser worker, which renders third-party pages, and the Python service,
which parses uploaded files. A foothold in either becomes code execution in the
app container, which holds `DB_PASSWORD`, `OPENBAO_TOKEN`, `APP_KEY` and the S3
keys. `read_only: true` does not prevent it.

This is also what defeats the segmentation argument in
`compose.services/browser.yaml:29-31` and the deployment guide: the worker's
network carries no credentials, but it does carry a route to a process that has
them all.

**Fix:** a unix socket on a volume shared only with the edge, or a dedicated
edge↔app network with nothing else on it. `listen.allowed_clients` alone still
admits the whole subnet unless pinned to the edge's address.

### 4. Cross-tenant write via `account_id` — MEDIUM, confirmed

**Status:** Fixed 2026-10-19. The controller resolves the account through the tenant scope (404), the action refuses an account it cannot find instead of passing it, and the balance query filters by subject. Confirmed live before the fix; the three new tests fail against the old code.

`apps/api/app/Http/Controllers/Api/V1/Documents/CreateTransactionFromDocumentController.php:95`
validates `account_id` as `['required', 'uuid']` and nothing more.

Verified, and the mechanism is worth stating exactly, because the scoping is
present and still does not hold.
`packages/tax-romania/src/Wallet/Actions/RecordFinancialTransaction.php:171`
reads `FinancialAccount::query()->find($accountId)`. That query *is* tenant
scoped, so another tenant's id returns `null` — and the next line is
`if ($account !== null && $account->kind->isHolding())`, so a null account
passes the guard instead of failing it. The row is then written with the
caller's `tax_subject_id` and the other tenant's `account_id`; the foreign key
permits it. `WalletQuery::balancesByAccount`
(`packages/tax-romania/src/Wallet/Queries/WalletQuery.php:1042-1046`) sums
`financial_transactions` by `account_id` with no `tax_subject_id` filter, so the
row lands in the other tenant's balance.

The sibling endpoints get this right: `RecordTransactionController` and
`StartStatementImportController` both resolve the account through the scoped
model. The gap is this one controller, and the test at
`DocumentToTransactionTest.php:188` covers a foreign *document* but not a
foreign *account*.

It needs the other tenant's account UUID, which is UUIDv7 and not enumerable,
so it is hard to reach in practice. It is still an isolation break, and the
response also distinguishes a foreign-key failure from success.

**Fix:** `FinancialAccount::query()->findOrFail($accountId)` in the controller,
a null check rather than a null-tolerant guard in the action, and
`tax_subject_id` on the raw balance queries. Add the missing test.

### 5. The AI enhance endpoint skips the consent gate — MEDIUM

**Status:** Fixed 2026-10-19. Consent is checked before the file is read; refused with `AI_CONSENT_REQUIRED` and nothing sent. Confirmed live before the fix.

`packages/documents/src/Http/Controllers/EnhanceImageController.php` never calls
`DocumentAiConsentGate::assertConsented()`, which every other external-AI path
does (`RequestExternalDocumentExtraction.php`). It sniffs the image and posts it
to the provider as a data URI.

So a tenant that never consented, or that revoked consent, can still have an
identity document or invoice sent to a third party. The bytes are also not
scanned, since they are forwarded rather than stored.

**Fix:** call the same gate before the request.

---

## Then these

### 6. The OpenBao token expires at day 32 and nothing renews it — MEDIUM (availability)

**Status:** Fixed 2026-10-19. The audit's "self-heals on restart" was wrong: a restart only checked the token existed, so it would have expired on 2026-10-31. The bundled OpenBao now issues the token itself, publishes it on a memory-only volume read per call, replaces it with seven days left and revokes the old one at the next daily check. `kern:health` shows the days left. `OPENBAO_TOKEN` is no longer written into `.env`.

`docker/openbao/entrypoint.sh` creates the app token with `-period=768h`. A
periodic token only survives if something calls `renew-self` inside each period;
`grep` finds no renewal in the application, the compose files, or the scripts.
The script's own comment says the token was made periodic so it could not die
mid-month, which is the opposite of what happens.

Around day 32 every wrap and unwrap returns 403 and the container healthcheck
fails. It recovers only when the `openbao` container restarts and the entrypoint
issues a fresh token — which is why this has not been hit yet.

Unconfirmed at runtime; it follows from the code and from how periodic tokens
work.

**Fix:** renew on a schedule, or re-issue at boot and accept the restart as the
renewal mechanism — and say which in the entrypoint's comment.

### 7. The CNP blind index is brute-forceable with a database copy plus `.env` — MEDIUM

**Status:** Fixed 2026-10-19. The key is a transit HMAC key inside OpenBao (ADR 0020); `CRYPTO_BLIND_INDEX_KEYS` is gone and every index was recomputed live. Batched per file and per re-index chunk.

`packages/platform/src/Crypto/BlindIndex/BlindIndexHasher.php` uses HMAC-SHA256
with one global key — correctly keyed, not a bare hash. But the key lives in
`CRYPTO_BLIND_INDEX_KEYS` in `apps/api/.env`, and nothing about it involves
OpenBao, so it is not protected by the vault at all.

A CNP is sex and century, birth date, county, a three-digit serial and a check
digit: on the order of 10⁹ candidates. With the key and the
`fiscal_identifier_blind_index` column, the whole space can be enumerated
offline in minutes, and `fiscal_identifier_kind` is plaintext to say which rows
are CNPs. The same applies by dictionary to `users.email_blind_index` and the
counterparty and tag indexes.

This contradicts `DEPLOYMENT.md` §5, which says a stolen database is useless
without the master key. It is useless without the *blind-index* key too — and
that key is in a file an operator may well copy alongside a dump.

Neither half alone gives anything.

**Fix:** move the blind-index key into OpenBao (transit HMAC), so a database
copy plus a `.env` copy is not sufficient. Correct §5 either way.

### 8. Every PHP container gets every secret, including the Cloudflare token — MEDIUM

**Status:** Partly fixed 2026-10-19. Reverb takes a short list of names instead of the whole file and left `data_net`: verified live that it holds no `APP_KEY`, database, Redis, storage or service credential, cannot resolve Postgres, and still serves WebSockets and accepts publishes. The vault token and the blind-index key had already left `.env` (#6, #7). Left as accepted: `app`, `queue` and `scheduler` still see the Cloudflare token, because they hold credentials worth more; `DEPLOYMENT.md` says how to treat it. A second environment file for the edge was rejected as contrary to ADR 0017.

`compose.yaml:90` puts `env_file: apps/api/.env` on the shared `x-php-image`
anchor, inherited by `app`, `queue`, `scheduler` and `reverb`. The Python and
browser services were deliberately excluded; `reverb` was not, and it is the one
app container the edge exposes to the internet (`handle /app/*`). Reverb needs
none of the database, vault or S3 credentials.

`scripts/install.sh:687-690` writes `KERN_EDGE_DNS_TOKEN` into the same file, so
the Cloudflare token reaches every PHP process and shows in `docker inspect`. A
`Zone:DNS:Edit` token is zone-wide and cannot be narrowed to `_acme-challenge`:
it can repoint A, MX and TXT records for the whole zone, which means redirecting
mail and obtaining valid certificates for any name in it.

**Fix:** give reverb its own short env file. Keep `KERN_EDGE_*` out of the
app's. For the token, delegate `_acme-challenge` by CNAME to a throwaway zone
and scope the token to that zone.

### 9. `.env` is world-readable, and the installer leaves a window — MEDIUM

**Status:** Fixed 2026-10-19. The installer creates the file and every temporary copy under `umask 077`, scoped to those writes so it never reaches `npm ci` or an image build; measured over 400 rewrites, the copy is `600` where it used to be `644`. `make env` writes `640`, group `10001` (the app containers' user, which reads it through the dev source mount), and says so when it cannot change the group. The live file is `640`: verified that PHP in the container still reads it and another host account is refused.

Verified: the live `apps/api/.env` is mode 644. `Makefile:44-46` writes it with
`cat > …` under the default umask and never chmods. `scripts/install.sh:896`
chmods 0600 at the end, but `set_env` (`install.sh:201`) writes `$ENV_FILE.tmp`
under the default umask, moves it over, then chmods — about thirty times, each
leaving the file briefly world-readable on a machine with other logins.

**Fix:** `umask 077` at the top of `install.sh`, `chmod 600` in `make env`, and
a one-line fix for the live file.

### 10. Admin AI-settings overrides leak the stored provider key — MEDIUM

**Status:** Fixed 2026-10-19, wider than the finding. The provider's address and key are saved together, only by `PUT /api/v1/settings/ai/connection` (the screen's dialog); the general settings save refuses both, and the old `PUT settings/ai` — which kept the stored key for a new address on every real AI call, not just the test — and `DELETE settings/ai/api-key` are gone. The test and model-list endpoints refuse an address or key in the body and use only the saved pair. The `AI_*` environment variables are removed, which closed a second route to the same leak: a saved address used to be paired with a key from `.env`; the resolver now takes the key from wherever the address came from. Addresses are held to `AiProviderAddress` (scheme, host, port, plain path; no `?`, `#`, credentials, `..` or percent-encoding), so the fixed path is always the one called. The screen says only connected / could not connect; the reason (`AiProviderFailure`) goes to the log. Private hosts and `169.254.0.0/16` stay allowed, by decision.

`packages/platform/src/Settings/Services/TestAiProviderConnection.php` and
`FetchAiProviderModels.php` take a `base_url` override validated only by
Laravel's `url` rule (`TestAiConnectionController.php`,
`ListAiModelsController.php:25-42`) — no https requirement, no check against
private ranges. When `api_key` is omitted, `firstNonNull` falls back to the
stored decrypted key and sends it to the supplied host. The key is otherwise
write-only: there is no read endpoint.

The same two endpoints will also issue requests to internal addresses. The
test-connection response is a fixed enumeration, so it reveals little; the model
list returns parsed output.

Both require `system.settings.manage`, so this is an insider or
compromised-admin problem rather than an open door.

**Fix:** ignore the stored key whenever `base_url` is overridden, and reject
non-public hosts.

### 11. No CSP, no HSTS, session cookie not marked Secure — LOW/MEDIUM

**Status:** Partly fixed 2026-10-19, and one third of it was wrong. **The session cookie is marked Secure** — verified live: with `SESSION_SECURE_COOKIE` unset, Laravel marks it whenever the request arrived over HTTPS, which through the edge is every request that reaches PHP; plain HTTP only redirects. **HSTS** is a whole line from the environment (`KERN_EDGE_HSTS`), written by the installer for the two Let's Encrypt answers only, never for the self-signed or bring-your-own-certificate ones, where it would lock browsers out. **CSP** is sent by `App\Http\Middleware\ContentSecurityPolicy` in `report-only` mode, with Vite's tags allowed by a per-request nonce and no `unsafe-inline` or `unsafe-eval`; violations are logged as `csp_violation` through `/csp-report`. **Still to do:** set `CSP_MODE=enforce` once the log has been read clean.

`docker/caddy/Caddyfile:109-122` sets `X-Content-Type-Options`,
`X-Frame-Options: DENY` and `Referrer-Policy`. There is no
`Content-Security-Policy` anywhere in the edge or the app, and no
`Strict-Transport-Security`.

HSTS is reasonably absent while the default certificate is self-signed, but
install modes 2, 3 and 4 all obtain trusted certificates and nothing turns it on
afterwards.

Verified: `apps/api/config/session.php:174` is `env('SESSION_SECURE_COOKIE')`
with no default, and the variable is set in neither `.env.example` nor the live
`.env`, so Laravel falls back to `false` and the cookie is not marked Secure. A
user who types `http://` sends it in clear before the redirect. `HttpOnly` and
`SameSite=lax` are correct.

No XSS sink was found, so the missing CSP is depth rather than an open hole.

**Fix:** set `SESSION_SECURE_COOKIE=true` for every non-IP install mode, add
HSTS once the certificate is trusted, and add a CSP.

### 12. The browser worker defaults to no authentication — MEDIUM

**Status:** Fixed 2026-10-19. Confirmed live first: an unauthenticated request got past the door (`422 playbook_invalid`). `compose.services/browser.yaml` now defaults to `token`, the worker's own default; the installer generates `BROWSER_WORKER_TOKEN` for a local worker; `kern:health` asks `/v1/stats`, behind the token, so a missing or wrong token reads as down instead of healthy. Verified live: no token and a wrong token both get `401`, the app's own calls pass. `BROWSER_AUTH_MODE=network` is kept, by decision, as an explicit opt-in.

`compose.services/browser.yaml:32` defaults `BROWSER_AUTH_MODE` to `network`,
and `apps/browser-worker/src/app.ts` mounts the auth middleware only in `token`
mode. The live `BROWSER_WORKER_TOKEN` is empty, and `install.sh:501-508` does
not generate one — though the `.env` comment claims an empty token "fails closed
on both sides".

Today only `app` shares `browser_net`, so this needs an existing foothold. But
the worker runs submitted playbooks, its allow-list accepts `"*"`, and
`browser_net` is not `internal: true`, so it has outbound access.

**Fix:** default to token mode and generate the token the way the installer
already does for the Python service.

### 13. The statement parser has no memory or time ceiling — MEDIUM

**Status:** Fixed 2026-10-19, with one more cause than the audit found. Statements are read in a disposable process with a memory ceiling and a clock (`processing.isolation`, now shared with the PDF reader, which used to carry its own copy); a workbook whose zip index says it unpacks past 50 MiB is refused before it is opened; and every reader stops at one row past the limit. The audit blamed reading every row before counting them; measuring showed `openpyxl.load_workbook` itself, in read-only mode, spending 9 s and some 400 MB on a 48 KB workbook — before any row was asked for, so no in-reader limit could have caught it. Verified live: a 175 KB bomb is refused in 0.5 s from the index, a 48 KB one is refused in 2.3 s when its worker hits the memory ceiling, and the service stays healthy throughout.

`apps/processing/src/processing/statements/parse.py:192-233` (`_read_xlsx`)
builds the full row list before `_assemble` applies `max_rows:431`, and the only
size limit is 10 MiB on the *compressed* file. PDF text extraction runs in a
killable child process with limits (`documents/timeout.py`); the statement parser
runs on a FastAPI thread in the shared container, which has a 1 GB cap.

A file that is small compressed and enormous expanded therefore takes the
container down for every tenant, and the PHP sniffer accepts it because the
magic bytes are a genuine XLSX. `_read_xls` and `_open_pdf` are unbounded the
same way.

`defusedxml` is a declared dependency, so XXE looks covered, but nothing in
`apps/processing/src` calls it — it relies on openpyxl's auto-detection, which
was not verified against the installed version.

**Fix:** run the parse in the same limited subprocess the PDF path uses, and cap
uncompressed size and entry count before parsing.

### 14. Browser sessions are unbounded — MEDIUM/LOW

**Status:** Fixed 2026-10-20, narrower than the audit read it. Sync already reused the signed-in browser, failed sign-ins already closed theirs, and the screen hides "Sign in" while a sitting is open — but the server enforced none of that, and nothing bounded the total. Now: the worker refuses a browser past `BROWSER_MAX_SESSIONS` (default 4, sized from a measurement: idle 343 MB, +44 MB per empty Chromium) with the `503 at_capacity` it already used for a full run queue, which the app had been misreporting as "unreachable" and now shows as busy; and `InitiateSpvAuthAttempt`, under a lock on the connection, refuses a signed-in connection (`409 SPV_ALREADY_SIGNED_IN`) and closes an unfinished sign-in before opening the next. "Live session" is one scope on `SpvSession`, where the list and the sync each had their own copy. Verified live: four browsers open, the fifth is refused as busy, the worker stays healthy.

`packages/tax-romania/routes/api.php:249-251` launches a fresh Chromium per
request. Sessions idle out after 15 minutes and are renewed by use; the queue
caps concurrent *runs*, not sessions. The route has `verify-recent` but no
throttle and no per-subject cap, against a 2 GB container shared by all tenants.

**Fix:** a per-subject session cap and a throttle.

### 15. A scanner outage strands uploads permanently — LOW (fails closed)

**Status:** Fixed 2026-10-20. Confirmed: one attempt everywhere, dispatched once, and a docblock promising a retry that did not exist — though not quite "forever", since an administrator could retry the failed job from Operations. `ScanQuarantinedPart` now declares its own `tries()` and `backoff()` from `CLAMAV_RETRY_BACKOFF_SECONDS` (30, 120, 300, 600 s — five attempts over about seventeen minutes), and leaves a part already out of quarantine alone, so a retry after a later step failed cannot fail for ever looking for promoted bytes. A scanner that never answers writes nothing, so a retry duplicates nothing; an infected verdict returns rather than throws, so it is never repeated. Verified live that the queued message carries `maxTries: 5` and that backoff, overriding the supervisor's one attempt for this job only.

`packages/documents/src/Jobs/ScanQuarantinedPart.php:30-45` says "queue
retry/backoff takes over", but `apps/api/config/horizon.php` sets `tries => 1`
on every supervisor. If clamd is down the job fails once, nothing re-dispatches
it, and the document stays in `QUARANTINE` forever.

The direction is right — refusing to serve an unscanned file is correct. The
documented recovery just does not exist.

**Fix:** either a bounded retry for this job specifically, or correct the
docblock and add a requeue command.

### 16. CSV formula injection in exports — LOW/MEDIUM

**Status:** Fixed 2026-10-20, in `CsvFile::fromRows`, the one writer both exports use. A text cell starting with `=`, `+`, `-`, `@`, a tab or a carriage return is written with a leading `'`, which a spreadsheet shows as text and does not display. A cell that is only a number is left alone — `-12.50` starts with `-`, and a spreadsheet must still add it up. Tested on the writer (each formula lead, negative amounts, ordinary text) and end to end on the wallet export with a hostile counterparty and description. Found on the way: `ExportAssessmentTest` and the other TaxEngine suites never bind the in-memory key manager, so they fail in every run of `make test`, not only here — a pre-existing test-setup fault, recorded rather than fixed under this finding.

`packages/platform/src/Text/CsvFile.php` writes with `fputcsv` and neutralises
nothing. `packages/tax-romania/src/Wallet/Exports/WalletReportsCsv.php` exports
counterparty names and descriptions, which come from bank statement lines — text
a third party chose. A leading `=`, `+`, `-` or `@` is a formula to Excel when
the taxpayer or their accountant opens the file.

**Fix:** prefix those cells with a quote, or refuse the leading characters, in
`CsvFile` so every export gets it.

### 17. Changing the password does not revoke other sessions — LOW/MEDIUM

`UpdatePasswordController` writes `password_hash` and nothing else. A stolen
session outlives the password change that was meant to end it.
`DELETE /auth/sessions` exists but must be invoked separately.

**Fix:** revoke the other sessions as part of the password change.

**Status:** Fixed 2026-10-20. `UpdatePasswordController` calls `RevokeAllSessions` with the current session excepted, so the revocation is audited like the "sign out everywhere" control, and returns `revoked_count`; the account page says how many were signed out (RO/EN). Test: `SessionManagementTest` — the other session is revoked in the list and emptied in the store, the current one still works.

### 18. CNP claiming is unverified — MEDIUM

Registration is open by default and email verification defaults off
(`identity.verification.required` = false), so `verified` is effectively a no-op
as shipped. `RegisterPfSubjectController` accepts any checksum-valid CNP with no
proof of ownership, and first claimant becomes OWNER; the real person then gets
`DuplicateCnp`. `POST /api/v1/subjects/cnp` answers whether a given CNP is
already registered, at `throttle:20,1`, and accounts cost nothing to make.

Not a read of anyone's data, but for a tax product it is an identity-integrity
problem.

**Fix:** require verified email before claiming a subject, and remove or gate
the already-registered answer.

**Status:** Fixed 2026-10-20, narrower than written. Claiming was already behind the confirmation gate — it reached the package routes through `config('kern.routing.middleware')` — so with confirmation on, an unconfirmed account could not claim a CNP. What the gate missed was the application's own `routes/api.php`: four routes (`GET admin/users`, `GET settings/platform-data`, `GET documents/ai-consents`, `POST …/documents/{document}/transaction`) answered an unconfirmed account. `RequireVerifiedEmail` is now on the `web` group itself, ahead of route-model binding, and named nowhere else; its allowed set gained `csp-report`. `EmailVerificationTest` calls every route as an unconfirmed account and expects 409 except on the allowed set; against the old wiring it lists the four, plus `/`, `/login`, Horizon and broadcasting auth. Left as they are, by decision: the shipped default (registration open, confirmation off) is the installer's to change, and the "already registered" answer stays, since registering gives the same one. Proving a CNP is the claimant's own needs ANAF (SPV) and is a feature, not this fix.

### 19. Unthrottled endpoints that spend money — LOW

`GET /api/v1/legislation/search` (`packages/legislation/routes/api.php:37`) has
only `auth:web`, and each call runs an embedding request plus the hybrid pgvector
query. Also unthrottled: `documents/{id}/extractions/retry`, and the wallet
import mapping `suggest`/`suggestions` routes (unconfirmed — the grep was
truncated). Upload and enhance *are* throttled, and so are login, register, MFA,
passkey and verification.

**Fix:** a throttle on each, sized to what the backend costs.

**Status:** Fixed 2026-10-20, and smaller than written in one place, larger in another. Smaller: asking the model again for the rows was already bounded — a new round replaces the old, and a batch checks it is still the current round before it calls the model — and asking for the columns is refused while the file is being read; extraction retry was already one-at-a-time. Throttled now, each from config: asking the model again (columns and rows share one budget, 10/min per taxpayer, `STATEMENT_IMPORT_MODEL_RATE_LIMIT_*`), legislation search (30/min per account, `LEGISLATION_SEARCH_RATE_LIMIT_*`), and the administrator's provider and operation calls — AI test, model list, saving the connection, portal search, running an operation (20/min per account, `identity.rate_limits.operator_calls_per_user`). The columns ask now claims the import with one conditional `UPDATE`, as extraction retry does, so two presses at once read the file once. The limiter code had two copies (identity, documents); both now use `Kern\Platform\Routing\ConfiguredRateLimit`. Larger: **every "per taxpayer" budget was per address.** The framework sorts its throttle ahead of route middleware, so the taxpayer was not yet bound and the key fell back to the IP — including the upload limit that existed before this audit. `bootstrap/app.php` now sets the priority: signed in, confirmed address, taxpayer, throttle, route-model binding. Tests: the four limits each refuse request N+1 and leave another taxpayer or account untouched; the upload and model-ask tests fail with the ordering line removed; the two-presses test.

### 20. TOTP codes can be replayed inside the window — LOW

`packages/platform/src/Identity/Support/Totp/TotpVerifier.php:36-60` and
`CompleteMfaChallenge.php:72-76` write `last_used_at` but never compare it, so a
code stays valid for its full ±1-period skew, about 90 seconds. There is also no
per-account lockout on the challenge, and the pre-auth limiter falls back to IP
because no user is known yet. The attacker needs the password first.

**Fix:** record the consumed step and refuse it.

**Status:** Fixed 2026-10-20. `TotpVerifier::matchingStep()` says which time step a code belongs to; `CompleteMfaChallenge` keeps the step of every accepted code in `user_mfa_methods.last_used_step` (new migration) and accepts a code only through one conditional `UPDATE` that requires a later step — so a used code, an older one, or the same code sent twice at once is refused as a wrong code. It covers sign-in and step-up, which share that action; confirming enrolment does not record a step. The MFA and step-up throttles are now keyed on the account alone — the signed-in user, or before the code the account the pending login is for (`PendingMfaLogin`, which also gives the session key the one home two controllers had each written out) — where they were account-and-address, or address alone before sign-in. The cost, accepted: someone holding the password can spend the owner's five guesses a minute. Tests: a code accepted once is refused the second time, an older code after a newer one is refused, five wrong guesses from five addresses make the sixth a 429; all three fail with the fix removed. Live database migrated.

---

## Smaller items, recorded so they are not re-discovered

- **DEKs are never rotated.** `EnvelopeCipher.php:51-66` always seals at version
  1. `kern:keys:rotate-kek` rotates the wrapping key only, so the data
  ciphertext never changes and a leaked subject DEK has no remedy. Retiring an
  old KEK version needs `min_decryption_version`, which the scoped token cannot
  set — only the root token in the volume can.
  **Status:** documented 2026-10-20, not built — by decision. ADR 0002 records it as a known limit
  (and corrects its own claim that a DEK "rotates independently"); the platform README's key runbook
  says what to do if a DEK leaks before rotation exists. Retiring KEK versions staying root-only is
  recorded as deliberate. Found on the way: crypto-shredding a taxpayer (§25) has no command either.
- **The scoped vault token is one key, not one tenant.** No `context` is passed
  on wrap/unwrap (`OpenBaoTransitKeyManagementService.php:41-69`), so the token
  can unwrap any DEK it is handed. Inherent to a single-KEK design; worth not
  mis-describing. OpenBao also listens on plain HTTP (`tls_disable = true`), so
  tokens and plaintext DEKs cross `app_net` unencrypted.
  **Status:** fixed 2026-10-20. The token's scope is described as it is (ADR 0002, `DEPLOYMENT.md` §5):
  one token unwraps every DEK, by necessity. The connection is TLS on a network of its own: OpenBao is
  on `kms_net` with only `app`, `queue` and `scheduler` (Reverb, Redis, Mailpit cannot resolve it); its
  startup script issues a private CA and certificate on first boot, renews the certificate 30 days
  before expiry and reloads it with SIGHUP — no restart — and publishes the CA beside the token, which
  the client verifies against (`OPENBAO_CA_CERT`; never `verify=false`). The upstream image has no
  `openssl`, so `docker/openbao/Dockerfile` adds it. Tried on a throwaway server first (first boot,
  forced certificate renewal, forced CA renewal), then applied live: plain HTTP and an unverified
  client are refused, reverb and redis cannot reach it, user and taxpayer fields decrypt.
- **`LOG_LEVEL=debug` is the shipped default** and `MAIL_MAILER=log` is live, so
  verification links land in `storage/logs/laravel.log` on the `app_storage`
  volume, in a `single` channel that never rotates.
  **Status:** fixed 2026-10-20. Shipped defaults are `LOG_LEVEL=info` and `LOG_STACK=daily`
  (`LOG_DAILY_DAYS`, 14). The development stack sends mail to Mailpit from app, queue and scheduler
  (the queue sends queued notifications); `MAIL_MAILER=log` stays possible for an installation with no
  mail server yet, and `kern:health` now has a mail row — an SMTP server is connected to and greeted,
  log is listed as not configured with "written to the log file, not sent" (not degraded, which would
  fail the install). Live: switched to info + daily + Mailpit, a test message reached Mailpit and not the
  log; the old log was split into its 79 real lines and 2,124 lines from test runs that had written to
  it, checked to add up to the original. The live log held no mail at the time.
- **The Redis password is on a command line.** `docker/redis/entrypoint.sh` ends
  with `exec redis-server --requirepass "$REDIS_PASSWORD"`, visible in
  `/proc/<pid>/cmdline` — which is what the file's own header comment says the
  script exists to avoid.
  **Status:** fixed 2026-10-20, and the leak was elsewhere. The server's own command line was clean —
  Redis rewrites it to `redis-server *:6379` right after starting, so the password showed only for that
  moment. The healthcheck was the real one: `redis-cli -a <password>` every ten seconds, readable by any
  host account with `ps`. It now passes `REDISCLI_AUTH`; the server takes the password as configuration
  on standard input (`redis-server -`), quoted, instead of `--requirepass`. Tried on a throwaway Redis
  with a password holding a space, a quote and a backslash (right one accepted, wrong and none refused),
  then live: a host `ps` scan across three health checks found it 0 times, and found the old command
  6 times in 6. Found on the way, and fixed after asking: redis-server ran as root — the image drops to
  its `redis` user only when its own entrypoint starts the server, and the script started it directly.
  The script now hands over to that entrypoint; live, Redis runs as uid 999 with no capabilities, and
  holds the same 72 keys it held before.
- **No trusted-proxy configuration.** Behind Cloudflare (install mode 3),
  `request()->ip()` is the Cloudflare edge, so login throttles and audit IPs
  collapse onto a few addresses. Unconfirmed; the throttle code was not read.
  **Status:** fixed 2026-10-20. Confirmed for install mode 3, and the opposite risk checked absent: a
  login through the edge with forged `X-Forwarded-For` and `CF-Connecting-IP` recorded the connecting
  address, so nobody can fake theirs. Caddy's FastCGI transport fills `REMOTE_ADDR` from the connection
  even when it trusts a proxy (`fastcgi.go`, 2.11.7), so the edge now passes `env REMOTE_ADDR
  {client_ip}` — the connection's address unless a trusted proxy is configured. Mode 3 configures one:
  `trusted_proxies cloudflare` (the `caddy-cloudflare-ip` module, Cloudflare's published ranges,
  refreshed at runtime) and `client_ip_headers CF-Connecting-IP`, written by the installer. Tested live
  through the real edge: direct, forged headers ignored; Cloudflare mode with a request not from
  Cloudflare, ignored; a trusted range standing in for Cloudflare, the header believed. Not tested: a
  request actually arriving from Cloudflare, which needs a proxied zone.
- **The audit hash chain is unkeyed SHA-256** (`AuditChainService.php:86`), so
  database write access is enough to recompute it. Tamper-evident against
  outsiders only, unless the head is anchored somewhere else.
  **Status:** part A fixed 2026-10-20; part B decided after. Checking it found something larger: the
  application connected as `kern`, the Postgres image's superuser and the owner of every table — so
  one SQL injection was the whole database server (`COPY … TO PROGRAM`), and the owner can disable the
  audit trigger. **A:** two roles. The owner (`DB_OWNER_*`) creates the tables and is used only by
  `migrate`, a one-shot container (`make migrate`, the installer); the running application has its
  password blanked. The application connects as `kern_app`, created by `kern:db:grant-app-role`: no
  attributes, owns nothing, rows only, and on `audit_events` insert and select only. Live, as the
  application's connection: disabling the trigger, UPDATE, DELETE and TRUNCATE of an audit entry,
  `COPY … TO PROGRAM`, `pg_read_file`, CREATE and DROP TABLE all refused with 42501; login, the audit
  chains (4, 2105 events), queue and scheduler work. `--repair` upgrades an older installation the same
  way. **B** — keying the hash so the owner cannot recompute it either — is open, and set aside until
  the end of this list, by decision: with A, a stolen application connection can no longer rewrite
  history at all.
- **Remote services default to `http://`.** `install.sh:424` and `:459` offer
  `http://10.73.4.x` for an external vault and S3, and the live `AWS_ENDPOINT` is
  plain HTTP. Acceptable only because the VPN carries it.
  **Status:** fixed 2026-10-20, and widened by decision into TLS for every service inside the stack.
  Confirmed: the live `AWS_ENDPOINT` is `http://10.73.4.3:9000`, routed over `tailscale0`, so it is
  encrypted by the VPN today. *Installer:* the `http://10.73.4.x` suggestions and the dead
  `VAULT_ADDR='http://openbao:8200'` are gone; a remote key vault, object storage, processing plane or
  browser worker must be `https://` (asked again otherwise), and for a private CA its certificate is
  asked for and copied to `docker/tls/trusted/`; `--repair` warns about any `http://` left. *Inside the
  stack:* a new `certs` container (`docker/certs`) is the one private CA — it replaced OpenBao's own CA
  and `scripts/generate-certs.sh`, whose edge pair lasted 825 days and was never renewed — and issues
  each service a key in its own volume, owned by that service's user. OpenBao, Postgres (verify-full,
  non-TLS refused), Redis (plain port closed), the browser worker, Reverb and the edge speak TLS; each
  reloads a renewed certificate itself (`docker/tls/kern-tls.sh`), with no Docker socket anywhere.
  Every client verifies against the CA (`Kern\Platform\Http\CertificateAuthority`; never off), and
  `kern:health` has a `certificates` row. Tried on throwaway copies first, then live: each service
  refuses plain connections and an unknown CA; a forced renewal of all six was picked up by every one
  — four reloaded in place, Reverb and the edge restarted once each; `python-api` followed once the
  finding below was fixed, restarting once on its own renewal. Not done: ClamAV (no TLS of its own; stunnel, by decision later). Not tested: a real remote service with a
  private CA.
- **`python-api` runs as root, from the test stage.** Found 2026-10-20 while giving it TLS.
  `compose.services/python.yaml` builds `docker/python/Dockerfile` with no `target`, so Docker builds
  the last stage — `test`, which is `FROM base`, with pytest, ruff, pyright and pip-audit installed
  and no `USER` line. The running container is uid 0 (`/proc/1/status`), not the `appuser` the
  runtime stage creates. The browser worker had the same bug and was fixed by naming
  `target: runtime`. Capabilities are dropped, so root here cannot read files it does not own, which
  is also why it cannot be given a key owned by `appuser` until this is fixed.
  **Status:** fixed 2026-10-20. `target: runtime`; the embedding cache volume (134 MB) handed to
  `appuser` (10001). Live: uid 10001, no pytest or ruff in the image, health ok, and a real embedding
  from the queue over the service's new TLS listener.
- **Horizon's gate is the framework default** (`environment('local')`), so a dev
  stack without `APP_ENV` set serves `/horizon` with job payloads. Production
  fails closed.
  **Status:** fixed 2026-10-20. Confirmed: `compose.dev.yaml` sets `APP_ENV=${APP_ENV:-local}`, so a
  development `.env` without the line opened all 22 dashboard routes — the job lists and a POST that
  re-runs jobs — to anyone, signed out included; live is `production`, and answered 403. Nothing uses
  the dashboard: the Operations screen reads the same queues and failed jobs itself, under the
  application's permissions. So `AppServiceProvider` answers Horizon's auth question with no, for
  every request in every environment; Horizon still runs the jobs. The test runs as `local` and is
  refused signed out, signed in and as an administrator, on the dashboard and two job lists; without
  the fix the dashboard answered 200. Live: 403 on all three, also with the environment forced to
  `local`; Horizon running.
- **Per-tenant roles are not enforced anywhere.** `VIEWER` and `ACCOUNTANT`
  exist in the enum, `TenantContext::currentRole()` has no callers, and only
  `OWNER` memberships are creatable today. Latent — it becomes real the day
  sharing or invites ship.
  **Status:** fixed 2026-10-20, and it was not only latent. Confirmed: nothing read a membership's
  role — any membership opened its taxpayer to everything, and only three owner-only actions (status,
  details, picture) asked for OWNER. Live there was one VIEWER row, made 2026-10-09 for UI screenshots:
  `ui-preview@example.test` on another user's real taxpayer, never removed, so a "look only" pass could
  change and delete that taxpayer's data. The row is deleted (OWNER rows only remain), and the
  screenshot recipe in memory no longer says to make one. The rule now lives in one place:
  `MembershipRole::opening()` (OWNER, until sharing defines the others) and the `opening()` and
  `owned()` scopes on `SubjectMembership`. The tenant gate — every request and every queued job —
  refuses any other role and records `role_without_rules`; the taxpayer list, the picture, "is this CNP
  mine" and, through a new `TenantGate::tenantsFor()`, the documents package read the same rule instead
  of the table. Five hand-written owner checks became `SubjectMembership::owns()`, and an unused
  `FindSubjectByCnp::ownedBy()` was removed. Tests: ADMIN, ACCOUNTANT and VIEWER refused at the gate,
  over HTTP on a read, a write and the picture, and missing from the list.
- **Image pins are tags, not digests.** `redis:8-trixie` and
  `pgvector/pgvector:pg18-trixie` float at major. `xcaddy` pins
  `caddy-dns/cloudflare@v0.2.4` but its transitive Go dependencies resolve at
  build time, and that binary holds the DNS token and the TLS keys. The
  embedding model is fetched at runtime at `EMBEDDING_REVISION=main`.
  **Status:** images and Caddy fixed 2026-10-20; the model revision stays `main`, decided 2026-10-20. Confirmed,
  and wider: every image floated, three tags had already been rebuilt upstream since they were pulled.
  Every image in compose and the Dockerfiles is now exact tag plus digest, and `make pins`
  (`scripts/pins.sh`) lists the pins whose tag points at a newer build, changing nothing. Moved at the
  same time, each checked for security releases: OpenBao 2.6.2 → 2.6.4 (tried first on a copy of its
  volume; the live volume copied to `anaf_openbao_data_before_2_6_4` before the upgrade), Redis 8.10.1 →
  8.10.2, Mailpit v1.31.1 → v1.31.6, ClamAV's 2026-10-19 rebuild of 1.5.4, uv 0.12.10 → 0.12.24. The edge
  is built from a committed `docker/caddy/build/go.mod` and `go.sum` with `-mod=readonly` and
  `go mod verify`: the same Caddy v2.11.7 (same module hash), both modules present. Live: every service
  healthy on its new image, `kern:health` all ok, smoke passes; Python 114 and worker 60 tests pass.
  Found on the way: the installer copied an edge key of your own as root-only, which the edge (uid 10001)
  cannot read — it now hands the key to that uid, or stops and says how. The model: the name is only a
  label per vector (the cache is keyed by the text), and the cached files are commit `614241f6…`, which
  is upstream `main` today, so pinning it would re-embed nothing. Left on `main` by choice: a change
  upstream would arrive on the next download.
- **`SUPER_ADMIN` can be granted via `UpdateUserRolesController`** where
  `CreateUserController` blocks it. Unexploitable with the shipped
  `config/access.php`, because only `SUPER_ADMIN` holds `users.manage`.
  **Status:** fixed 2026-10-20. Confirmed: the roles screen had no check at all, so whoever opened it
  could grant `SUPER_ADMIN` to anyone, themselves included, and remove it from anyone but the last.
  One rule now, `Kern\Platform\Access\SuperAdministratorRole`, applied by both screens: only a super
  administrator gives or takes away the role; `users.manage` changes every other role. A refusal says
  `SUPER_ADMIN_ONLY`, shown in Romanian or English. Account creation also stopped listing the roles by
  hand and takes them from `config/access.php`. Tests give ADMIN `users.manage` and are refused on both
  screens — granting to themselves, to someone else, and removing it — while still changing other roles,
  and a super administrator can do all of it; with the rule switched off, four of them fail. The other
  `'SUPER_ADMIN'` strings stay for cleanup group 5.
- **Registration reveals whether an email exists** (422 on `email`), throttled
  10 per 5 minutes. Login and passkey options are enumeration-safe.
  **Status:** fixed 2026-10-20. Confirmed (live registration is closed, so it was latent here). Every
  valid request now gets the same 202 with an empty body, in the same time: the password is hashed in
  the request whichever address it is, and the rest is queued (`Jobs\RegisterAccount`, encrypted on the
  queue) — a new address becomes an account, a taken one is left as it was and its owner gets a mail,
  in Romanian and English (`packages/platform/lang`), at most once an hour
  (`identity.rate_limits.registration_notice_per_account`). The page says the same for both. Tests: the
  two answers are byte-identical, the existing account and its password unchanged, one notice for three
  attempts, the job encrypted and holding a hash, never the password; breaking the answer or the limit
  fails them. The administrator's create-user screen still says an address is taken: only they see it.
- **Admins see all tenants' audit metadata** by design — ids, types, hashes, no
  payloads.
  **Status:** fixed 2026-10-20. Confirmed: `audit.view`, which ADMIN holds, read every line on every
  taxpayer's chain, and beside the users screen (ids to email addresses) that showed when and what each
  person did with their taxes. Live it reached nobody but the two super administrators. Now `audit.view`
  reaches the account and system lines, and taxpayer lines need the new `audit.view.taxpayers` —
  SUPER_ADMIN's alone as shipped. One rule, `Kern\Audit\Queries\AuditReach`, read by the list, the
  summary counts and event types, and the chain check; a `subject_id` filter finds nothing rather than
  saying whether a taxpayer has a trail. A narrower reader sees one line saying why, in Romanian or
  English. Tests on Postgres: an ADMIN sees only system lines, nothing for a taxpayer asked for by id,
  and counts and chains of their own; a super administrator sees all; switching the rule off fails the
  first. Live: permission synced, both super administrators reach everything, ADMIN does not hold it.
- **No backups exist.** No target in the `Makefile` or `scripts/`, only the "back
  that volume up" line in `DEPLOYMENT.md:313`. Any `openbao_data` backup carries
  `init.json`, so a volume backup plus a database backup is a total loss.
  **Status:** confirmed 2026-10-20, and moved to a feature: `PLANNED-FEATURES.md` §2, designed together
  with where backups go and the unseal-key decision. Still open until that is built.
- **Laravel's file route `storage/{path}` is outside the app's middleware.**
  The `local` disk has `serve => true` (`config/filesystems.php:38`), so the
  framework registers `GET` and `PUT storage/{path}` with no middleware at all —
  no session, no `auth`, no confirmation gate. Laravel is meant to answer them
  only for a valid signed URL; whether that holds here, and whether anything uses
  the route at all (or `serve` can simply be `false`), is unchecked. Found while
  fixing #18.
  **Status:** fixed 2026-10-20. Confirmed, and not exploitable: Laravel answers both routes only for a
  URL signed with the application key, and nothing here signs one (no `temporaryUrl` anywhere). But the
  disk's folder holds the profile pictures, and no page or download uses the route — every file goes out
  through the application's own controllers. `serve` is now `false`, so the routes no longer exist; a
  test fails if any `storage/` route or any serving disk comes back (both fail with it switched on).
  Live: `route:list` shows no `storage/` route, and the picture and document routes still answer.

---

## Found after the list

### 21. An erased account kept its platform roles — LOW

Found 2026-10-21, listing the pentest's leftover accounts. `EraseUser` removed an account's sign-ins, second
factors and memberships and kept its roles, so an erased account still held `SUPER_ADMIN`. It could not sign
in — but it was counted as a super administrator, and the one guard on removing the last one
(`kern:access:grant --revoke`) would have counted it and not asked before the real last one went.
**Status:** fixed 2026-10-21. Erasure removes the account's roles and direct permissions; "is anyone else a
super administrator" is `SuperAdministratorRole::activeHoldersOtherThan()`, which counts active accounts only
(a suspended one cannot grant anything either); and a migration took the roles off the accounts erased
before. Tests fail with any of the three taken out. Live: one holder of `SUPER_ADMIN`, active; no erased
account holds any role.

Follow-up 2026-10-21: the roles screen (`UpdateUserRolesController`) had its own copy of the "is this the
last one?" count — a raw `User::role(…)->count()` that still counted suspended holders — so the same demotion
the CLI refused went through on the screen. It now calls `activeHoldersOtherThan()` like the CLI. A test
demotes the only active super administrator while a suspended one exists and expects 422; it returns 200 with
the raw count restored.

### 22. A taxpayer's rate budget and object keys forked on the letter case of its UUID — MEDIUM

Found 2026-10-21, re-checking item 19 under the round-2 tenancy audit. A Postgres `uuid` column compares
case-insensitively, so `MembershipTenantGate::roleFor` authorised a subject written in any letter case. But
`TenantContext` stored the raw `{subject}` route string verbatim, and that string is what
`ConfiguredRateLimit::perTenant()` keys the per-taxpayer limiter on — and what controllers hand to the
encryption AAD (`subject_id | table | field | record_uuid`) and the object-store keys. A UUIDv7 has letters,
so an owner could re-case their own subject id and mint a fresh full budget on every variant, defeating the
money-spending AI limits item 19 established; the same re-casing would seal a document under an AAD a later,
canonical read could not reproduce. Not a cross-tenant leak — a self-inflicted bypass and integrity hazard.
**Status:** fixed 2026-10-21. `BindTaxSubjectContext` — the one middleware every subject-scoped route passes
through — now rejects a non-UUID `{subject}` with a 404 (before the gate, so Postgres never raises a `22P02`
that a stranger reads as "my guess reached the database"), then lower-cases the id to its canonical form for
the bound context, the rate-limit key and the `{subject}` a controller reads back. Isolation is unchanged:
equality still matches. Tests prove the bound id, the read-back parameter and the `perTenant()` key are all
canonical from an upper-cased URL, that an upper-cased UUID still authorises, and that a non-UUID is a 404,
not a 500; each fails with its guard removed. Closes the non-UUID 500s (round-2 N6) for subject routes too.

### 23. Step-up could be bootstrapped past by a stolen session — CRITICAL

Found 2026-10-21 in a live hands-on pentest. The routes that add a login factor — `auth/mfa/totp/enroll`,
`totp/confirm`, `passkeys/registration` (options and store) and `recovery-codes/regenerate` — required only a
session, no `verify-recent`. So a stolen session cookie could: enrol its own authenticator, confirm it with a
code it generated, then satisfy `step-up` with that same factor — clearing every gate that trusts step-up
(assessment compute/finalise/unlock, SPV connect and credentials, ending other sessions) **without ever
producing the account's password**. Proven end to end over the edge: session cookie → enrol TOTP → pass
step-up → create a tax assessment that was 403 seconds before. It also lets an attacker enrol a factor on an
account that had none, which locks the real owner out of login with no reset path.
**Status:** fixed 2026-10-21. `verify-recent` now gates all factor-enrolment routes (enroll, confirm,
passkey registration options and store, recovery-code regenerate); disabling an authenticator already
required the current password, so it was left as is. `verify-recent` is answerable by password — which an
account with no factor still has and a session thief does not — so the loop closes at enrolment and no
chicken-and-egg appears. `FactorEnrollmentStepUpTest` proves each route refuses without step-up
(`PASSWORD_CONFIRMATION_REQUIRED`) and passes with it; removing the middleware fails it. Live after the fix:
a fresh session is refused `403` on enrol, passkey-options and recovery-regenerate. The MFA lifecycle tests
now step up first, which is the real client flow.

### 24. Admin people-management ran on a session alone, no step-up — HIGH

Found 2026-10-21, same pentest. The `users.manage` routes — create a user (the response returns a generated
password), change roles, change status, erase, and start impersonation — carried the permission gate but no
`verify-recent`. The most powerful acts in the installation were therefore reachable on a cookie alone: a
stolen or walked-away admin session could erase accounts, mint a fresh administrator, or impersonate any
taxpayer's owner with no second proof. The taxpayer-side sensitive routes already required step-up; the admin
side had been missed. (The browser shows an "are you sure?" box before erase, but that is an accident guard,
not identity re-proof, and a direct API call skips it entirely.)
**Status:** fixed 2026-10-21. `verify-recent` now gates create / roles / status / erase / impersonate, marked
per route (the alias's own rule — never on a group, so a read like avatar-removal is not swept in and a route
added later opts in deliberately). Because the SPA's HTTP layer already raises the "confirm it is you" dialog
from the `403` these now return, no frontend change was needed. `AdminActionsRequireStepUpTest` proves each of
the five refuses without step-up (`PASSWORD_CONFIRMATION_REQUIRED`) and that a stepped-up admin creates a user;
removing the middleware from any one fails it. Verified by test rather than live, since the live run has no
administrator password to drive step-up with.

### 25. Password change had no brute-force throttle — MEDIUM

Found 2026-10-21, same pentest, live. `PUT /me/password` requires the current password, but the check had no
rate limit: five wrong guesses in a row each answered `422` with no backoff (the step-up password path, by
contrast, throttles the same check). A stolen session cannot change the password without the current one —
but it could grind at guessing it, and a weak password recovered that way is a full, permanent takeover, not
just a session. **Status:** fixed 2026-10-21. An in-controller limiter now budgets the current-password check
per account and address (`change_password_per_user`, five attempts per five minutes), counting only failures
and clearing on a correct password — the same shape as `StepUpVerificationController`, so a fumble is still
recoverable and grinding is pointless. Tests cover the sixth attempt returning `429` and the budget being
forgiven on success; removing the throttle fails the first. Live after the fix: five wrong guesses `422`, the
sixth and beyond `429`.

### 26. A malformed `account_id` crashed the transaction endpoint with a 500 — LOW

Found 2026-10-21, same pentest. `POST .../wallet/transactions` with `account_id` as a JSON object or any
non-UUID string returned `500`, not a validation error. The field carried `['required', 'uuid', <closure>]`,
but a closure rule still runs after the `uuid` rule fails, and the closure called `FinancialAccount::find()`
on the raw value — handing Postgres a value it cannot cast to `uuid`, which raised a `22P02` that escaped
validation as a 500 before the `uuid` rule's own `422` could be returned. No data leaked (debug is off), but a
500 on shaped input is a reliability bug and noise that hides real faults. **Status:** fixed 2026-10-21. The
closure now returns early unless the value is a well-formed UUID (`is_string(...) && Str::isUuid(...)`), so a
malformed id is the `uuid` rule's `422` and the holding-kind check only ever queries a real id. Tests cover a
non-UUID string and an empty string both answering `422` with an `account_id` error. Live after the fix: both
return `422`; before, the object form returned `500` (captured in the log as `22P02 … invalid input syntax
for type uuid`).

### 27. Impersonation carried the administrator's step-up into the target's session — MEDIUM

Found 2026-10-21, both round-2 audits and confirmed in code. `ImpersonationController::start` switches the
session to the target with `establishSessionFor` (login + `session()->regenerate()`), which keeps the
session's data — including `auth.step_up_verified_at`. An administrator who had stepped up in the last 30
minutes therefore passed every step-up gate *as the target* — SPV connect, assessment compute/finalise/unlock,
session revocation — on their own earlier proof, never the target's factor. **Status (part 1):** fixed
2026-10-21. `StepUpPolicy::clear()` added, and `start` and `stop` both call it after the switch, so a proof
belongs only to the identity that made it. A test shows the session is verified after the admin steps up and
no longer verified once impersonation starts; removing the clear fails it.

Part 2 — impersonated actions record the *target* as `actor_id`, not the administrator — is **not yet fixed**.
The audit chain is an append-only, hash-chained log with a single `actor_id`; carrying the real actor through
it is a design change to that chain (a central actor/impersonator context threaded into every event, or a new
field in the hashed envelope), which `AGENTS.md` §1.20 says needs its own threat-model note. Tracked as its
own task rather than bolted onto the chain. Interim: the `ImpersonationStarted`/`ImpersonationStopped` events
bracket the session with the administrator as actor, so who was driving is reconstructable by time, if awkwardly.

### 28. Suspending an account had no super-administrator guard — MEDIUM

Found 2026-10-21, round-2 tenancy audit. `UpdateUserStatusController` checked only `users.manage`, not whether
the target was a super administrator. With the shipped config (only SUPER_ADMIN holds `users.manage`) it was
latent, but the code and `PlatformPermissionsTest` support granting `users.manage` to ADMIN — and in that
configuration an ordinary administrator could suspend every super administrator, which revokes their sessions
and locks the top role out of the installation from below it. Erasure and role changes already refuse a super
administrator target unless the actor is one; the status route had been missed. **Status:** fixed 2026-10-21.
Changing a super administrator's status now requires the actor to be a super administrator, the same rule the
other two enforce. A separate "last active super administrator" guard is deliberately not added: the only
actor this admits for a super-administrator target is another super administrator — who is active, since a
suspended account holds no session — and nobody may change their own status to suspended, so one always
remains and the zero case is unreachable (adding a guard for it would be dead code). A test shows an
administrator with `users.manage` refused (`403`) and a super administrator allowed; neutralising the guard
fails it.

### 29. A session in use could be pruned from the ledger and left unrevocable — LOW

Found 2026-10-21, round-2 auth audit, confirmed in code. `user_sessions.last_used_at` moved only at login
and session-id rotation: `SessionRegistrar::touch()` existed but was called nowhere (its only mention was a
comment in `PruneExpiredSessionsCommand`, which assumed it ran every request). The hourly prune deletes rows
idle past the session lifetime plus grace (~180 min), measured — without `touch` — from login. A session
someone kept using stayed alive (its cookie refreshes each request) while its ledger row was pruned after a
few hours: it vanished from the device list, and "sign out everywhere" and the password-change revoke, which
act only on listed rows, could no longer reach it. Suspension still ended it, via `EndInactiveAccountSession`
(which reads the account, not the ledger). **Status:** fixed 2026-10-21. A `RecordSessionActivity` middleware,
appended to the `web` group after the inactive-account check, calls `touch()` on every authenticated request,
so `last_used_at` tracks real use and the prune only removes genuinely idle sessions. A test logs in, travels
five hours, makes one authenticated request, and shows `last_used_at` advanced to the present; neutralising the
middleware fails it. `last_used_at` stays unindexed by design — a per-request write against an hourly scan.

## The passkey bug: the cause is not what STATUS.md says

`STATUS.md` records this P1 as two cache repository instances. It is not.

`apps/api/config/cache.php:64` sets `serializable_classes => false`.
`ChallengeStore` (`packages/platform/src/Identity/Support/Webauthn/ChallengeStore.php`)
caches `PublicKeyCredentialCreationOptions` and `PublicKeyCredentialRequestOptions`
objects, which come back from Redis as `__PHP_Incomplete_Class`. The `instanceof`
check then fails and `ChallengeExpiredException` is thrown — in both
`pullCreationOptions` and `pullRequestOptions`, so registration *and* login are
broken.

It fails closed: no passkey can be enrolled or used, so the whole passkey path
has never run in production. The impact is availability.

Two notes for whoever fixes it. Allow-listing the specific WebAuthn option
classes is far better than re-enabling all classes on a Redis-backed cache; and
caching a JSON form instead of an object avoids the question. Separately, once
passkeys work, passkey login skips TOTP and — until finding 2 is fixed — ignores
user status, and `userVerification` is only `preferred` in `PasskeyManager`.

---

## What the audits confirmed is sound

Recorded because it is the other half of the result, and because each line is a
control someone read rather than assumed.

**Cryptography.** XChaCha20-Poly1305 AEAD with a fresh 24-byte `random_bytes`
nonce per seal; no ECB, no static IV, nothing hand-rolled. The AAD binds
`subject_id|table|field|record_uuid`, so a row, column or subject swap fails
authentication. DEKs come from `random_bytes(32)`, only the wrapped form is
persisted, and the plaintext DEK lives in a `scoped` service torn down per
request and per job — not in logs, queue payloads, model attributes or
responses. Document objects seal each chunk with the chunk index in the AAD and
a sealed manifest, so reordering and truncation are caught. The first-use race
fails safe via `whereNull('encrypted_dek')`. Argon2id for passwords,
`hash_equals` and `compare_digest` for comparisons, no `rand`/`mt_rand` for
anything secret. Every CNP, CUI, name, address, email, description,
counterparty, filename, SPV username, TOTP secret and AI key is an
`Encrypted`-cast ciphertext column; the migrations contain no plaintext CNP,
IBAN, phone or name.

**Secrets in the repo: none, including in history.** Tracked files are
`.env.example` with empty or placeholder values, plus a root `.env` symlink to
the gitignored real file. `git log --all -p` was scanned for token, key and
private-key patterns: the only hits are documentation placeholders, an all-zero
CI `APP_KEY`, and one example key. Certificates and keys are untracked, and
`.dockerignore` keeps `.env` out of images.

**Injection.** Every `DB::raw`, `whereRaw`, `selectRaw`, `orderByRaw` and
`DB::statement` was read: none takes request input, there is no user-controlled
`orderBy` column, and `HybridLegislationSearch` uses bindings throughout. No
`exec`, `shell_exec`, `system`, `passthru`, `proc_open` or backtick exists in
`packages/*/src` or `apps/api/app`; scheduled tasks are a closed enum. No
`unserialize`. XML is parsed with `LIBXML_NONET`, no entity loading and no DTDs.
The front end is React with no `dangerouslySetInnerHTML`, and the one Blade file
has no raw echo. No path built from user input, no archive extraction. Every
paginated endpoint caps `per_page`; no `$request->all()` into a model, no
`$guarded = []`. `CnpParser::assertChecksum` is the real mod-11 algorithm with a
date round-trip and county validation, not a regex.

**Uploads and documents.** Type is decided by sniffing magic bytes — the client
MIME type is never consulted — against an allowlist of pdf, jpg, jpeg, png, csv,
xlsx, xls, with the declared extension required to match, so `.php`, `.phtml`,
`.svg` and `.htaccess` are rejected outright. Per-type size caps, a 40 M pixel
image cap, 20 files per request, 30 uploads per minute per subject, 25 MB at the
edge. Stored filenames are sanitised display metadata only; object keys are
built from UUIDs and a closed enum. The bucket is private, with no public ACL and
no presigned URLs — downloads stream through Laravel, re-resolve the model
through the tenant scope, and force the stored sniffed content type with
`nosniff`. Scanning is structural, not advisory: bytes go to a separate
quarantine bucket and content endpoints serve only scanned states; a clamd
outage, timeout or odd reply throws, and an infected verdict deletes the object
and rejects the document, with an audit row either way. Deletion removes rows,
derivatives and every stored object.

**Tenant isolation.** Every tenant route runs `auth:web` plus `tax-subject`
(`BindTaxSubjectContext` → `MembershipTenantGate::roleFor`, checking non-revoked
memberships); a non-member gets a 403 and a `security_events` row.
`TenantContext` is `scoped`. `TenantScope` throws when no tenant is bound and
stamps `tax_subject_id` on create, and it is applied across Document,
DocumentPart, FinancialAccount, FinancialTransaction, TransactionSplit, Tag,
Counterparty, Rental*, Property, Spv*, StatementImport, TaxAssessment, TaxFact,
Declaration and Deposit*. Every `findOrFail`/`find`/`whereKey` in the
tax-romania and documents controllers was checked: all scoped or explicitly
filtered. `withoutGlobalScope` appears only in jobs, console commands and
system services taking internal ids. Duplicate-document detection is
tenant-scoped, broadcast channels authorize through the membership gate, and
`CrossTenantIsolationTest` covers documents, wallet, rental, SPV, tax engine,
declarations and subjects.

**Authentication.** Constant-time login against a dummy hash; per-account-plus-IP
and per-IP throttles; session regenerated on login and invalidated on logout;
sessions revocable with the handler record destroyed. Sensitive actions require
a `verify-recent` step-up with its own budget. Verification codes are hashed and
bound to their destination with an attempt cap. Impersonation needs
`SUPER_ADMIN` plus `users.manage`, refuses super-admins and inactive targets,
and is audited. Caddy sets `REMOTE_ADDR` and Laravel trusts no proxy headers, so
`X-Forwarded-For` does not defeat the IP limits. Session cookies are `HttpOnly`
and `SameSite=lax`. The API is session-cookie only inside the `web` group, so
CSRF applies with no exclusions and no routes outside it.

**Containers.** Every service sets `read_only`, `cap_drop: ALL` and
`no-new-privileges`; caddy, php, python and playwright run non-root; no
`docker.sock`, no `privileged`, no host networking, no writable host bind mount
in the production files. `data_net` and `app_net` are `internal: true`. Postgres
uses scram, and Redis refuses to start with an empty or placeholder password.
The Python service requires a bearer token compared with `compare_digest` and
fails closed when unset.

**The edge.** `admin off`, `auto_https disable_redirects`, h1/h2 only,
`max_size 25MB`, a dotfile 403 matcher, `-Server` and `-Via`. The file root is
only `public/`, which holds one PHP file; `php_fastcgi` forwards `*.php` and
rewrites everything else to `index.php`, so the path-info trick does not apply.
`/fpm-status` is not reachable, and the Reverb publish leg is deliberately not
proxied.

**The installer.** Secrets from `openssl rand` at 192–256 bits, the admin
password over stdin, nothing secret in `argv` or echoed, `ENVIRON`-based `awk`
so values cannot inject, and a refusal to overwrite an existing `.env`.
`generate-certs.sh` writes the key 640 under `umask 077`.

---

## What was not covered

No audit ran against a live instance, so everything above is a code and
configuration read. Not examined: the Reverb broadcast payloads; whether the
`5432` firewall rule survives a reboot; whether `defusedxml` is actually active
in the installed openpyxl; the throttle internals behind the trusted-proxy
question; what else the application logs at debug level; and roughly 140 of the
~160 controllers, of which the high-ID-surface ones were spot-checked.

### 30. 404 responses named the internal model class — LOW

Found 2026-10-21, round-2 audit, confirmed live during the pentest. `findOrFail` and route-model binding
throw `ModelNotFoundException`, which the framework turns into a 404 whose body carried its default message —
the fully-qualified class, e.g. `No query results for model [Kern\TaxRomania\Wallet\Models\FinancialTransaction]
01a1…`. That handed any caller the internal namespace and module layout of a record that does not even exist;
no data leaked, but it maps the application for an attacker. **Status:** fixed 2026-10-21. The exception
handler now rewrites a model-backed 404 to a bare `{"message":"Not found."}` for JSON/API callers — matched by
the 404's previous exception being a `ModelNotFoundException`, so a deliberate 404 message elsewhere is left
alone and web navigations still fall through to the error page. A test asks for a well-formed but missing wallet
transaction and asserts the exact bare body; neutralising the handler fails it. The class and id stay in the log.
