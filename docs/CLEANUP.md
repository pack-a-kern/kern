# Cleanup — duplicated rules and dead code

Found 2026-10-20 by a read-only audit, after the same "is this user the owner?" query turned up hand-written
in five places (`docs/SECURITY-FINDINGS.md`, "Per-tenant roles"). The project rule is one rule in one
place, and no dead code; this is where the codebase does not keep it.

**Nothing here is verified yet.** Each entry is the audit's claim, to be checked before it is acted on —
one of them was already wrong (`CSP_MODE` *is* read, by `apps/api/config/csp.php`). Worked through one
group at a time, explained and agreed first, like the findings.

**Order:** groups 1 and 2 first, because they are security rules; the rest once the security list is
done.

---

## 1. Membership of a taxpayer — security

The rule lives in `SubjectMembership` (`opening()`, `owned()`, `opens()`, `owns()`) and
`MembershipRole::opening()`. Still written out elsewhere:

- `packages/tax-romania/src/Subjects/Tenancy/MembershipTenantGate.php:24-30` — the `opening()` query by
  hand, then `role->opens()` (it needs the row to say *why* it refuses; check whether a scope can serve it).
- `packages/tax-romania/src/Console/Commands/Wallet/ClassifyCounterpartiesCommand.php:104-110` —
  `whereNull('revoked_at')` with an OWNER-first ordering.
- `packages/tax-romania/src/Subjects/Queries/MembershipRoleCounts.php:29-31` — raw `DB::table`.
- `packages/platform/src/Identity/Queries/AdminUserDirectory.php:150-153` — raw `DB::table`, and the
  platform reading a table that belongs to `tax-romania`.
- `packages/platform/src/Identity/Actions/EraseUser.php:53` — deletes `subject_memberships` directly.
- Creating an owner's membership, twice: `RegisterPfSubject.php:118-126`, `RegisterPfaSubject.php:115-123`.

**Status:** fixed 2026-10-20. Verified true, all six, and one was a bug: the classify command chose any
unrevoked member, a VIEWER included, whom the gate then refused — so a taxpayer whose names nobody was
asked about looked like one with nothing to ask. Now:

- `SubjectMembership::active()` is "not revoked", once; `opening()`, `owned()`, the gate and the role
  counts start from it. The gate still reads the row, to log why it refuses.
- The classify command chooses from `opening()`, owner first.
- `SubjectMembership::grantOwnership()` writes an owner's membership, for both registrations.
- The platform no longer names the table: `Kern\Platform\Tenancy\Contracts\TenantMemberships`
  (`rolesByUser`, `removeAllFor`), answered by `SubjectMemberships` in tax-romania — the same shape as
  `TenantGate`. The users directory and `EraseUser` ask it.
- Tests: a VIEWER left alone is skipped, the directory lists only memberships in force, erasure ends
  every membership. Each fails with its rule taken out.

## 2. The security log — security

`security_events` is written by raw inserts in `AttemptPasswordLogin.php:90`, `CompletePasskeyLogin.php:59`
and `CompleteMfaChallenge.php:148` (platform), and through the `SecurityEvent` model in
`MembershipTenantGate.php` (tax-romania). One table, two packages, two ways. Should be one action.

**Status:** fixed 2026-10-20. Verified true. The table, its migration (same file name, so nothing runs
again) and the model moved to `Kern\Platform\Security`; every row is written by `RecordSecurityEvent`,
with the names in `SecurityEventType`. "No personal data in this log" was a docblock sentence kept by
four inserts; the action now refuses any context that is not short codes (an address, a sentence, a
nested value, a key that is not a code), and the tests fail with the check or any writer taken out.
Live: a failed sign-in for an unknown address wrote `LOGIN_FAILED`, no user, `{"method": "password"}`.

## 3. Reading config

Private `stringConfig` / `intConfig` / `optionalStringConfig` copies, with different behaviour (one throws
`InvalidArgumentException`, one `RuntimeException`, one returns `''`, one requires > 0):
`KeyManagementServiceProvider.php:90,101,108`, `ProbeSystemHealth.php:302,313`,
`HttpEmbeddingProvider.php:182,195`, `ProbeDataFreshness.php:197`, `ProbeSchedulerStatus.php:134`.
Laravel's `Repository::string()` / `integer()` already do most of it.

**Status:** fixed 2026-10-20. Verified true. One reader, `Kern\Platform\Config\RequiredConfig`
(`string`, `optionalString`, `positiveInt`), refusing with the setting's name; the nine copies are gone,
and with them the three numbers in the code (180, 4, 60) that stood in silently for a missing setting —
the values stay where they always were, in `config/operations.php`. An empty `services.processing.base_url`
is now refused by name instead of failing later as a bad request. The embeddings token stays optional,
as it was: missing, it answers "not configured". Tests fail with either check taken out. Live:
`kern:health` all ok, every changed class resolves.

