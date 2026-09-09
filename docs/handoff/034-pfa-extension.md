# 034 — PFA extension (Milestone 12)

**Milestone:** M12   **Status:** complete (extension mechanism proven; no PFA tax rate/rule shipped, by design)
**Date:** 2026-09-09

## What was built

This milestone is the exam `BLUEPRINT.md` §38.B sets, not a feature: prove that adding a second
`TaxSubject` type (`PFA`) needs a new profile/module, new rule wiring, and nothing else — no
rewrite of `Identity`, `Wallet`, `Documents`, `Fx`, `Spv`, `Audit`, or the security core.

Two deliverables, in the order the task asked for:

1. **The coupling audit** — `docs/reference/pf-coupling-audit.md`. Read first; it is the more
   important document. **Verdict: the architecture held.** Three real, non-blocking findings
   surfaced (a logging-redaction gap, a shared-column uniqueness-namespace risk this task avoided
   rather than repeated, and a pre-existing minor duplication), all recorded with file/line
   references and explicitly left unfixed because they sit outside this task's declared scope.
2. **The PFA vertical slice** — a new `pfa_profiles` table/model, a new `RegisterPfaSubject`
   action, and a proof (not a new production rule — none is permitted; see "Hard constraint
   observed" below) that a PFA-tagged rule resolves and runs through `App\Domain\TaxEngine`
   completely unmodified.

### Entry points

```php
use App\Domain\Subjects\Actions\RegisterPfaSubject;
use App\Domain\Subjects\DataTransferObjects\RegisterPfaSubjectInput;

$subject = app(RegisterPfaSubject::class)(new RegisterPfaSubjectInput(
    registeringUserId: $userId,
    displayName: 'Ion Popescu PFA',
    tradeName: 'Popescu Ion PFA',
    registrationNumber: 'J40/1234/2026',
    caenCode: '6201',       // optional
    countyCode: 'B',        // optional
    locality: 'București',  // optional
    address: 'Str. Exemplu nr. 1', // optional
));

// $subject->type === App\Domain\Subjects\Enums\SubjectType::PFA
```

`App\Domain\Subjects\Models\PfaProfile::query()->find($subject->id)` reads the profile back
(fields decrypt transparently via `App\Support\Crypto\Casts\Encrypted`, exactly like `PfProfile`).

For the TaxEngine side, there is **no new entry point** — that is the finding. A PFA-tagged rule
is assessed through the exact same `TaxContext`/`TaxAssessor` API `docs/handoff/020-tax-engine.md`
already documents; see `tests/Feature/Domain/TaxEngine/PfaRuleExtensionTest.php` for the full
worked example.

## Files created / changed

Migration:
- `apps/api/database/migrations/2026_09_09_200000_create_pfa_profiles_table.php` — `pfa_profiles`,
  a 1:1 extension table keyed by its owning `TaxSubject`'s own id (mirrors `pf_profiles`' shape;
  not part of the blueprint's literal §6 schema, since only `pf_profiles` is specified there — see
  the migration's own docblock for the schema decisions, especially why it does **not** reuse
  `tax_subjects.fiscal_identifier_*`).

Subjects (`apps/api/app/Domain/Subjects/`):
- `Models/PfaProfile.php` — the `pfa_profiles` Eloquent model, structurally identical to
  `PfProfile` (`FillsSubjectForeignKeyFirst`, `EncryptsWithSubjectDek`, no `HasUuidPrimaryKey`).
- `DataTransferObjects/RegisterPfaSubjectInput.php` — the PFA counterpart of
  `RegisterPfSubjectInput`.
- `Actions/RegisterPfaSubject.php` — the PFA counterpart of `RegisterPfSubject`: same two-write
  `TaxSubject` creation, same duplicate-identifier check before the transaction, same membership +
  audit event shape. The only place `SubjectType::PFA` is ever constructed.
- `Exceptions/DuplicateRegistrationNumberException.php` — the PFA counterpart of
  `DuplicateCnpException`.

