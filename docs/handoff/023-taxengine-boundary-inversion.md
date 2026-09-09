# 023 — TaxEngine/TaxKnowledge boundary inversion (ports and adapters)

**Milestone:** M5/M3 (cross-cutting fix)   **Status:** complete
**Date:** 2026-09-08

## What was built

Closed the one deliberate module-boundary exception recorded in
`docs/adr/0009-tax-engine-reads-tax-knowledge-models-directly.md`: `App\Domain\TaxEngine\
Repositories\{TaxRuleTemporalReader,FiscalParameterTemporalReader}` used to read `App\Domain\
TaxKnowledge\Models\{TaxRule,TaxRuleVersion,TaxRuleSource,FiscalParameter,FiscalParameterVersion}`
Eloquent models directly, backed by a layer-wide `TaxEngine -> TaxKnowledge` grant in
`deptrac.php`. `BLUEPRINT.md` §2.1 forbids a module importing another module's Eloquent models
directly; the ADR itself named the better fix and only deferred it for being out of that task's
path scope. This task's scope covers both modules, so there was no reason left to keep the
exception — the dependency is inverted instead of merely narrowed.

**No behaviour changed.** This is a structural move: the same "exactly one matching version for a
date, or a named exception — never a heuristic" / "`REJECTED`/`SUPERSEDED` excluded from the
candidate set" / "`CANDIDATE` may back `DRAFT`, never `FINAL`" semantics are intact, and every
existing test asserting them still asserts them — most were only relocated or had their
constructor call-site updated, none had an expectation changed.

### The shape

`App\Domain\TaxEngine` now defines two ports, named only in its own DTOs/exceptions/enums:

```php
interface App\Domain\TaxEngine\Contracts\TaxRuleVersionProvider {
    public function versionInForceOn(string $ruleCode, DateTimeImmutable $date): ResolvedTaxRuleVersion;
}
interface App\Domain\TaxEngine\Contracts\FiscalParameterProvider {
    public function versionInForceOn(string $parameterCode, DateTimeImmutable $date): ResolvedFiscalParameter;
}
```

`App\Domain\TaxKnowledge\Providers\{EloquentTaxRuleVersionProvider,EloquentFiscalParameterProvider}`
implement them, reading `TaxKnowledge`'s own Eloquent models (legitimate — a module reading its own
models is not the violation `BLUEPRINT.md` §2.1 names) and mapping `TaxKnowledge`'s enums to
`TaxEngine`'s at the boundary. `App\Domain\TaxKnowledge\TaxKnowledgeServiceProvider` binds both
ports to these adapters — the one place the inversion is wired.

`App\Domain\TaxEngine\Services\{RuleResolver,ParameterResolver}` now depend on the interfaces,
never the concrete adapter.

## Files created / changed