## 4. Calling a service inside the stack

Timeout + `CertificateAuthority::verifyOption()` + token, set up separately in
`HttpTextExtractionGateway.php:122`, `HttpEmbeddingProvider.php:111`, `ProbeSystemHealth.php:254`,
`PlaybookBrowserSessionClient.php:260`, `HttpStatementParseGateway.php:71`,
`OpenBaoTransitKeyManagementService.php:207`; the processing CA resolved in three providers. One client
factory beside `CertificateAuthority`.

**Status:** fixed 2026-10-20. Verified: all six checked the certificate — no hole — but each built
the call by hand, and four read the processing service's settings on their own. Now
`Kern\Platform\Http\StackService` (`processing()`, `browserWorker()`) holds address, token and CA, and
`request()` builds the call with its time limit, the certificate check and the token where there is
one; text extraction, statement reading, embeddings, the SPV worker client and the health page use it.
OpenBao keeps its own client (its token is read from a file per call) but its CA goes through
`CertificateAuthority`. Each client still refuses a missing token in its own words, as before —
checking found that three already did. Tests fail with the certificate check or the token rule taken
out. Live: health ok for OpenBao, processing and the worker; one embedding call answered.

## 5. Platform roles

`'SUPER_ADMIN'` as a string in `ImpersonationController.php:53,59`, `EraseUserController.php:29,33`,
`CreateUserController.php:32,39`, `UpdateUserRolesController.php:32,63-64`,
`GrantPlatformRoleCommand.php:55`; `CreateUserController.php:32` repeats the role list from
`config/access.php`.

**Status:** fixed 2026-10-20. Verified true (the two in `CreateUserController` and `UpdateUserRolesController`
were already gone with the super-administrator finding). `SuperAdministratorRole::isHeldBy()` is the one
way the role is asked about — impersonation, erasure, granting — and the shell command names it through
`SuperAdministratorRole::NAME`; on the screen, `SUPER_ADMIN_ROLE` in `types/admin.ts`. Its "last super
administrator" question had no test; it has one now, and it fails with the guard taken out.

## 6. Money and dates

- Backend: a taxpayer's display currency read through the model in three places and with
  `DB::table('tax_subjects')` in `WalletQuery.php:911`; the `RON` default and the `/^[A-Z]{3}$/` check apart.
- Frontend: `formatMinor` (`types/wallet.ts:469`) beside `formatAmount` (`lib/format.ts:116`, which throws on
  a bad currency); about fifteen pages call `toLocaleString` / `Intl.NumberFormat` themselves — two with no
  locale (`audit.tsx`, `admin/jobs.tsx`). `formatDate` and `addMonths` twice
  (`lib/format.ts`, `lib/depositTerm.ts`, `components/DatePicker.tsx`).

**Status, backend (6a):** fixed 2026-10-20. Verified true, and larger than listed: `'RON'` in some twenty
places and the code check in eleven, two forms the audit missed among them. `Kern\Platform\Money\CurrencyCode`
holds `BASE` and the one check (`isValid()`, `rules()` for a form); `SettingType::BASE_CURRENCY` is gone
into it. A taxpayer's display currency is read by `TaxSubject::displayCurrency()` / `displayCurrencyOf()`,
and that found a bug the old copy shared: Postgres pads an empty `char(3)` to three spaces, which was shown
as the currency instead of lei. Trimmed now, with a test on both databases. The FX history form's
lower-case-tolerant check is left on purpose.

