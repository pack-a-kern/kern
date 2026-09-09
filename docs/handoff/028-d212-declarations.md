# 028 — D212 declarations module (form registry, lifecycle, snapshot, placeholder artifact)

**Milestone:** M9   **Status:** complete (machinery only — no real D212 form content; blocked on
ANAF's specification, by design)
**Date:** 2026-09-08

## What was built

`App\Domain\Declarations` (`BLUEPRINT.md` §6.10, §12) — the declaration lifecycle machinery
`docs/AGENTS.md` §1 rule 1 and `docs/reference/anaf-spv-integration.md` require this milestone to
build *without* guessing at ANAF's unpublished D212 submission format or inventing any real form
field/box number. This task builds: a versioned form registry, the
`DRAFT -> VALIDATING -> VALID -> SUBMITTED -> ACCEPTED|REJECTED` lifecycle with `ORIGINAL`/
`RECTIFICATIVE` kinds, the `§12.3` snapshot-freeze mechanism, a validator that enforces every check
this module can make without fabricating an ANAF rule — most importantly, that a `FINAL`
`tax_assessments` row (built exclusively from `APPROVED` rule/parameter versions) is required
before a declaration can become fileable — and a pluggable artifact-generation seam whose one
shipped implementation is an unmistakable placeholder, never a real filing artifact.

### Entry points

```php
use App\Domain\Declarations\Actions\{
    RegisterFormDefinition, RegisterFormVersion, CreateDeclarationDraft,
    ValidateDeclaration, SubmitDeclaration, RecordDeclarationSubmissionResponse,
    CreateRectificativeDeclaration,
};

$definition = app(RegisterFormDefinition::class)(new RegisterFormDefinitionInput('D212', 'ANAF'));
// A future task loads the real schema/applicability — this task seeds none (see "What is NOT done").
$version = app(RegisterFormVersion::class)(new RegisterFormVersionInput(
    formDefinitionId: $definition->id,
    versionCode: '2026.1',
    applicableFrom: '2026-01-01',
    taxYearFrom: 2026,
    taxYearTo: 2026,
    status: FormVersionStatus::ACTIVE,
));

$declaration = app(CreateDeclarationDraft::class)(new CreateDeclarationDraftInput(
    taxSubjectId: $subject->id,
    formVersionId: $version->id,
    fiscalYear: 2026,
    assessmentId: $assessment->id, // must be App\Domain\TaxEngine\Enums\AssessmentStatus::FINAL
));

app(ValidateDeclaration::class)($declaration); // -> VALID, freezes declarations.snapshot_json/hash
                                                // or throws DeclarationValidationFailedException
                                                // (never mutates status past DRAFT on failure)

$submission = app(SubmitDeclaration::class)(new SubmitDeclarationInput(
    declarationId: $declaration->id,
    transport: SubmissionTransport::MANUAL, // or SPV_BROWSER / ANAF_API, once M10 exists
    submittedAt: '2026-06-01T10:00:00+00:00',
));
// $declaration is now SUBMITTED and locked — every further save() to a frozen field throws.

app(RecordDeclarationSubmissionResponse::class)(new RecordDeclarationSubmissionResponseInput(
    submissionId: $submission->id,
    outcome: DeclarationStatus::ACCEPTED, // or ::REJECTED
));

$rectificative = app(CreateRectificativeDeclaration::class)(new CreateRectificativeDeclarationInput(
    originalDeclarationId: $declaration->id,
    assessmentId: $recomputedAssessment->id,
));
```

## Files created / changed

**Migrations** (`apps/api/database/migrations/2026_09_08_900000`–`900003`):
- `..._create_form_definitions_table.php` — `form_definitions` (`code` unique, `issuer`), global
  registry, not tenant-scoped.
- `..._create_form_versions_table.php` — `form_versions`: `applicable_from/until`,
  `tax_year_from/to`, `schema_object_path`, `instructions_document_version_id` (no DB-level FK —
  see "Key decisions" #2), the deliberate `schema_json` addition, `status`.
- `..._create_declarations_table.php` — `declarations`: real FKs to `tax_subjects`,
  `form_versions`, `tax_assessments`; the deliberate `snapshot_json`/`supersedes_declaration_id`
  additions; no `updated_at`. **The self-referencing `supersedes_declaration_id` FK is added via a
  second `Schema::table()` call after `Schema::create()`, not inline** — see "Key decisions" #6,
  this is a real bug this task hit and fixed, not a stylistic choice.
- `..._create_declaration_submissions_table.php` — `declaration_submissions`: encrypted
  `external_reference_ciphertext`/`response_ciphertext`, schema-level FK to `documents` for
  `receipt_document_id`.

**`App\Domain\Declarations`** (`apps/api/app/Domain/Declarations/`) — by responsibility:
- `Enums/{FormVersionStatus,DeclarationKind,DeclarationStatus,SubmissionTransport}.php`.
- `Models/{FormDefinition,FormVersion,Declaration,DeclarationSubmission}.php` — `Declaration`
  carries the model-level immutability guard (see "Immutability enforcement" below);
  `DeclarationSubmission` carries the `encryptionSubjectId()` fallback lookup (see "Key decisions"
  #7).
- `DataTransferObjects/*` — one input DTO per action, plus `AssessmentSnapshot` (the
  `tax_assessments` read-model), `ValidationIssue`/`ValidationResult`, `GeneratedArtifact`.
- `Exceptions/*` — one per named failure mode; no catch-all anywhere in this module.
- `Contracts/DeclarationArtifactGenerator.php` — the pluggable artifact seam.
- `ArtifactGeneration/PlaceholderArtifactGenerator.php` — the one, unmistakably-marked
  implementation this task ships.
- `Repositories/TaxAssessmentSnapshotReader.php` — reads `tax_assessments` via the plain query
  builder (never `App\Domain\TaxEngine\Models\TaxAssessment`).
- `Services/{FormSchemaValidator,DeclarationValidator,DeclarationSnapshotBuilder,
  DeclarationLifecycle}.php`.
- `Actions/{RegisterFormDefinition,RegisterFormVersion,CreateDeclarationDraft,ValidateDeclaration,
  SubmitDeclaration,RecordDeclarationSubmissionResponse,CreateRectificativeDeclaration}.php`.
- `DeclarationsServiceProvider.php` — binds `DeclarationArtifactGenerator ->
  PlaceholderArtifactGenerator`.

**Factories** — `FormDefinitionFactory`, `FormVersionFactory` (`apps/api/database/factories/`). No
`Declaration`/`DeclarationSubmission` factory — both always need a real, FK-valid
`tax_assessments`/`form_versions` row and are created through their own actions or `::create()`/
`::record()` directly in tests, the identical precedent `docs/handoff/025-rental-module.md` sets
for `RentalContract`.

**Tests** (`apps/api/tests/`) — 10 files, 47 tests:
- Unit (pure, no DB): `FormSchemaValidatorTest`, `PlaceholderArtifactGeneratorTest`.
- Feature (real Postgres): `CrossTenantIsolationTest`, `RawDatabasePlaintextTest`,
  `DeclarationLifecycleTransitionTest`, `DeclarationImmutabilityTest`, `ValidateDeclarationTest`
  (the `ASSESSMENT_NOT_FINAL` gate lives here), `DeclarationFilingFlowTest` (submit → response →
  rectificative, end to end), `DeclarationSnapshotBuilderTest`, `RegisterFormActionsTest`.

**Modified outside the strict path list** (small, additive, same justification pattern every prior
handoff uses):
- `apps/api/deptrac.php` — added the `Declarations` layer, `accesses($support, $subjects)` only.
- `apps/api/tests/Pest.php` — added `uses(RefreshDatabase::class)->in('Feature/Domain/Declarations')`.
- `apps/api/bootstrap/providers.php` — registered `DeclarationsServiceProvider`, the same one-line
  addition every prior module with a service provider required (`Documents`, `Fx`, `TaxKnowledge`,
  …); there is no other place to wire an interface binding for a module with no controller/route
  layer of its own yet.

No new Composer dependencies (`composer validate`/`composer audit` both re-run clean, see "How to
verify").

## Immutability enforcement (the part that matters most)

Two independent layers, deliberately not one:

1. **Action layer** — `App\Domain\Declarations\Services\DeclarationLifecycle::transition()` is the
   only way any action changes `declarations.status`; it enforces the exact matrix
   `DeclarationStatus`'s own docblock states and throws `InvalidDeclarationTransitionException` on
   anything else (`DeclarationLifecycleTransitionTest`, 9 assertions covering every legal move and
   8 illegal ones).
2. **Model layer** — `Declaration::booted()` registers a `saving` guard: once a row has been
   persisted with `locked_at` set (which `SUBMITTED` sets), any further `save()` is rejected unless
   it is *exactly* the single `SUBMITTED -> ACCEPTED|REJECTED` status flip — every other column,
   and every other status change, throws `DeclarationIsImmutableException`
   (`DeclarationImmutabilityTest`, 5 scenarios, proven by mutating a loaded model directly,
   bypassing `DeclarationLifecycle` entirely). This is `docs/AGENTS.md` rule 18 enforced as code,
   not only as a convention actions happen to follow.

## Key decisions

1. **`CreateDeclarationDraftInput::$formVersionId` is caller-supplied, never auto-resolved from
   `fiscalYear`.** Inferring "which form version applies to fiscal year X" without a verified ANAF
   applicability mapping would itself be an un-sourced decision (`docs/AGENTS.md` rule 3) — the
   identical reasoning `docs/handoff/020-tax-engine.md` "Key decisions" #1 gives for
   `TaxContext::forRules()`. `DeclarationValidator` checks the caller's choice against the
   version's own declared applicability (`FORM_VERSION_FISCAL_YEAR_NOT_APPLICABLE`); it never
   picks one itself.

2. **`form_versions.instructions_document_version_id` carries no DB-level foreign key**, unlike
   every other cross-module schema reference in this codebase. `TaxKnowledge` (which owns
   `source_document_versions`) is being actively developed by a different agent in this same wave
   per this task's own brief — binding a migration-time FK to a table under concurrent change
   risked an ordering failure this task has no way to verify against. A future task confirming
   `source_document_versions` is stable can add the constraint; the column and its intended
   meaning are unchanged either way.

3. **The safety property, `ASSESSMENT_NOT_FINAL`, is a `ValidationIssue`, not a special-cased
   exception path.** Every check `DeclarationValidator` makes (subject/fiscal-year mismatch,
   form-version applicability/status, empty totals, malformed schema, and this one) is collected
   uniformly into `ValidationResult`; `ValidateDeclaration` throws one
   `DeclarationValidationFailedException` carrying all of them. This keeps "why did validation
   fail" a single, list-shaped answer rather than the first-exception-wins shape a special path
   would produce, and it is exactly as strict for this property as for any other —
   `ValidateDeclarationTest` proves a `DRAFT` (candidate-backed) assessment can *never* produce a
   `VALID` declaration, and that the declaration is explicitly moved back to `DRAFT`, not left in
   `VALIDATING`.

4. **`App\Domain\Declarations\Repositories\TaxAssessmentSnapshotReader` reads `tax_assessments` via
   the plain query builder, never `App\Domain\TaxEngine\Models\TaxAssessment`.** This task's path
   scope is `apps/api/app/Domain/Declarations/**` only — `docs/handoff/023-taxengine-boundary-
   inversion.md`'s ports-and-adapters fix (TaxEngine defines a port, the *other* module
   implements it) requires touching both modules, which is out of scope here. This is instead the
   identical, already-sanctioned interim shape `App\Domain\Rental\Services\Matching\
   TransactionMatchCandidateFinder` establishes for reading `Domain/Wallet`'s
   `financial_transactions` table — confined to exactly this one class, and the reason `deptrac.php`
   needs no `Declarations -> TaxEngine` grant at all.
   **What this cannot do**: `tax_assessments` stores only `input_snapshot_hash` (a SHA-256 digest),
   never the underlying `tax_facts` ids. This module cannot independently recover "which facts
   backed this assessment" — see "Open questions".

5. **No D212 field-level mapping.** `CreateDeclarationDraft` and `DeclarationSnapshotBuilder` work
   at the assessment's own `tax_code -> Money` granularity (`tax_assessments.totals_json`), never
   at a named box/field level — this codebase has never seen a real D212 form, and
   `docs/AGENTS.md` rule 1 forbids inventing one. `form_versions.schema_json` models the
   *structure* a real specification would eventually populate (ordered sections/fields,
   `App\Domain\Declarations\Services\FormSchemaValidator` proves it is exercised, not dead code)
   but no fixture or factory in this codebase gives it real-looking content — every test uses
   `SYNTHETIC_*` codes, the convention `App\Domain\TaxEngine`'s own tests established first.

6. **The self-referencing `declarations.supersedes_declaration_id` FK is added via a second
   `Schema::table()` call, after `Schema::create()`, in the same migration file — a real defect
   this task hit, not a stylistic choice.** Declaring it inline inside the `Schema::create()`
   closure failed against real Postgres with `SQLSTATE[42830]: there is no unique constraint
   matching given keys for referenced table "declarations"` — the exact defect class
   `docs/AGENTS.md` §7b names ("a self-referencing FK declared inline") and the exact fix
   `source_document_versions.supersedes_version_id` already applies elsewhere in this codebase (in
   a later, separate migration there, since that column was retrofitted onto an existing table).
   Caught by running migrations against real Postgres during this task's own verification, per
   §7b's explicit warning that sqlite would have hidden it.

7. **`DeclarationSubmission::encryptionSubjectId()` has two paths, not one.** `App\Domain\
   Documents\Models\DocumentExtraction`'s precedent (an `encryptionSubjectIdOverride` property set
   only by a sanctioned static constructor) is not sufficient here: `RecordDeclarationSubmissionResponse`
   loads an existing `DeclarationSubmission` via `::query()->findOrFail()` — a normal Eloquent
   hydration that never sets the override — and then writes the encrypted `response_ciphertext`
   attribute on it. This threw `RuntimeException` in this task's own first test run (caught by
   running real Postgres + real encryption, not sqlite). Fixed by falling back to
   `Declaration::withoutGlobalScope(TaxSubjectScope::class)->where('id', $this->declaration_id)
   ->value('tax_subject_id')` when the override is unset — bypassing the tenant scope
   deliberately, since resolving *which* DEK protects a row is infrastructure, not an authorized
   business read, the same "genuinely subject-agnostic system query" category
   `TaxSubjectScope`'s own docblock names as its sanctioned escape hatch.

8. **`Declaration`'s immutability guard compares `getRawOriginal()`, never `getOriginal()` — a
   second real defect this task hit and fixed.** `getOriginal()` re-applies attribute casts
   (constructing a fresh model instance from the raw original attributes), so comparing its
   enum-cast `status` against a raw string with `===` is *always* false regardless of the actual
   value — this made the guard reject the one transition it was supposed to allow
   (`SUBMITTED -> ACCEPTED`) on every attempt. `docs/CODE-QUALITY.md` §4b's own documented object-
   comparison trap (Pint's `strict_comparison` rewriting `==`/`!=`) is a sibling bug in the same
   family: a same-looking comparison silently comparing the wrong representation. Fixed by using
   `getRawOriginal()`, which returns the uncast database value, matching `getDirty()`'s own uncast
   values exactly. Caught by `DeclarationImmutabilityTest`'s
   `'it still allows the sole sanctioned SUBMITTED -> ACCEPTED status flip'` test, which failed
   before this fix and passes after it.

9. **No `Money` value object for this module.** Every amount this module ever touches
   (`tax_assessments.totals_json`) is already `{minor_units: int, currency: string}` — produced
   and owned by `App\Domain\TaxEngine`'s own `Money`. This module reads and re-serialises that
   shape verbatim into `declarations.snapshot_json`; it never performs arithmetic on it, so
   wrapping it in a second `Money` type here would be exactly `docs/CODE-QUALITY.md` §2's
   "abstraction with no real use."

## How to verify

Per `docs/AGENTS.md` §7b, created a dedicated database (another agent's work was in flight per this
task's own brief) and ran everything against the project's own `anaf-app` image on `anaf_data_net`,
`--user root` for the same bind-mount ownership reason `docs/handoff/016-postgres-suite-
stabilisation.md`/`docs/handoff/023-taxengine-boundary-inversion.md` document. Dropped the database
after verification completed.

```text
$ docker exec anaf-postgres-1 psql -U taxos -d postgres -c 'CREATE DATABASE taxos_d212;'
CREATE DATABASE

$ docker run --rm --network anaf_data_net --user root -v "$PWD/apps/api":/app -w /app \
    --entrypoint sh anaf-app -c 'vendor/bin/pint --test app/Domain/Declarations \
    tests/Unit/Domain/Declarations tests/Feature/Domain/Declarations \
    database/factories/Form{Definition,Version}Factory.php \
    database/migrations/2026_09_08_9000{00,01,02,03}_*.php deptrac.php tests/Pest.php \
    bootstrap/providers.php'
  PASS .......................................................... 63 files

$ ... -c 'vendor/bin/pint --test'                     # whole project
  PASS .......................................................... 699 files

$ ... -c 'php -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G \
    app/Domain/Declarations tests/Unit/Domain/Declarations tests/Feature/Domain/Declarations \
    database/factories/Form{Definition,Version}Factory.php'
 [OK] No errors                                                    (level 9, no baseline)

$ ... -c 'php -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G'   # whole project
 [ERROR] Found 1 error   — tests/Feature/Domain/TaxKnowledge/Retrieval/
                            IndexSourceSectionForRetrievalTest.php:71, "Cannot access property
                            $text on IndexedChunk|null" — NOT in Domain/Declarations; a
                            concurrently-changing module per this task's own brief
                            (docs/AGENTS.md §10: recording, not silently fixing, an out-of-scope
                            bug found during whole-project verification — the same treatment
                            docs/handoff/023-taxengine-boundary-inversion.md gives an out-of-scope
                            Domain/Rental error it found the same way).

$ ... -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_DATABASE=taxos_d212 \
      -e DB_USERNAME=taxos -e DB_PASSWORD=<POSTGRES_PASSWORD> -e APP_KEY=base64:<random> \
      -c 'php artisan migrate --force'
  ... 2026_09_08_900000_create_form_definitions_table ............ DONE
  ... 2026_09_08_900001_create_form_versions_table ................ DONE
  ... 2026_09_08_900002_create_declarations_table ................. DONE   # after the self-FK fix
  ... 2026_09_08_900003_create_declaration_submissions_table ...... DONE

$ ... -c 'php artisan migrate:rollback --step=4 --force && php artisan migrate --force'
  # all four down() then up() again, clean both directions

$ ... -c 'vendor/bin/pest tests/Unit/Domain/Declarations tests/Feature/Domain/Declarations'   # run 1
  Tests:    47 passed (102 assertions)
$ ... # run 2 (leak check) — identical
  Tests:    47 passed (102 assertions)

$ ... -c 'vendor/bin/deptrac analyse --config-file=deptrac.php'
  Violations 0, Skipped violations 0, Uncovered 558, Allowed 266, Warnings 0, Errors 0

$ ... -c 'vendor/bin/pest'   # whole project, run 1
  Tests:    3 warnings, 10 skipped, 677 passed (1901 assertions)
$ ... -c 'vendor/bin/pest'   # whole project, run 2 — identical
  Tests:    3 warnings, 10 skipped, 677 passed (1901 assertions)

$ docker run --rm -v "$PWD/apps/api":/app -w /app composer:2 sh -c \
    'composer validate --no-check-publish; composer audit'
./composer.json is valid
No security vulnerability advisories found.

$ docker exec anaf-postgres-1 psql -U taxos -d postgres -c 'DROP DATABASE taxos_d212;'
DROP DATABASE
```

Two real defects were found and fixed *during* this verification, both invisible on sqlite and
both documented above (`docs/AGENTS.md` §7b's exact warning, borne out twice in one task):
the self-referencing FK ordering failure (#6) and the `getOriginal()`/enum-cast comparison bug in
the immutability guard (#8).

## Interfaces exposed to other modules

```php
use App\Domain\Declarations\Actions\{
    RegisterFormDefinition, RegisterFormVersion, CreateDeclarationDraft,
    ValidateDeclaration, SubmitDeclaration, RecordDeclarationSubmissionResponse,
    CreateRectificativeDeclaration,
};
use App\Domain\Declarations\Contracts\DeclarationArtifactGenerator;
use App\Domain\Declarations\Enums\{DeclarationKind, DeclarationStatus, FormVersionStatus, SubmissionTransport};
```

- **`App\Domain\TaxEngine` is this module's one upstream dependency** — a `tax_assessments.id`
  with `status = FINAL`. Nothing else about `TaxEngine`'s internals is assumed.
- **M10 (SPV automation) plugs in at `SubmitDeclaration`/`RecordDeclarationSubmissionResponse`.**
  Once a real transport exists, it calls `SubmitDeclaration` with `transport:
  SubmissionTransport::SPV_BROWSER` (or `ANAF_API`, per `docs/reference/anaf-spv-integration.md`'s
  note on the documented certificate-holder API) after the portal accepts the artifact, and
  `RecordDeclarationSubmissionResponse` once ANAF's outcome is known. Neither action changes.
- **Whoever obtains ANAF's real D212 specification replaces exactly one container binding**
  (`DeclarationArtifactGenerator -> PlaceholderArtifactGenerator` in
  `DeclarationsServiceProvider`) to swap in a real generator — no other class in this module
  changes. `form_versions.schema_json`/`App\Domain\Declarations\Services\FormSchemaValidator` are
  the seam for loading the real field structure once it exists.
- **`declarations.snapshot_json`/`snapshot_hash`** are the `BLUEPRINT.md` §12.3 freeze — readable by a
  future explanation/audit UI without needing to re-run anything.

## What is NOT done

- **No real D212 form content anywhere** — no field names, box numbers, section codes, or
  submission schema. By design; `docs/AGENTS.md` rule 1 and this task's own brief forbid it.
- **No real artifact generator** — `PlaceholderArtifactGenerator` produces a labelled,
  human-readable JSON dump of the frozen snapshot and nothing else. It must never be submitted to
  ANAF.
- **No object-storage write for `declarations.generated_object_path`** — the column exists
  (blueprint-literal) and stays `null`, identical to `docs/handoff/020-tax-engine.md`'s own
  `tax_assessments.calculation_trace_object_path` precedent: wiring it would require extending
  `App\Support\ObjectStore\ObjectKey`/`DocumentObjectKind` (both hard-coded to the `documents`
  shape) or inventing a second, unencrypted ad hoc writer — out of this task's path scope
  (`apps/api/app/Domain/Declarations/**` only) to decide unilaterally.
- **No substantive ANAF filing-rule validation** — duplicate-original detection, box-level
  completeness against a real schema, cross-field consistency, or any rule specific to D212 itself.
  `DeclarationValidator` checks only what this module can verify without a real specification
  (structural consistency, the `FINAL`-assessment gate, form-version applicability/status,
  non-empty totals, schema well-formedness).
- **No HTTP/API layer or controller** — this task builds the domain actions only, the same
  boundary `docs/handoff/020-tax-engine.md` drew for `TaxEngine` itself.
- **No SPV transport** — M10's job. `SubmissionTransport`/`SubmitDeclaration` are the seam.
- Did not touch `docs/STATUS.md` (explicit instruction), `app/Domain/TaxKnowledge/**`, or
  `apps/api/resources/**` (both explicitly another agent's concurrent work per this task's brief).

## Open questions

- **Should `App\Domain\TaxEngine` persist the raw `tax_facts` id list a `tax_assessments` row was
  computed from**, not only `input_snapshot_hash`? Today, `App\Domain\Declarations\
  Repositories\TaxAssessmentSnapshotReader` (and therefore this whole module's §12.3 snapshot)
  cannot independently recover "which facts backed this assessment" — it treats the hash as the
  authoritative, already-reproducible reference. Adding a join table or JSON column to
  `tax_assessments` is out of this task's path scope; flagged for whoever next touches
  `Domain/TaxEngine`.
- **Whether ANAF's own rules ever allow a `RECTIFICATIVE` to reference the *same* `assessment_id`
  as its original**, versus requiring a freshly recomputed one. `CreateRectificativeDeclaration`
  does not enforce a difference — unverified, and inventing that constraint would itself be an
  un-sourced ANAF rule this module has no authority to assert.
- **Whether `form_versions.applicable_from/applicable_until` should also gate
  `CreateDeclarationDraft`/`DeclarationValidator`** (a filing-date-aware check, as opposed to the
  fiscal-year-only check this task implements). This task received no "as of / filing date" input
  to check them against in the shapes it builds; a future task adding one should extend
  `DeclarationValidator`, not invent a default.
- **The pre-existing whole-project PHPStan error** in
  `tests/Feature/Domain/TaxKnowledge/Retrieval/IndexSourceSectionForRetrievalTest.php:71` (not
  introduced by this task, not in this task's scope to fix — see "How to verify").

## Sources consulted

- `BLUEPRINT.md` §6.10 (literal schema), §12 (D212 module, in full), §13/§13.1–§13.4 (SPV, for the
  `SubmissionTransport` shape only), §21.2 (golden tests — none apply here, no real fiscal value is
  computed by this module), §28 (hard constraints, rules 1, 6, 17, 18 directly govern this task).
- `docs/AGENTS.md` §0, §1, §2, §6, §7b (read in full; both real defects this task hit are exactly
  the class of bug §7b warns about), §9 (the `FINAL`/`APPROVED` gate this module enforces), §10.
- `docs/CODE-QUALITY.md` §4b (the `getOriginal()`/enum-cast bug this task hit is a sibling of the
  documented `strict_comparison` trap — same family: a same-looking comparison silently comparing
  the wrong representation).
- `docs/handoff/020-tax-engine.md` — read in full: `TaxContext::forRules()`'s "explicit, never
  inferred" reasoning (reused for `formVersionId`), `AssessmentStatus`/`CandidateNotAllowed
  InFinalAssessmentException` (the mechanism `ASSESSMENT_NOT_FINAL` depends on),
  `tax_assessments.totals_json`'s exact shape, `calculation_trace_object_path`'s "left
  unpopulated" precedent (reused for `generated_object_path`).
- `docs/handoff/023-taxengine-boundary-inversion.md` — read in full: the ports-and-adapters
  pattern, and why this task's narrower path scope makes the raw-query-builder shape (not a full
  port/adapter pair) the correct choice here — see "Key decisions" #4.
- `docs/handoff/025-rental-module.md` — read in full: the "no factory for a row that always needs a
  paired valid FK" precedent, the `financial_transactions` raw-query-builder precedent reused for
  `tax_assessments`, the `RentalContractStatus`-style "only the states actually used" enum
  discipline.
- `docs/reference/anaf-spv-integration.md` — read in full: no documented D212 submission API, no
  verified artifact format, the certificate-holder OAuth API noted for `SubmissionTransport::ANAF_API`.
- `docs/STATUS.md` blocked item 2 — the exact framing this task's placeholder-generator docblocks
  quote.
- `docs/handoff/016-postgres-suite-stabilisation.md`, `docs/handoff/021-wallet.md` — the
  `--user root` / dedicated-database verification recipe, reused verbatim.
- Existing code read directly before writing anything: `App\Domain\TaxEngine\{Models\
  TaxAssessment, Services\TaxAssessmentRecorder}`, `App\Domain\Rental\{Models\RentalContract,
  Actions\{AmendRentalContract,TerminateRentalContract}, Services\Matching\
  TransactionMatchCandidateFinder}`, `App\Domain\Documents\Models\DocumentExtraction`,
  `App\Domain\Subjects\Tenancy\{ScopedToTaxSubject,TaxSubjectScope,TenantContext}`,
  `App\Support\Crypto\{Casts\Encrypted,Concerns\{HasUuidPrimaryKey,FillsSubjectForeignKeyFirst}}`,
  `App\Support\ObjectStore\{ObjectKey,DocumentObjectKind,EncryptedObjectStore}`, `apps/api/
  deptrac.php`, `apps/api/tests/Pest.php`, `apps/api/bootstrap/providers.php`.