Factories (`apps/api/database/factories/`):
- `TaxSubjectFactory.php` — added a `pfa()` state (`->state(['type' => SubjectType::PFA])`), used
  everywhere a test needs `TaxSubject::factory()->pfa()->create()`. No `PfaProfile` factory, for
  the identical reason `docs/handoff/011-identity-tenancy.md` gives for not giving `PfProfile` one
  (its primary key is a real `TaxSubject`'s id; there is no interesting randomised state to
  generate).

Tests (`apps/api/tests/`) — all real, all executed, see "How to verify":
- `Feature/Domain/Subjects/RegisterPfaSubjectTest.php` — the PFA mirror of
  `RegisterPfSubjectTest.php`: OWNER membership creation, a verifiable audit event, duplicate-
  registration-number rejection (tolerant of whitespace via `Normalizer::text()`), two distinct
  subjects with distinct numbers, and one extra assertion `RegisterPfSubjectTest` has no
  equivalent of — that `tax_subjects.fiscal_identifier_*` stays `NULL` for a PFA registration (the
  coupling-audit's Finding 2, made executable).
- `Feature/Domain/Subjects/PfaEncryptionAndCoexistenceTest.php` — the PFA mirror of
  `RawDatabasePlaintextTest.php` (raw-DB-dump-contains-no-plaintext, for `pfa_profiles`'
  `trade_name`/`registration_number`/`address`), a decrypt-round-trip test, and — the direct test
  of the `BLUEPRINT.md` §38.B / §33 claim — one user holding an **active PF and an active PFA
  subject at the same time**, both independently authorizable via the unmodified `TenantContext`,
  both independently audit-chain-verifiable, and a PFA subject successfully using the unmodified
  `App\Domain\Wallet\Actions\OpenFinancialAccount` action.
- `Feature/Domain/TaxEngine/PfaRuleExtensionTest.php` — the central proof: a `tax_rules` row
  tagged `subject_type = 'PFA'`, backed by `Tests\Fixtures\TaxEngine\Rules\
  SyntheticPercentageOfBaseRule` (M5's own existing fixture, reused **unchanged** — see "Key
  decisions" #1 for why no new rule class was written), resolves and assesses through
  `TaxAssessor`/`TaxAssessmentRecorder` with zero engine code changes, and a `TaxFact`
  cross-tenant-isolation test specifically between a PF subject and a PFA subject (not just two
  same-type subjects, which `CrossTenantIsolationTest.php` already covers).

Documentation:
- `docs/reference/pf-coupling-audit.md` — the audit; read it first.

**Nothing else was touched.** No change to `deptrac.php` (confirmed unnecessary — see "How to
verify"), no change to `apps/api/tests/Pest.php` (the existing `insertApprovedTaxRuleVersion`/
`insertApprovedFiscalParameterVersion` helpers and `RefreshDatabase` wiring for
`Feature/Domain/Subjects`/`Feature/Domain/TaxEngine` already covered everything this task needed),
no change to any file under `App\Domain\{Identity,Wallet,Documents,Fx,Spv,Audit,Declarations,
TaxKnowledge}` or `App\Support\**`.

## Hard constraint observed

Per the task brief and `docs/AGENTS.md` rule 3: **no tax rate, threshold, or ceiling was
introduced.** `PfaRuleExtensionTest.php`'s only numbers are a synthetic `0.05` rate under the
already-established `RO.TEST.*` naming convention (M5's own precedent), inserted via the same
`insertApprovedTaxRuleVersion`/`insertApprovedFiscalParameterVersion` test-only helpers M5 built
specifically so a test can simulate an `APPROVED` ruleset without any agent ever setting that
status through application code (`App\Domain\TaxKnowledge\Concerns\ForbidsAgentApproval` still
makes `status = APPROVED` unreachable via Eloquent). M4 (the real, human-reviewed Romanian PFA
fiscal dataset) remains entirely unstarted and unblocked by anything in this task.

## Key decisions

1. **No new `TaxRuleContract` implementation was written for PFA — the existing
   `Tests\Fixtures\TaxEngine\Rules\SyntheticPercentageOfBaseRule` (M5's own PF-context fixture) is
   reused verbatim, registered under a second, independent `tax_rules`/`tax_rule_versions` row
   tagged `subject_type = 'PFA'`.** Two reasons, both considered: first, the hard constraint above
   forbids a real PFA rate/formula, and M5's own decision #8
   (`docs/handoff/020-tax-engine.md`) already established the precedent that a generic,
   parameter-driven rule with no real caller belongs in `tests/Fixtures/`, not
   `app/Domain/TaxEngine`. Second, and more importantly for what this task is actually trying to
   prove: reusing the identical class, unmodified, is a *stronger* demonstration than writing a
   second near-identical fixture class would have been — it shows the extension point is entirely
   **data** (a `tax_rules` row, a `tax_rule_versions` row, a `fiscal_parameters` row, all pointing
   at the same `implementation_class`), not code, for the case where a new subject type's rule
   happens to need the same computational shape as an existing one. A second, cosmetically-renamed
   fixture class would have been the exact "duplication of stable... no, incidental similarity"
   case `docs/CODE-QUALITY.md` §1 says to leave alone, without adding any real proof value.
   **One concrete consequence discovered while wiring this**: `SyntheticPercentageOfBaseRule`
   hardcodes its fiscal-parameter code as the literal string `'RO.TEST.SYNTHETIC_RATE'` (not
   derived from `config_json`) — so the PFA-tagged `fiscal_parameters` row in this task's test
   necessarily uses that same code, isolated from any other test's identically-coded row by
   `RefreshDatabase`'s per-test transaction. This is fine and expected, and is called out in the
   test's own inline comment so a future reader does not mistake it for an accident.
2. **`RegisterPfaSubject` does not populate `tax_subjects.fiscal_identifier_ciphertext`/
   `fiscal_identifier_blind_index`**, unlike `RegisterPfSubject`. See
   `docs/reference/pf-coupling-audit.md` Finding 2 for the full reasoning — reusing that shared,
   globally-unique column for a second identifier *kind* (registration numbers alongside CNPs)
   was a real, if small, collision-namespace risk this task chose not to introduce. Uniqueness for
   PFA is enforced entirely by `pfa_profiles.registration_number_blind_index`'s own unique
   constraint, mirroring how `pf_profiles.cnp_blind_index` already provides PF's own uniqueness
   independently of the shared column (Finding 3 records that this makes the shared column
   redundant for PF too, pre-existing, not introduced here).
3. **Registration-number normalisation uses the existing, generic `Normalizer::text()`**, not a
   new CNP-style digit-stripping method. `Normalizer::cnp()` encodes a real, verified fact about
   CNP's shape (13 digits); a Romanian business registration number/CUI has no single verified
   shape this task could encode without risking an unverified assumption
   (`docs/AGENTS.md` §2). `Normalizer::text()` is already the established precedent for exactly
   this situation — `App\Domain\Spv\Actions\RegisterSpvConnection`'s SPV username and
   `App\Domain\Wallet\Actions\RecordFinancialTransaction`'s external id both use it for the same
   reason. No new file under `App\Support\Crypto\**` was needed or added.
4. **`pfa_profiles` mirrors `pf_profiles`' shape (self-referential primary key, no surrogate id,
   `FillsSubjectForeignKeyFirst`) rather than inventing a different extension-table pattern.**
   `docs/AGENTS.md` §10 ("prefer extending an existing pattern... write an ADR if you must
   introduce a competing one") — no ADR was written because no competing pattern was introduced.
5. **No PFA HTTP endpoint, controller, or route was added.** The task's declared scope
   (`apps/api/app/Domain/Subjects/**`, `apps/api/app/Domain/TaxEngine/**`, migrations, tests,
   factories, `deptrac.php`, `apps/api/tests/Pest.php`) does not include `apps/api/app/Http/**`,
   and `App\Http\Controllers\Api\V1\Subjects\RegisterPfSubjectController` was left untouched.
   `RegisterPfaSubject` is reachable exactly the way `RegisterPfSubject` was before its own
   controller existed — callable directly, with an HTTP wrapper left for a future task.
6. **No `PfaTaxFactNormalizer` was built.** `BLUEPRINT.md` §32 names one as an expected PFA artifact,
   but investigating what it would plug into surfaced that **no financial-event-to-`TaxFact`
   normalizer exists for PF either** (`docs/reference/pf-coupling-audit.md`'s "One caveat" section)
   — `DepositInterestEventRecorded`/`RentalAccrualRecorded` are both dispatched with a documented
   "a listener should create a TaxFact here, not built yet" contract, and neither has a listener.
   Building a PFA-labelled listener before a PF one exists would be both backwards and speculative
   (`docs/CODE-QUALITY.md` §2). Recorded as a gap for whichever task next wires that listener —
   generically, for both subject types from the start, since neither event DTO carries a
   subject-type assumption.

## How to verify

Verified against a dedicated Postgres database (`docs/AGENTS.md` §7b — another agent was active
in `apps/api/resources/js/**`, so a separate database avoided any shared-state collision):

```sh
docker exec anaf-postgres-1 psql -U taxos -d taxos -c "CREATE DATABASE taxos_pfa OWNER taxos;"
docker exec anaf-postgres-1 psql -U taxos -d taxos_pfa -c \
  "CREATE EXTENSION IF NOT EXISTS vector; CREATE EXTENSION IF NOT EXISTS unaccent; CREATE EXTENSION IF NOT EXISTS pg_trgm;"
```

Migrations, applied and reversibility-checked against the live `anaf-app-1` container (bind-mounts
`apps/api`, PHP 8.5, DB env overridden per-command):

```text
$ docker exec -e DB_DATABASE=taxos_pfa anaf-app-1 php artisan migrate:fresh --force
  ... 60 migrations, including 2026_09_09_200000_create_pfa_profiles_table ... DONE

$ docker exec -e DB_DATABASE=taxos_pfa anaf-app-1 php artisan migrate:rollback --step=1 --force
  2026_09_09_200000_create_pfa_profiles_table ... DONE
$ docker exec -e DB_DATABASE=taxos_pfa anaf-app-1 php artisan migrate --force
  2026_09_09_200000_create_pfa_profiles_table ... DONE
```

**Note on test runner**: the live `anaf-app-1` php-fpm container produced 48 unrelated failures
(all HTTP-session/CSRF 419s, in `RegisterPfSubjectControllerTest`, `RegistrationTest`,
`PasswordLoginTest`, `MfaTest`, `PasskeyTest`, `SessionManagementTest`,
`UploadDocumentControllerTest`, `DocumentAiConsentControllerTest`, `DocumentExtractionReview
ControllerTest` — zero of them in a file this task touched) when run via `docker exec`. Reproduced
in isolation against the **default** `taxos` database too, confirming it is an artifact of running
the suite against the live, long-running php-fpm container rather than anything this task changed.
Every prior handoff's own "How to verify" used a dedicated, throwaway CLI container instead
(`docs/handoff/011-identity-tenancy.md`, `docs/handoff/020-tax-engine.md`) for exactly this class
of problem; a matching prebuilt image (`taxos-php-test:local`, PHP 8.5-cli with every extension
`composer.json` requires) already existed from that prior work and was reused here:

```text
$ docker run --rm --network anaf_data_net -v apps/api:/var/www/html -w /var/www/html \
    -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_DATABASE=taxos_pfa -e DB_USERNAME=taxos \
    -e DB_PASSWORD=<redacted> taxos-php-test:local vendor/bin/pint --test
  PASS ......................................................... 779 files

$ docker run ... taxos-php-test:local php -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G
 [OK] No errors                                                            (level 9, no baseline, 776 files)

$ docker run ... taxos-php-test:local vendor/bin/deptrac analyse --config-file=deptrac.php
  Violations 0, Skipped violations 0, Uncovered 633, Allowed 327, Warnings 0, Errors 0

$ docker run ... taxos-php-test:local vendor/bin/pest    # run 1
  Tests: 3 warnings, 10 skipped, 740 passed (2234 assertions)

$ docker run ... taxos-php-test:local vendor/bin/pest    # run 2, identical database, leak check
  Tests: 3 warnings, 10 skipped, 740 passed (2234 assertions)
```

Both runs identical, 0 failed. The 10 skipped are the pre-existing live-service tests
(`OpenBaoLiveIntegrationTest`, `EncryptedObjectStoreLiveMinioTest`) that self-skip without a live
OpenBao/MinIO reachable from this throwaway container — unrelated to this task, self-skipping
correctly both times. This task's own 11 new tests (`RegisterPfaSubjectTest`: 5,
`PfaEncryptionAndCoexistenceTest`: 4, `PfaRuleExtensionTest`: 2) are all present and passing in
both runs, confirmed by name in the output.

`composer validate --strict` / `composer audit` (via a `composer:2` image, no dependency added or
changed):

```text
$ docker run --rm -v apps/api:/app -w /app composer:2 sh -c "composer validate --strict && composer audit"
./composer.json is valid
No security vulnerability advisories found.
```

## Interfaces exposed to other modules

- `App\Domain\Subjects\Actions\RegisterPfaSubject` + `DataTransferObjects\
  RegisterPfaSubjectInput` — callable from a future controller/command, mirroring
  `RegisterPfSubject`'s own current state (no controller either).
- `App\Domain\Subjects\Exceptions\DuplicateRegistrationNumberException`.
- `App\Domain\Subjects\Models\PfaProfile` — importable only from within `Subjects` per
  `deptrac.php`'s existing rules (unchanged); another module needing PFA profile data goes through
  `TenantContext`/`ListSubjectsForUser`/a future application service, never a direct model import.
- `Database\Factories\TaxSubjectFactory::pfa()` — the sanctioned way a test gets a PFA
  `TaxSubject`.
- **M4/M5 for PFA plugs in exactly where M5 already documented it plugs in for PF**: once a real,
  human-approved `tax_rule_versions`/`fiscal_parameter_versions` row exists with
  `subject_type = 'PFA'` and a real source section, nothing in `App\Domain\TaxEngine` changes.

## What is NOT done

- **No PFA HTTP endpoint/controller/route.** Out of this task's declared scope (see Key decision
  #5).
- **No `PfaTaxFactNormalizer` or any financial-event listener**, for PF or PFA (Key decision #6).
- **No real PFA tax rule, rate, or ruleset.** By design — M4 is unstarted and unblocked by this
  task; `docs/AGENTS.md` rule 3 forbids introducing one without an approved `source_section_id`.
- **No membership grant/revoke flow, no PFA-specific validation of `registrationNumber`'s shape**
  (deliberately — see Key decision #3), matching the exact same gaps `docs/handoff/
  011-identity-tenancy.md` already recorded as open for PF.
- **The three findings in `docs/reference/pf-coupling-audit.md`** (logging redaction gap, the
  shared-column namespace question, the pre-existing CNP blind-index duplication) are recorded,
  not fixed — all sit outside `app/Support/**`/`app/Http/**`, which this task's scope excludes.
- Did not touch `docs/STATUS.md` (explicit instruction) or `apps/api/resources/js/**` (another
  agent's active work, per the task brief).

## Open questions

- Should `tax_subjects.fiscal_identifier_ciphertext`/`fiscal_identifier_blind_index` be formally
  documented as PF-only, or retired in favour of every subject type owning its own scoped
  identifier table the way `pf_profiles`/`pfa_profiles` now both do? (Audit Finding 2.)
- Should `App\Support\Logging\SensitiveDataProcessor::SENSITIVE_KEYS` gain a `'registrationnumber'`/
  `'cui'` entry? (Audit Finding 1.) Recommended, not done here.
- Who builds the first `TaxFact`-creating listener for `DepositInterestEventRecorded`/
  `RentalAccrualRecorded`, and when — this predates and is independent of PFA (Key decision #6).

## Sources consulted

`BLUEPRINT.md` §2.2, §12 (Milestone 12), §32, §33, §38 (all read in full per the task brief);
`docs/AGENTS.md` §2, §6, §7b, §9, §10; `docs/CODE-QUALITY.md` §1–§2; `docs/ARCHITECTURE.md`;
`docs/adr/0004-tax-engine-deterministic.md`, `docs/adr/0010-tax-engine-ports-and-adapters.md`;
`docs/handoff/011-identity-tenancy.md`, `docs/handoff/020-tax-engine.md`,
`docs/handoff/023-taxengine-boundary-inversion.md` — all read in full. Every application file this
note or the audit cites was opened and read directly, not assumed from another document's summary.