**New — TaxEngine (ports + its own enums)**
- `app/Domain/TaxEngine/Contracts/TaxRuleVersionProvider.php`
- `app/Domain/TaxEngine/Contracts/FiscalParameterProvider.php`
- `app/Domain/TaxEngine/Enums/RuleApprovalStatus.php` — `CANDIDATE|APPROVED|SUPERSEDED|REJECTED`,
  identical case names/backing values to `TaxKnowledge`'s enum of the same name, deliberately a
  separate declaration (see "Key decisions" #1).
- `app/Domain/TaxEngine/Enums/FiscalParameterValueType.php` — `MONEY|DECIMAL|INTEGER|DATE|JSON`,
  same relationship to `TaxKnowledge`'s enum.

**New — TaxKnowledge (adapters)**
- `app/Domain/TaxKnowledge/Providers/EloquentTaxRuleVersionProvider.php` — same logic as the
  deleted `TaxRuleTemporalReader`, now implementing `TaxRuleVersionProvider`, mapping
  `TaxKnowledge\Enums\RuleApprovalStatus` → `TaxEngine\Enums\RuleApprovalStatus` via
  `::from($value)`.
- `app/Domain/TaxKnowledge/Providers/EloquentFiscalParameterProvider.php` — same for
  `FiscalParameterTemporalReader`/`FiscalParameterProvider`, mapping both enums.

**Deleted**
- `app/Domain/TaxEngine/Repositories/TaxRuleTemporalReader.php`
- `app/Domain/TaxEngine/Repositories/FiscalParameterTemporalReader.php`

**Modified**
- `app/Domain/TaxEngine/DataTransferObjects/ResolvedTaxRuleVersion.php` — `$status` now typed to
  `App\Domain\TaxEngine\Enums\RuleApprovalStatus`, not `TaxKnowledge`'s.
- `app/Domain/TaxEngine/DataTransferObjects/ResolvedFiscalParameter.php` — `$valueType`/`$status`
  likewise switched to `TaxEngine`'s own enums.
- `app/Domain/TaxEngine/Services/RuleResolver.php` — constructor now takes `TaxRuleVersionProvider`
  (interface), not `TaxRuleTemporalReader` (concrete class); enum import switched.
- `app/Domain/TaxEngine/Services/ParameterResolver.php` — same change for `FiscalParameterProvider`.
- `app/Domain/TaxEngine/Contracts/TaxRuleContract.php`,
  `app/Domain/TaxEngine/Attributes/TaxRule.php`,
  `database/migrations/2026_09_08_302002_create_tax_assessment_lines_table.php` — docblock-only
  updates: stale references to the deleted reader classes replaced with references to the new
  ports/adapters.
- `app/Domain/TaxKnowledge/TaxKnowledgeServiceProvider.php` — binds
  `TaxRuleVersionProvider -> EloquentTaxRuleVersionProvider` and
  `FiscalParameterProvider -> EloquentFiscalParameterProvider` (both `bind()`, matching the
  adapters' stateless, cheap-to-construct lifecycle — same reasoning already documented there for
  `AllowlistedHttpFetcher`).
- `deptrac.php` — removed `TaxKnowledge` from `Ruleset::forLayer($taxEngine)->accesses(...)`;
  added a new `Ruleset::forLayer($taxKnowledge)->accesses($support, $taxEngine)`. No cycle: the
  edge exists in one direction only.
- `phpstan.neon` — see "Also fixed" below.
- `docs/adr/0009-tax-engine-reads-tax-knowledge-models-directly.md` — marked superseded, not
  deleted (`docs/AGENTS.md` §5 — superseded ADRs stay for the record).
- `docs/adr/0010-tax-engine-ports-and-adapters.md` — new; records the inversion and why §2.1 was
  upheld rather than excepted.

**Tests**
- New (relocated, same assertions): `tests/Feature/Domain/TaxKnowledge/Providers/
  EloquentTaxRuleVersionProviderTest.php`, `EloquentFiscalParameterProviderTest.php` — replace
  `tests/Feature/Domain/TaxEngine/{TaxRuleTemporalReaderTest,FiscalParameterTemporalReaderTest}.php`
  (deleted), same `it()` blocks, only the class under test and its `use` import changed.
- Modified — mechanical constructor/import updates only, no assertion changed:
  `tests/Feature/Domain/TaxEngine/RuleResolverTest.php`,
  `tests/Feature/Domain/TaxEngine/ParameterResolverTest.php` (both now construct the real
  `Eloquent*Provider` adapter and import `TaxEngine`'s own `RuleApprovalStatus` for the DTO
  assertion, aliasing `TaxKnowledge`'s as `TaxKnowledgeRuleApprovalStatus` for the model-factory
  calls that still legitimately need it),
  `tests/Feature/Domain/TaxEngine/TaxAssessorTest.php` (`makeTaxAssessor()` now wires the two
  adapters instead of the two deleted readers),
  `tests/Feature/Domain/TaxEngine/RuleInvokerTest.php`,
  `tests/Unit/Domain/TaxEngine/ResolvedFiscalParameterTest.php` (both switched fully to
  `TaxEngine`'s own enums — they construct the DTO directly and never touch a `TaxKnowledge`
  model, so no alias was needed).

## Key decisions

1. **Two new, TaxEngine-owned enums (`RuleApprovalStatus`, `FiscalParameterValueType`) duplicate
   `TaxKnowledge`'s enums of the same name, case-for-case.** This is deliberate, not an oversight
   the task brief anticipated explicitly ("expressed purely in terms of TaxEngine's own DTOs...
   and scalars/dates. No Eloquent, no TaxKnowledge types in the signatures"). A port whose DTO
   still names a `TaxKnowledge` enum would leak the boundary this task closes, just through an
   enum instead of a model — deptrac cannot tell "just an enum" from "a model" at the layer level,
   and neither should the design. The two adapters are the only code that maps between them
   (`EngineEnum::from($tkEnum->value)`), which throws `ValueError` if the two ever drift out of
   sync — an acceptable, self-detecting cost recorded in ADR 0010's "Consequences".
2. **The binding lives in `TaxKnowledgeServiceProvider`, not a new `TaxEngineServiceProvider`.**
   `App\Domain\TaxEngine` has no service provider today (every class the container resolves has
   only zero-arg or class-typed constructor dependencies it can already satisfy) — adding one
   solely to bind an interface whose only implementation lives in another module would itself
   require that new provider to import `TaxKnowledge`, defeating the inversion. Binding inside
   `TaxKnowledgeServiceProvider` (which now legitimately depends on `TaxEngine`) is both simpler
   and consistent with the direction of the dependency.
3. **`RuleResolverTest`/`ParameterResolverTest` keep testing against the real `Eloquent*Provider`
   adapter (a feature test against real Postgres), not a hand-written test double.** The task's
   "preserve exactly" instruction covers the temporal-resolution and draft/final-gate semantics
   these tests already proved; switching to a double would prove a *different*, weaker thing (that
   `RuleResolver` calls whatever it's given, not that the real resolution behaves correctly).
   `docs/adr/0010-tax-engine-ports-and-adapters.md`'s "Consequences" section notes the seam now
   exists for a pure unit test later, but exploiting it wasn't part of this task.
4. **The pre-existing PHPStan error `docs/handoff/022-document-pipeline.md` reported
   (`tests/Fixtures/TaxEngine/Purity/ImpureEnvironmentRule.php:15`, Larastan's "no `env()` outside
   config" rule) is fixed by excluding that one file in `phpstan.neon`, not a baseline entry or an
   `@phpstan-ignore` comment.** The file's entire purpose is to contain a real, lexically-scannable
   `env()` call — `Tests\Support\TaxEngineRulePurity\RulePuritySourceScanner` scans its raw text to
   prove it catches an environment read; the file is never `require`d or executed by anything
   except that scan and `RulePurityArchitectureTest`'s dynamic class discovery (which does
   `require_once` every file under `app/Domain/TaxEngine`, not `tests/Fixtures/**`). A baseline
   entry would suppress this specific error by fingerprint while leaving the door open for a
   genuine new violation elsewhere to slip in unnoticed; excluding the one deliberately-impure
   fixture file is the same treatment any other test suite gives a fixture with deliberately
   malformed content. No other fixture in that directory (`ImpureDatabaseRule`, `ImpureNowRule`,
   `ImpureNoArgumentDateTimeRule`, `ImpureRandomnessRule`, `CleanRule`) trips any PHPStan rule —
   confirmed by running the whole-project analysis before and after this exclusion.

## How to verify

Verified against the project's own `anaf-app` image, real Postgres (`anaf_data_net`), following
`docs/handoff/016-postgres-suite-stabilisation.md`/`docs/handoff/015-m1-integration-repairs.md`'s
`docker run --network anaf_data_net --user root ...` recipe. `--user root` is required because the
image's default user (`appuser`, uid 10001, set by `docker/php/Dockerfile`) cannot write to
`storage/`/`bootstrap/cache/` on the bind-mounted `apps/api` tree, which this sandbox's host side
owns as `root` — without it, Laravel's own bootstrap (log file, compiled config/route caches) fails
before a single test runs.

```text
$ docker run --rm --network anaf_data_net --user root \
    -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_PORT=5432 \
    -e DB_DATABASE=taxos -e DB_USERNAME=taxos -e DB_PASSWORD=<POSTGRES_PASSWORD from root .env> \
    -e APP_KEY=base64:<random> \
    -v "$PWD/apps/api":/app -w /app --entrypoint sh anaf-app -c 'vendor/bin/pint --test'
  PASS .......................................................... 592 files

$ ... -c 'php -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G \
    app/Domain/TaxEngine app/Domain/TaxKnowledge tests/Unit/Domain/TaxEngine \
    tests/Feature/Domain/TaxEngine tests/Feature/Domain/TaxKnowledge tests/Fixtures/TaxEngine \
    database/factories deptrac.php'
 [OK] No errors                                                    (level 9, no baseline)

$ ... -c 'vendor/bin/pest tests/Unit/Domain/TaxEngine tests/Feature/Domain/TaxEngine tests/Feature/Domain/TaxKnowledge'
  Tests:    1 skipped, 125 passed (272 assertions)
$ # run 2 (leak check) — identical
  Tests:    1 skipped, 125 passed (272 assertions)

$ ... -c 'vendor/bin/deptrac analyse --config-file=deptrac.php'
  Violations 0, Skipped violations 0, Uncovered 446, Allowed 224, Warnings 0, Errors 0

$ ... -c 'vendor/bin/pest'   # whole project, run 1
  Tests:    3 warnings, 10 skipped, 552 passed (1502 assertions)
$ ... -c 'vendor/bin/pest'   # whole project, run 2 — identical
  Tests:    3 warnings, 10 skipped, 552 passed (1502 assertions)
```

**Whole-project PHPStan** (`vendor/bin/phpstan analyse --memory-limit=1G`, no path filter) reports
**6 errors, all in `app/Domain/Rental/**`** (a concurrently in-flight module — files there carry
today's date and are not part of this task's scope, per the task brief's boundary list). None are
in `app/Domain/TaxEngine` or `app/Domain/TaxKnowledge` — the scoped run above, covering exactly
this task's files plus their tests, is clean. Not fixed here; flagged for whoever owns
`Domain/Rental` (`docs/AGENTS.md` §10 — recording rather than silently fixing an out-of-scope bug;
`docs/STATUS.md` was explicitly out of this task's scope to edit, so it is recorded here instead).

`composer validate`/`composer audit` were not run — no Composer binary in this runtime image (same
situation as `docs/handoff/012-encrypted-object-store.md`, `docs/handoff/014-source-registry-bnr.md`,
`docs/handoff/021-wallet.md`), and no dependency changed, so there is nothing new to validate/audit.

## Interfaces exposed to other modules

```php
use App\Domain\TaxEngine\Contracts\{TaxRuleVersionProvider, FiscalParameterProvider};
use App\Domain\TaxEngine\Enums\{RuleApprovalStatus, FiscalParameterValueType};

// Resolved automatically via the container — TaxKnowledgeServiceProvider binds both:
$version = app(TaxRuleVersionProvider::class)->versionInForceOn('RO.PF.SOME_RULE', $date);
$parameter = app(FiscalParameterProvider::class)->versionInForceOn('RO.PF.SOME_PARAM', $date);
```

`App\Domain\TaxEngine\Services\{RuleResolver,ParameterResolver,TaxAssessor}`'s own public
interface is unchanged from `docs/handoff/020-tax-engine.md` — this task did not touch
`TaxContext`, `TaxAssessor::assess()`, `TaxAssessmentRecorder`, `RuleInvoker`, `Money`,
`FxConversionPolicy`, or any model. A future module needing to read `TaxKnowledge`'s
ruleset/parameter store should depend on these two ports, never on `TaxKnowledge` directly.

## What is NOT done

- `App\Domain\Rental`'s 6 PHPStan errors — out of scope, flagged above, not this task's module.
- No new unit test exploiting the fact that `RuleResolver`/`ParameterResolver` can now be tested
  with a hand-written `TaxRuleVersionProvider`/`FiscalParameterProvider` double instead of the real
  adapter — the existing feature tests already prove the real behaviour; adding a pure-unit
  duplicate would test the same thing a second way for no stated need (`docs/CODE-QUALITY.md` §1).
- Did not touch `Domain/Wallet`, `Domain/Documents`, `Domain/Rental`, `apps/processing`, or
  `docs/STATUS.md`, per the task's explicit boundary.

## Open questions

None outstanding for this task's own scope. `docs/adr/0010-tax-engine-ports-and-adapters.md`
records the alternatives considered and rejected.

## Sources consulted

- `BLUEPRINT.md` §2.1 (the rule this task restores full compliance with).
- `docs/AGENTS.md` §6 (module boundary / deptrac enforcement), §5 (handoff note template), §10
  (record rather than silently fix an out-of-scope bug — governs the `Domain/Rental` note above).
- `docs/CODE-QUALITY.md` §1 (the stable-duplication judgement behind the two mirrored enums).
- `docs/adr/0009-tax-engine-reads-tax-knowledge-models-directly.md` — the exception closed here,
  including its own "Alternatives considered", which named this exact direction as the likely
  better long-term shape.
- `docs/handoff/020-tax-engine.md`, `docs/handoff/018-legislation-ingestion.md` — read in full for
  the original implementations and the exact temporal-resolution contract being preserved.
- `docs/handoff/016-postgres-suite-stabilisation.md`, `docs/handoff/015-m1-integration-repairs.md`,
  `docs/handoff/021-wallet.md` — the real-Postgres verification recipe and its `--user root`
  rationale, reused verbatim.
- `docs/handoff/022-document-pipeline.md` — named the pre-existing PHPStan error fixed here.
- Existing code read directly before changing it: `App\Domain\TaxEngine\Repositories\
  {TaxRuleTemporalReader,FiscalParameterTemporalReader}` (the classes replaced),
  `App\Domain\TaxEngine\Services\{RuleResolver,ParameterResolver,TaxAssessor}`,
  `App\Domain\TaxKnowledge\TaxKnowledgeServiceProvider`, `apps/api/deptrac.php`,
  `apps/api/phpstan.neon`, every test file this task touched.
