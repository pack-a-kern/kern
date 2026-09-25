# 020 — Tax Engine (deterministic assessment machinery)

**Milestone:** M5   **Status:** complete (machinery only — no fiscal numbers; blocked on M4 for real data, by design)
**Date:** 2026-09-08

## What was built

`Kern\TaxRomania\TaxEngine` — the deterministic, pure computation core `BLUEPRINT.md` §11 and ADR 0004
describe. This task builds **only the machinery**: `Money`, `TaxFact`, FX conversion policy, rule
resolution with the `CANDIDATE`/`APPROVED` draft-vs-final gate, the assessment orchestrator, and
`CalculationTrace`. **No tax rate, threshold, or ceiling is introduced anywhere in this codebase**
(`docs/AGENTS.md` rule 3) — M4 (the approved historical dataset) is still blocked on human review,
exactly as the brief states. Every numeric example in every test is clearly synthetic
(`RO.TEST.*` codes, `SYNTHETIC_*` labels), matching the convention `Kern\Legislation`'s own
factories already established (`TaxRuleFactory`/`FiscalParameterFactory`).

### Entry points

```php
use Kern\TaxRomania\TaxEngine\TaxContext;
use Kern\TaxRomania\TaxEngine\Services\TaxAssessor;
use Kern\TaxRomania\TaxEngine\Repositories\TaxFactRepository;

$facts = (new TaxFactRepository())->snapshotsFor($taxSubjectId, $factIds);

$context = TaxContext::forSubject($taxSubjectId)
    ->fiscalYear(2026)
    ->asOf($asOfDate)
    ->forRules(['RO.PF.SOME_RULE_CODE'])   // explicit — see "Key decisions" #1
    ->withFacts($facts)
    ->withRulesetId('RO-PF-2026@1.0.0')
    ->final();                              // or ->draft() (the default)

$result = app(TaxAssessor::class)->assess($context); // TaxAssessmentResult

// Persist only when you actually want a durable row:
$assessment = app(\Kern\TaxRomania\TaxEngine\Services\TaxAssessmentRecorder::class)->record($result);
```

`$result->lines[$i]->trace->toArray()` is the `CalculationTrace` — rule code, rule version,
inputs, steps, source refs, result — exactly `BLUEPRINT.md` §11.4's shape, JSON-serialisable, and
persisted verbatim to `tax_assessment_lines.calculation_trace_json`.

## Files created

**`Kern\TaxRomania\TaxEngine`** (`apps/api/packages/tax-romania/src/TaxEngine/`) — full tree in the previous tool
output; summarised by responsibility:

- `ValueObjects/Money.php` — integer minor units + explicit currency + explicit decimal-place
  scale, BCMath-only arithmetic (`add`/`subtract`/`compareTo`/`multipliedByDecimal`/`convertedTo`),
  explicit `Enums/RoundingMode` on every method that can produce a fraction. No `float` anywhere
  in its type signatures (tested via reflection, not a source-text grep — see "Key decisions" #5).
- `Models/{TaxFact,TaxAssessment,TaxAssessmentLine}.php` + three migrations
  (`2026_09_08_302000..302002`) — `BLUEPRINT.md` §6.7, with documented, justified deviations (see
  each migration's own docblock and "Key decisions" #2 below).
- `Repositories/{TaxRuleTemporalReader,FiscalParameterTemporalReader}.php` — the *only* two
  classes in this module that read `Kern\Legislation` Eloquent models directly (see "Key
  decisions" #3). Mirror `Kern\Legislation\Services\LegislationTemporalQuery`'s exact
  contract: exactly one matching version for a date, or a named exception — never a heuristic.
  `REJECTED`/`SUPERSEDED` versions are excluded from the candidate set entirely.
- `Repositories/TaxFactRepository.php` — `TaxFact` row → `TaxFactSnapshot` DTO, explicitly scoped
  by a caller-supplied `tax_subject_id` (not `TenantContext`-dependent — see "Key decisions" #4).
- `Services/RuleResolver.php` / `ParameterResolver.php` — resolve-then-gate: `CANDIDATE` is usable
  only in a `DRAFT` assessment, `APPROVED` in either, `REJECTED`/`SUPERSEDED` in neither
  (`docs/AGENTS.md` §9, `CandidateNotAllowedInFinalAssessmentException`).
- `Services/RuleInvoker.php` — `new $implementationClass()` (never through the container — see
  `TaxRuleContract`'s own docblock) then `evaluate($input)`.
- `Services/FxConversionPolicy.php` — `convertToRon(Money, FxConversionMethod, DateTimeImmutable,
  RoundingMode, sourceCode = 'BNR'): FxConversionResult`. All four methods from `BLUEPRINT.md` §6.9
  (`SPOT`, `PREVIOUS_BANKING_DAY`, `MONTHLY_AVERAGE`, `ANNUAL_AVERAGE`) implemented; method is
  always an explicit caller-supplied argument, never a default this policy picks. Built entirely
  on `Kern\TaxRomania\Fx\Services\FxRateQuery` (see "Key decisions" #6 for why the average methods are
  computed here rather than via `Kern\TaxRomania\Fx\Services\FxPeriodAverageCalculator`).
- `Services/TaxAssessor.php` — orchestrates `RuleResolver`→`ParameterResolver`→`RuleInvoker` per
  rule code in `TaxContext::$ruleCodes`, builds `TaxAssessmentResult` (lines, obligations grouped
  by `tax_code`, totals, a SHA-256 `inputSnapshotHash` over subject/year/as-of/ruleset/fact
  ids/resolved version ids — the `BLUEPRINT.md` §39 reproducibility proof).
- `Services/TaxAssessmentRecorder.php` — `TaxAssessmentResult` → `tax_assessments` +
  `tax_assessment_lines` rows, one DB transaction.
- `TaxContext.php` — the `BLUEPRINT.md` §11.1 builder (`forSubject()->fiscalYear()->asOf()->
  withFacts()`), plus `forRules()` (see "Key decisions" #1) and `draft()`/`final()`.
- `Contracts/TaxRuleContract.php`, `Attributes/TaxRule.php` — the interface every rule
  implementation conforms to, and the `BLUEPRINT.md` §20 machine-readable metadata attribute.
- 17 exception classes, one per named failure mode — no catch-all handler anywhere in this module.

**Factories** — `TaxFactFactory`, `TaxAssessmentFactory`, `TaxAssessmentLineFactory`
(`apps/api/database/factories/`).

**Tests** (`apps/api/tests/`) — 25 files:
- Unit (pure, no DB/HTTP/clock): `MoneyTest`, `TaxContextTest`, `ResolvedFiscalParameterTest`,
  `CalculationTraceTest`, `RulePuritySourceScannerTest`.
- Feature (real Postgres): `TaxRuleTemporalReaderTest`, `FiscalParameterTemporalReaderTest`,
  `RuleResolverTest`, `ParameterResolverTest`, `RuleInvokerTest`, `FxConversionPolicyTest`,
  `TaxAssessorTest`, `TaxAssessmentRecorderTest`, `TaxFactRepositoryTest`,
  `CrossTenantIsolationTest`, `RulePurityArchitectureTest`.
- `tests/Support/TaxEngineRulePurity/RulePuritySourceScanner.php` — the purity-enforcement tool
  (test-only code, see "Key decisions" #7).
- `tests/Fixtures/TaxEngine/Rules/SyntheticPercentageOfBaseRule.php` — the one concrete
  `TaxRuleContract` implementation in this whole task, entirely parameter-driven, used by
  `TaxAssessorTest`/`RuleInvokerTest`/the purity scanner's own "does it correctly pass clean code"
  proof. **Not shipped under `packages/tax-romania/src/TaxEngine`** — see "Key decisions" #8.
- `tests/Fixtures/TaxEngine/Purity/*.php` — six deliberately-impure/clean fixtures proving the
  scanner actually works (`docs/AGENTS.md` §11.2 — "enforce with a test, not just a convention").
- `tests/Fixtures/Official/ANAF/README.md` — deliberately empty; explains what a future task
  drops in once M4 approves real data.

**Modified outside the strict path list** (small, additive, same justification pattern as prior
handoffs):
- `apps/api/deptrac.php` — added the `TaxEngine` layer with `accesses($support, $subjects, $fx,
  $taxKnowledge)`, each grant justified inline (see "Key decisions" #3/#4/#6).
- `apps/api/tests/Pest.php` — added `uses(RefreshDatabase::class)->in('Feature/Domain/TaxEngine')`
  and two test-setup helpers, `insertApprovedTaxRuleVersion()`/`insertApprovedFiscalParameterVersion()`
  (see "Key decisions" #9).

## Key decisions

1. **`TaxContext::forRules(array $ruleCodes)` is this module's own addition to the literal
   `BLUEPRINT.md` §11.1 builder chain.** The blueprint's example (`forSubject()->fiscalYear()->
   asOf()->withFacts()`) never states how the engine learns which rules to evaluate. Inferring
   "which `tax_rules` apply to these facts" from `category_code` would itself be an un-sourced
   fiscal mapping decision (`docs/AGENTS.md` rule 3) this task has no approved data to make. The
   caller states explicitly which rule codes to assess; each resolved rule receives the *entire*
   fact list and decides for itself what it needs (`TaxRuleContract`'s own contract) — no per-rule
   fact filtering happens in the orchestrator.

2. **Three deviations from `BLUEPRINT.md` §6.7's literal `tax_facts`/`tax_assessments` schema, each
   documented in its own migration's docblock:**
   - `tax_facts` gains six nullable date columns beyond `recognition_date`/`cash_date`
     (`earned_at`, `received_at`, `paid_at`, `distributed_at`, `contract_date`,
     `transaction_date`) — §10.3 names them explicitly ("Rule-ul decide ce dată contează").
   - `tax_facts.fx_rate_id` (a single FK) is replaced by `fx_conversion_json`, because
     `MONTHLY_AVERAGE`/`ANNUAL_AVERAGE` are derived from several `fx_rates` rows, not one — a
     single FK cannot represent every `FxConversionMethod`'s full provenance the way a JSON
     snapshot of `FxConversionResult` can, for every method uniformly.
   - `tax_facts` also gains `deductible_amount_ron_minor`/`withheld_tax_ron_minor` — the literal
     schema gives this table exactly one RON column (for the gross amount). A rule must never
     convert currency itself, so every amount it reads must already be in RON; deriving the
     deductible/withheld RON figures from the gross amount's implied conversion ratio would
     silently assume they convert at the *same* rate as gross, which is not always true (a
     withholding tax can genuinely be assessed/converted separately) — an unverifiable assumption
     `docs/AGENTS.md` §2 forbids making silently.
   - `tax_assessment_lines` gains `calculation_trace_json` (see decision below).

3. **`Kern\TaxRomania\TaxEngine\Repositories\{TaxRuleTemporalReader,FiscalParameterTemporalReader}` are
   the one deliberate exception, in this entire codebase, to "never import another module's
   Eloquent model directly."** `tax_rules`/`tax_rule_versions`/`tax_rule_sources`/
   `fiscal_parameters`/`fiscal_parameter_versions` are the shared, versioned ruleset/parameter
   store this whole engine exists to read — `Kern\Legislation\Models\{TaxRule,
   FiscalParameter}`'s own docblocks, written by the M3 task that built them, say outright "no
   TaxRule reads it yet (Milestone 5)", naming this module as their first reader. `TaxKnowledge`
   does not yet expose an application-service/DTO read surface for them the way it does for
   legal-text sections (`LegislationTemporalQuery`) — building one there is outside this task's
   path scope (`apps/api/packages/tax-romania/src/TaxEngine/**` only, per the task brief). Confined to exactly
   these two classes; every other class in this module only ever sees the DTOs they return
   (`ResolvedTaxRuleVersion`/`ResolvedFiscalParameter`). Recorded as `docs/adr/0009-tax-engine-
   reads-tax-knowledge-models-directly.md`; flagged as an open question below for whoever next
   touches `TaxKnowledge`.

4. **`TaxFactRepository` filters explicitly by a caller-supplied `tax_subject_id` via
   `withoutGlobalScope(TaxSubjectScope::class)`, rather than relying on
   `Kern\TaxRomania\Subjects\Tenancy\TenantContext` being bound.** Whoever calls
   `TaxContext::forSubject($id)` has already authorized that subject id — an HTTP controller with
   a bound `TenantContext` (not built by this task), or a background recompute job with no HTTP
   request at all. The explicit `WHERE tax_subject_id = $id` is correct in both cases and never
   throws `NoTenantContextException` in the job case. `TaxFact`/`TaxAssessment` still use
   `ScopedToTaxSubject` as defence in depth for any *other*, less careful caller — proven by
   `CrossTenantIsolationTest`.

5. **`Money`'s "never a float" guarantee is tested via `ReflectionClass` type inspection, not a
   source-text search for the word "float".** This class's own docblocks legitimately discuss
   "never a float" in prose; a naive `str_contains($source, 'float')` assertion would fail on its
   own documentation. The test instead walks every property/parameter/return type via
   `ReflectionNamedType`/`ReflectionUnionType`/`ReflectionIntersectionType` and asserts none is
   named `float`.

6. **`FxConversionPolicy::convertToRon()` computes `MONTHLY_AVERAGE`/`ANNUAL_AVERAGE` itself, from
   repeated `FxRateQuery::rateWithProvenance()` calls, rather than calling
   `Kern\TaxRomania\Fx\Services\FxPeriodAverageCalculator`.** That calculator's public methods take
   `Kern\TaxRomania\Fx\Models\{FxSource,FxPeriodAverage}` Eloquent models as parameters/return values —
   there is no DTO-based read surface for a pre-calculated average, and extending `Fx` to add one
   is outside this task's path scope. The arithmetic replicates `BLUEPRINT.md` §7.4's published
   methodology exactly (monthly = mean of that month's daily rates; annual = mean of the year's 12
   monthly averages, **never** of daily rates directly — proven by a test where January's two
   daily rates average to 4.5 before the annual figure ever sees them) using the one primitive
   `Fx` does expose. This leaves a reconciliation gap against `Fx`'s own `fx_period_averages`
   cache — see "Open questions".

7. **The purity scanner (`RulePuritySourceScanner`) is test-only code** (`tests/Support/`, not
   `packages/tax-romania/src/TaxEngine/`) **and is a lexical token scan, not a formal proof.** It uses PHP's own
   tokenizer (so it never matches inside a string literal or comment) to flag: wall-clock reads
   (`now`, `date`, `time`, `Carbon`, `CarbonImmutable`, and the disguised form `new
   DateTime[Immutable]()` with **no** constructor argument — proven to *not* false-positive on
   `new DateTimeImmutable($explicitValue)`), randomness, environment reads, and DB/HTTP/cache
   facade usage. `RulePurityArchitectureTest` runs it against **every class currently implementing
   `TaxRuleContract` under `packages/tax-romania/src/TaxEngine`** (discovered dynamically via
   `get_declared_classes()`/`is_a()`, not a fixed list) — zero today, by design (see decision #8),
   but the test is already wired so a future concrete rule is checked automatically with **no
   changes to this test**. Proven to actually catch violations against six deliberately-bad
   fixtures, and proven to *not* flag the one real fixture rule this task ships.

8. **No concrete `TaxRuleContract` implementation ships under `packages/tax-romania/src/TaxEngine`.** The task's
   hard constraint ("you must not introduce a single tax rate, threshold or ceiling") combined
   with `docs/CODE-QUALITY.md` §2 ("no speculative features... single implementation added in
   case") means a generic, parameter-driven rule class would have exactly one real consumer (this
   task's own tests) and no production caller yet — M4's approved data is what would give a real
   rule a reason to exist. `SyntheticPercentageOfBaseRule` (a generic "sum of RON gross facts ×
   a resolved decimal rate" implementation, reading its only number from a factory-created
   `fiscal_parameter_versions` row) lives under `tests/Fixtures/TaxEngine/Rules/` instead, proving
   the full resolve→invoke→trace pipeline end to end without shipping unused production code.

9. **`insertApprovedTaxRuleVersion()`/`insertApprovedFiscalParameterVersion()` (in `tests/Pest.php`)
   insert an `APPROVED` row via the raw query builder, bypassing Eloquent entirely.**
   `Kern\Legislation\Concerns\ForbidsAgentApproval` makes `status = APPROVED` unreachable
   through any Eloquent `save()` call — by design, per `docs/AGENTS.md` §9. Testing the "an
   already-approved version backs a FINAL assessment" happy path is one of this task's explicit
   deliverables ("proof: a CANDIDATE-parameter ruleset cannot produce a FINAL assessment" implies
   the converse must also be provably true), and the raw query builder never fires an Eloquent
   model event, so it is unaffected by that guard — simulating exactly what a future human-review
   workflow this milestone does not build would eventually produce. **No `Kern\TaxRomania\TaxEngine`
   application code path ever does this itself** — only test setup does, and it is confined to
   `tests/Pest.php`, clearly documented as such.

10. **No new Composer dependencies.** Everything is built on `ext-bcmath`, PHP's own `Reflection`/
    tokenizer APIs, and Carbon (already a framework dependency). `composer.json`/`composer.lock`
    untouched; `composer validate`/`composer audit` both re-run and clean (see "How to verify").

## How to verify

Host PHP is 8.3 and cannot run this dependency tree (Pest needs PHP ^8.4). Used the same
throwaway-container approach as every prior handoff: `php:8.5-cli-trixie` (intl, pdo_pgsql, pcntl,
bcmath installed) against the real, already-vendored `apps/api/vendor/`, plus a real
`postgres:17-alpine` container on a shared Docker network, both torn down after verification.

```text
$ vendor/bin/pint --test packages/tax-romania/src/TaxEngine tests/Unit/Domain/TaxEngine \
    tests/Feature/Domain/TaxEngine tests/Fixtures/TaxEngine tests/Support/TaxEngineRulePurity \
    database/migrations/2026_09_08_302000_create_tax_facts_table.php \
    database/migrations/2026_09_08_302001_create_tax_assessments_table.php \
    database/migrations/2026_09_08_302002_create_tax_assessment_lines_table.php \
    database/factories/{TaxFact,TaxAssessment,TaxAssessmentLine}Factory.php \
    tests/Pest.php deptrac.php
  PASS .......................................................... 82 files

$ php -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G packages/tax-romania/src/TaxEngine \
    tests/Unit/Domain/TaxEngine tests/Feature/Domain/TaxEngine tests/Fixtures/TaxEngine/Rules \
    tests/Support/TaxEngineRulePurity \
    database/factories/{TaxFact,TaxAssessment,TaxAssessmentLine}Factory.php
 [OK] No errors                                                                 (level 9, no baseline)

# tests/Fixtures/TaxEngine/Purity/*.php are deliberately-impure/clean fixture *text*, never
# executed, only lexically scanned by RulePuritySourceScannerTest — excluded from this PHPStan
# run for the same reason a fixture with deliberately malformed content is excluded from a
# linter (one of them, ImpureEnvironmentRule.php, trips Larastan's real "no env() outside config"
# rule on purpose; that IS the violation the scanner test proves it catches).

$ vendor/bin/deptrac analyse --config-file=deptrac.php
  Violations 0, Skipped violations 0, Uncovered 446, Allowed 227, Warnings 0, Errors 0

# Real Postgres, this module's own suite, run 1:
$ DB_CONNECTION=pgsql DB_HOST=<pg> DB_DATABASE=kern_test DB_USERNAME=kern DB_PASSWORD=kern \
    vendor/bin/pest tests/Unit/Domain/TaxEngine tests/Feature/Domain/TaxEngine
  Tests: 79 passed (163 assertions)

# Real Postgres, run 2 (leak check) — identical:
  Tests: 79 passed (163 assertions)

# Whole-project suite, real Postgres, run 1:
  Tests: 7 skipped, 485 passed (1285 assertions)
# Whole-project suite, run 2 — 0 failed both times; the passed/skipped counts differ between runs
# only because two other agents (Wallet, Documents) were landing code concurrently in this shared
# repository during verification (confirmed: the only WARN/new-test lines between the two runs
# belong to Tests\Feature\Domain\Documents\* and Tests\Feature\Domain\TaxKnowledge\
# LegislatieJustRoLiveFetchTest — nothing under Domain/TaxEngine). This module's own subset
# (above) was re-run in isolation immediately after and stayed at exactly 79 passed, 0 failed,
# both times.

$ composer validate --no-check-publish   # via composer:2 image, no dependency changed
./composer.json is valid
$ composer audit
No security vulnerability advisories found.
```

**Migration reversibility** — verified directly (not via `migrate:rollback`, which in this shared,
concurrently-changing repository was rolling back *other* agents' more-recently-added migrations
in the same batch, not mine): each of the three migrations' `down()` then `up()` methods invoked
directly, in dependency order (lines → assessments → facts, then reversed), against the real
Postgres database already holding the full concurrent schema. All six calls completed without
error; `tests/Feature/Domain/TaxEngine` (44 tests) re-run immediately after and passed identically.

## Interfaces exposed to other modules

```php
use Kern\TaxRomania\TaxEngine\TaxContext;
use Kern\TaxRomania\TaxEngine\Services\{TaxAssessor, TaxAssessmentRecorder};
use Kern\TaxRomania\TaxEngine\Repositories\TaxFactRepository;
use Kern\TaxRomania\TaxEngine\ValueObjects\Money;
use Kern\TaxRomania\TaxEngine\Enums\{AssessmentStatus, FxConversionMethod, RoundingMode, TaxFactStatus};
use Kern\TaxRomania\TaxEngine\Contracts\TaxRuleContract;
use Kern\TaxRomania\TaxEngine\Attributes\TaxRule as TaxRuleAttribute;

// A future rule implementation:
#[TaxRuleAttribute(code: 'RO.PF.SOME_RULE', version: '2026.1')]
final class SomeRule2026 implements TaxRuleContract
{
    public function evaluate(TaxRuleInput $input): TaxRuleOutcome { /* pure, zero deps */ }
}
```

- `TaxFactRepository::snapshotsFor(string $taxSubjectId, list<string> $factIds): list<TaxFactSnapshot>`
  — the only sanctioned way to turn persisted `tax_facts` rows into a `TaxContext`'s `withFacts()`
  input.
- `TaxAssessor::assess(TaxContext): TaxAssessmentResult` — throws `CandidateNotAllowedInFinalAssessmentException`
  if any resolved rule/parameter version is `CANDIDATE` and `$context->status === FINAL`;
  `NoTaxRuleVersionInForceException`/`AmbiguousTaxRuleVersionException` (and the parameter
  counterparts) on a temporal-resolution failure — never a guess.
- `TaxAssessmentRecorder::record(TaxAssessmentResult): TaxAssessment` — the only sanctioned write
  path to `tax_assessments`/`tax_assessment_lines`.
- `FxConversionPolicy::convertToRon(Money, FxConversionMethod, DateTimeImmutable, RoundingMode,
  string $sourceCode = 'BNR'): FxConversionResult` — usable directly by a rule's *orchestration*
  step (before `evaluate()` is called; never inside a rule itself).
- **M4 plugs in exactly here**: once a `fiscal_parameter_versions`/`tax_rule_versions` row is
  `APPROVED` with a real `source_section_id`, and a real `TaxRuleContract` implementation exists
  under a new `packages/tax-romania/src/TaxEngine/Rules/` directory with its `implementation_class` wired in
  `tax_rule_versions.config_json`, nothing else in this module changes — `RuleResolver`/
  `RuleInvoker`/`TaxAssessor` already handle exactly that shape.

## What is NOT done

- **No concrete Romanian tax rule.** By design — see "Key decisions" #8. `packages/tax-romania/src/TaxEngine/Rules/`
  does not exist yet; the first real rule creates it.
- **No calculation-trace object storage.** `tax_assessments.calculation_trace_object_path` is a
  real, nullable column per `BLUEPRINT.md` §6.7 but is never populated — the full trace is fully
  computed, returned to the caller, and persisted per-line as `tax_assessment_lines
  .calculation_trace_json` instead. Wiring the assessment-level object path would require
  extending `App\Support\ObjectStore` (out of this task's path scope) or inventing a second,
  unencrypted ad hoc writer — neither seemed like the right call to make unilaterally.
- **No HTTP/API layer.** `BLUEPRINT.md` §31's `POST /api/v1/subjects/{subject}/tax-assessments`
  example is not built — this task's brief asked for the domain engine, not the controller/route
  layer; `TaxAssessor`/`TaxContext` are the exact seam a future controller calls into.
  `Kern\TaxRomania\Subjects\Tenancy\TenantContext` authorization is assumed to already have happened by
  whatever constructs a `TaxContext` — this task builds no controller/route/middleware wiring.
- **`Kern\Legislation` does not (yet) expose an application-service/DTO read surface for
  `tax_rule_versions`/`fiscal_parameter_versions` temporal resolution.** `TaxRuleTemporalReader`/
  `FiscalParameterTemporalReader` read those Eloquent models directly as a documented, deliberate
  exception (decision #3) — a future task could move this logic into `TaxKnowledge` itself (e.g. a
  `TaxRuleTemporalQuery` mirroring `LegislationTemporalQuery`) without changing this module's own
  public interface.
- **No historical-regression suite across real fiscal years** — there is no real historical data
  to regress against yet (M4 blocked). `TaxAssessorTest`'s reproducibility test (identical context
  ⇒ identical `inputSnapshotHash`/result, run twice) is the mechanism-level proof that such a
  suite would rely on once real rule versions exist for multiple years.
- **`tests/Fixtures/Official/ANAF/` has no golden-test fixtures** — deliberately empty with a
  README, per the task's explicit instruction not to invent one.
- Did not touch `docs/STATUS.md` (explicit instruction) or any file under
  `packages/tax-romania/src/{Wallet,Documents,Fx,TaxKnowledge,Subjects,Identity,Audit}/**` or `app/Support/**`.

## Open questions

- **Should `Kern\Legislation` grow a `TaxRuleTemporalQuery`/`FiscalParameterTemporalQuery`
  application service**, so `Kern\TaxRomania\TaxEngine\Repositories\{TaxRuleTemporalReader,
  FiscalParameterTemporalReader}` could depend on a DTO-returning service instead of reading
  `TaxKnowledge` Eloquent models directly? Not decided here — out of this task's path scope; see
  decision #3. The two reader classes are a small, isolated seam if a future task wants to migrate
  this.
- **Should `Kern\TaxRomania\Fx` expose a DTO-based read surface for pre-calculated
  `fx_period_averages`** (a `FxPeriodAverageQuery` mirroring `FxRateQuery`), so
  `FxConversionPolicy`'s `MONTHLY_AVERAGE`/`ANNUAL_AVERAGE` could read `Fx`'s own cached averages
  instead of recomputing them from daily spot rates on every call? Both currently implement the
  *same* published methodology (`BLUEPRINT.md` §7.4) independently — not a correctness risk today,
  but a reconciliation/performance question for whoever next touches either module. Not decided
  here — out of this task's path scope (decision #6).
- **How does `tax_rule_versions.config_json`'s `parameter_references` key get populated for a
  real rule?** This task defines the convention (`ParameterResolver` reads
  `$ruleVersion->configJson['parameter_references'] ?? []`, a list of `fiscal_parameters.code`
  strings) but nothing in `TaxKnowledge`'s ingestion/candidate-extraction pipeline populates it —
  a future M4/rule-authoring task decides how a real rule version's config is assembled and
  reviewed.
- **Authorization**: this module assumes whatever constructs a `TaxContext::forSubject($id)` has
  already verified the caller may act on that subject (the same assumption
  `docs/handoff/011-identity-tenancy.md`'s `TenantContext` embodies) — no controller/middleware
  exists yet to enforce this for a real HTTP request, since none was built by this task.

## Sources consulted

- `BLUEPRINT.md` §2.3/§2.4 (forbidden pattern, financial-event→assessment chain), §6.7 (schemas),
  §6.9 (FX conversion methodology, multiplier trap), §10.3 (multiple fact dates), §11 (Tax Engine
  API, purity, Money, trace, in full), §21.2/§21.3 (golden tests, historical regression), §30
  (source-of-truth chain), §39 (reproducibility principle) — read in full per the task brief.
- `docs/adr/0004-tax-engine-deterministic.md` — the decision this task implements, read in full.
- `docs/handoff/018-legislation-ingestion.md` — the `fiscal_parameters`/`tax_rules` skeleton this
  task builds on, reused as-is; its "M5 is the first reader" framing directly informed decision #3.
- `docs/reference/legislation-effective-dates.md` — read in full; confirms rule versions must be
  datable independently of their parent document (already true of `tax_rule_versions.valid_from/
  valid_until`, which never reference a `source_document_versions` date) and that a rule whose
  parameters are only `CANDIDATE` needs a real, tested gate — the task's central mechanism.
  `RO.TEST.*` fixture codes never resemble a real Romanian legal citation, in the same spirit this
  document's own warning about fabricated effective dates describes.
- `docs/handoff/014-source-registry-bnr.md` — `FxRateQuery`'s exact interface, the multiplier
  trap, and the `string`-not-`decimal` lesson for `DecimalRate`, reused rather than re-derived.
- `docs/handoff/016-postgres-suite-stabilisation.md` — read in full before writing any migration;
  its standing lesson ("run the suite against Postgres before claiming done") is exactly why this
  task's verification used a real Postgres container throughout, not sqlite.
- `docs/AGENTS.md`, `docs/CODE-QUALITY.md` — read in full; rule 3 (no rate without a
  `source_section_id`) and rule 8 (never disable a check to pass CI) governed most of this task's
  hard boundaries; §11.2's purity requirement is the reason `tests/Support/TaxEngineRulePurity/
  RulePuritySourceScanner.php` exists.
- `docs/reference/verified-versions.md` — empty at the time of this task; nothing to reconcile.
- Existing code read directly, not assumed: `Kern\TaxRomania\Fx\ValueObjects\DecimalRate` (reused
  verbatim, no second decimal type written), `Kern\TaxRomania\Fx\Services\FxRateQuery`/
  `FxPeriodAverageCalculator` (read in full before deciding decision #6),
  `Kern\Legislation\Services\LegislationTemporalQuery` (the exact temporal-resolution
  contract this task's two readers mirror), `Kern\TaxRomania\Subjects\Tenancy\{ScopedToTaxSubject,
  TaxSubjectScope,TenantContext}` (reused unchanged), `App\Support\Crypto\{Casts\Encrypted,
  Concerns\{HasUuidPrimaryKey,FillsSubjectForeignKeyFirst}}` (reused unchanged for
  `TaxFact.metadata_ciphertext`).