**Status, frontend (6b):** fixed 2026-10-21. Verified true. `lib/format.ts` holds the one money formatter —
`formatAmount`, and `formatMinor` for minor units (moved out of `types/wallet.ts`); `formatAmount` no longer
throws on an unknown currency — plus a new `formatNumber`, and `formatPercent` takes its decimals. Every
page formats numbers through it; the audit and jobs pages, which passed no language and so followed the
browser's instead of the reader's RO/EN choice, now follow it, and `noBrowserLocale.test.ts` fails if a
screen formats a number with no language again. The date picker's own `formatDate` and `addMonths` are
gone for `lib/format.ts`'s and `lib/depositTerm.ts`'s (the second clamps 31 January plus a month to
February; the picker's rolled into March, though its callers only ever passed the 1st).

## 7. Frontend helpers

`str` in 12 files, `obj` in 9, `nullableStr` in 8, `num` in 7, `nullableNum` in 4, `asRecord` / `asString`
twice — one `lib/json.ts`. `statusToneClass` (`documents/inbox.tsx:855`, `review.tsx:148`), `initialsFor`
(`lib/initials.ts`, local copy in `admin/users.tsx:80`), `applyToDocument` (`useTheme.ts`, `usePalette.ts`,
possibly parallel on purpose).

**Status:** fixed 2026-10-21. Partly true. The JSON readers were: `lib/json.ts` now holds `obj`, `str`,
`nullableStr`, `num`, `nullableNum` and `bool`, and thirteen files lost their copies; `obj` takes the strict
form (a list is not a row) that one copy had and eight did not. `statusToneClass` is shared from
`components/panel/statusTone.ts`. Not duplicates, and left: the two `initialsFor` (a taxpayer's name and an
email address are different shapes, as `lib/initials.ts` explains), the two `applyToDocument` (theme and
palette, same name only), `account.ts`'s `asRecord` (it throws on purpose) and the two `asString` (each
behaves differently). `noLocalCopies.test.ts` fails if a screen writes its own copy again. Noticed and not
changed: `Status` in `components/panel` colours its tones with literal emerald and amber, where the tables
use the `success` and `warning` tokens.

## 8. Installer

`ask_processing` and `ask_browser_worker` are one function twice, and so are their blocks in
`write_environment_file`; `ask_vault`, `ask_antivirus` and `ask_object_storage` share their prompts.

**Status:** fixed 2026-10-21. Partly true. `ask_processing` and `ask_browser_worker` were one function
twice, and so were their blocks in `write_environment_file`: now `ask_optional_service` and
`write_optional_service`, each used by both. The other three only share the helpers they already call
(`ask_https_address`, `ask_ca_file`, `ask_secret`); what they ask differs, so they stay. The installer had
no test: `make test-installer` (`scripts/tests/install-optional-services.sh`) loads it with
`KERN_INSTALL_LIBRARY=yes`, which stops it before it does anything, answers both services all three ways
— an `http://` address refused on the way — and reads back a scratch environment file, never
`apps/api/.env`. It fails with the "left out" branch broken.

## 9. Dead code

- **PHP, called by nothing:** `VerificationChannel::descriptionKey`, `UpdateStatementImportRows::directionOf`,
  `SpvAlreadySignedInException::forConnection`, `StatementFormat::hasSheets`, `SniffedFileType::hasTextLayer`,
  `SpvSession::isExpired`, `DocumentStage::isInFlight`, `TaskRunStatus::isTerminal`, `CnpParser::isValid`,
  `ExtractionSchema::keepKnown`, `StatementField::labelName`, `Reading::withCheck`.
  **Status:** removed 2026-10-21 — ten of the twelve: `descriptionKey`, `directionOf`, `hasSheets`,
  `hasTextLayer`, `isExpired`, `isInFlight`, `isTerminal`, `keepKnown`, `labelName`, `withCheck`. Not
  dead: `SpvAlreadySignedInException::forConnection` (used by the SPV sign-in since its fix), and
  `CnpParser::isValid` is used by tests only, so it is in the next list.
- **Relations nothing loads** (may be used through `with()` — check): `FormVersion::formDefinition`,
  `Declaration::supersedes`, `SpvConnection::syncRuns`, `FinancialAccount::depositInterestEvents`,
  `Property::rentalContracts`.
  **Status:** removed 2026-10-21, all five — no use by name, string (`with`, `load`, `whereHas`) or
  test; the tables and their foreign keys are untouched.
- **Used by tests only:** `TenantContext::hasActiveTenant` / `currentRole`, `SubjectDekService::forgetAll`,
  `EnvelopeCipher::keyVersionOf`, `ExtractionSchema::allFieldNames`, `NativeTextExtractionResult::joinedText`,
  `AiProviderSettingsService::replaceConnection`, `TaxContext::withRulesetId`, `Declaration::submissions`.
  **Status:** checked 2026-10-21. Not test-only: `hasActiveTenant` (the rate limiter) and
  `replaceConnection` (the AI settings screen). Removed: `CnpParser::isValid` (called by nothing at all),
  `SubjectDekService::forgetAll` (the service is `scoped`, and Laravel's worker forgets scoped instances
  after every job — `SubjectDekScopeTest` now fails if it is ever made a singleton, which would keep every
  taxpayer's decrypted key in a worker's memory), `NativeTextExtractionResult::joinedText` and
  `Declaration::submissions`. Kept, because a test that guards something real needs them:
  `EnvelopeCipher::keyVersionOf` (the only reader of the key version every envelope carries),
  `ExtractionSchema::allFieldNames` (the label-coverage test) and `TenantContext::currentRole` (sharing will
  need it). `TaxContext::withRulesetId` is not dead but unwired — see section 11.
- **Frontend exports nothing imports:** `TabRail`, `disconnectEcho`, `vLiteral`, `StreamKind`, and
  `grantDocumentAiConsent` / `revokeDocumentAiConsent` — also a second wrapper of the endpoint
  `setDocumentAiConsent` calls. Test-only: `buildRamp`, `computeTargetDimensions`, `nextWorkingDay`,
  `pageWindow`.
  **Status:** removed 2026-10-21: `TabRail` with its `.tab-rail` / `.tab-item` CSS, `disconnectEcho` (sign-out
  is a full page load, which closes the socket), `vLiteral`, the `StreamKind` type, and
  `grantDocumentAiConsent` / `revokeDocumentAiConsent` — `setDocumentAiConsent` is the one way left. The four
  "test-only" ones are not: each is used inside its own file and exported so its test can reach it.
- **About 100 translation keys** in `dictionary.ts`, `en.ts` and `ro.ts` that nothing uses by name —
  many from a removed roles and consent panel (`rolesTitle`, `consentTitle`, `enforcedTitle`, `noSubjects`,
  the registration form's labels, theme and zoom labels…). Keys can be built at runtime, so each needs
  checking.
  **Status:** removed 2026-10-21 — 180, not about a hundred, from `en.ts`, `ro.ts` and `dictionary.ts`
  alike. Found by walking every key and counting it reached by a selector down to it, a selector or helper
  that stops at a parent and indexes it, or any chain ending in its last two names; nothing builds a key
  from a string. Cut by syntax tree, then checked: exactly those 180 gone in both languages, none added,
  every kept text unchanged. Among them the four `membershipRole` names — sharing adds them back with the
  screen that shows them. `dictionaryReached.test.ts` fails on a key no screen reaches.

## 10. Python types

`pyright` (strict) reports 17 errors in `apps/processing/src/processing/embeddings/model.py`: the types
from `onnxruntime` and `huggingface_hub` are unknown to it, so every value read from them is too. Found
2026-10-20 while upgrading `uv`; the same 17 appear with the old one and the file had not changed — they
predate the upgrade.

**Status:** fixed 2026-10-21. The file now describes, as `Protocol`s, only what it uses of the three
libraries, and each enters through one `cast` to them; after that line the file is checked strictly like
the rest. One exception, to one rule on one line: `snapshot_download`'s own signature has unknown parts,
so naming it at all is reported — the comment there says why. pyright 0 errors, ruff and format clean,
114 tests; live, the rebuilt processing service loaded the real model and embedded two texts (384
dimensions, as configured).

## 11. Assessments record no ruleset

Found 2026-10-21 while checking `TaxContext::withRulesetId`. The design (`docs/handoff/020-tax-engine.md`)
has every assessment record which version of the rules computed it — `RO-PF-2026@1.0.0` — and hashes it
into the assessment's input snapshot. Only tests set it: `AssessFiscalYear` never does, so every
assessment records `''` (all five live ones). The amounts are unaffected; what is lost is being able to
say, later, which rule version produced a figure. What the ruleset id should be is a design decision,
not yet taken.

## 12. Two test lanes that had stopped running

Found 2026-10-21; fixed the same day. Not dead code, but the same kind of drift.

- `make check-packages` failed in every package (16 tests) on `RedisException`: the suites run inside
  the app container, inherited its `CACHE_STORE=redis`, and their skeleton's Redis configuration knows
  nothing of the TLS Redis has spoken since 2026-10-20. Each package's `TestCase` now uses in-memory
  cache, session and queue.
- Behind that, and in `make check-php-pg` too: both ran as the application's database role, which since
  the role split owns nothing and cannot create or drop a table. Both targets now run as the owner
  (`AS_DB_OWNER` in the `Makefile`), read from `apps/api/.env` on the host and handed to the container
  by name, never on a command line.
- Running there showed two tests that only ever passed by accident. Four SPV test files configured
  `spv.browser_worker.*`, a name the setting stopped having when it moved to `browser_worker.*` — so the
  token and address they set were never read. And the "key manager cannot be built" test removed the
  token but not the token file, which the app container mounts.

Now: `check-packages` 449 passed; `check-php-pg` 1420 passed, none failed.

Found the same day, upgrading pgvector: `make migrate` itself could not start. Booting the framework reads
the stored settings through the cache, and `migrate` had been given Postgres's TLS settings but not
Redis's (`compose.services/redis.yaml` listed `app`, `queue` and `scheduler` only) — so every migration since
Redis moved to TLS would have failed. `migrate` is in that list now.

