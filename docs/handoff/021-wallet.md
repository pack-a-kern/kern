# 021 — Wallet (Milestone 6)

**Milestone:** M6   **Status:** complete
**Date:** 2026-09-08

## What was built

The financial wallet: manually-entered accounts, balances, transactions, transaction splits, and
term deposits with a deterministic interest-accrual engine that produces
`deposit_interest_events`, including full FX provenance for a foreign-currency deposit
(`BLUEPRINT.md` §6.3/§6.4, §7.5's DoD). No bank-API sync, PSD2, or statement import — every account
and transaction this task builds is `RecordSource::MANUAL` (`BLUEPRINT.md` §34).

Entry points:

- `App\Domain\Wallet\Actions\OpenFinancialAccount` — opens one `financial_accounts` row.
- `App\Domain\Wallet\Actions\RecordAccountBalanceSnapshot` — records one `account_balance_snapshots` row.
- `App\Domain\Wallet\Actions\RecordFinancialTransaction` — records one `financial_transactions` row
  plus optional `transaction_splits`, enforcing that split amounts sum to the transaction's own
  amount.
- `App\Domain\Wallet\Actions\OpenDeposit` — opens one `deposits` row plus its `deposit_rate_periods`,
  validating the principal, date range, and rate-period coverage eagerly.
- `App\Domain\Wallet\Actions\GenerateDepositInterestSchedule` — (re)computes and persists a
  deposit's full `deposit_interest_events` schedule; idempotent, never touches an already-paid
  period, dispatches `App\Domain\Wallet\Events\DepositInterestEventRecorded` per row after commit.
- `App\Domain\Wallet\Actions\RecordDepositInterestWithholding` — records the withholding actually
  applied to one interest event and recomputes its net amount.
- `App\Domain\Wallet\Services\Interest\DepositInterestCalculator` — the pure (no HTTP, no Eloquent,
  no `now()`, no env, no randomness, no cache) day-count/rate engine, unit-tested against
  hand-computed values.
- `App\Domain\Wallet\Services\DayCount\DayCountCalculator` — `ACTUAL_360`/`ACTUAL_365`/`THIRTY_360`,
  pure.
- `App\Domain\Wallet\Services\Fx\DepositInterestFxAnnotator` — attaches FX provenance to a
  non-RON deposit's interest event via `App\Domain\Fx\Services\FxRateQuery`.
- `App\Domain\Wallet\ValueObjects\Money` / `App\Domain\Wallet\ValueObjects\Decimal` — this
  module's own money/decimal primitives; see "Key decisions" below for why they are not shared
  with `Domain/TaxEngine`.

## Files created / changed

**Migrations** (`apps/api/database/migrations/2026_09_08_500000` through `500006`) —
`financial_accounts`, `account_balance_snapshots`, `financial_transactions`, `transaction_splits`,
`deposits`, `deposit_rate_periods`, `deposit_interest_events`. Every migration's own docblock
explains its schema decisions in detail (denormalised `tax_subject_id` on child tables, no
`updated_at` on `financial_transactions`, the FX provenance columns on `deposit_interest_events`,
why `institution_id` became a plain `institution_name` string, why rate/decimal columns are
`string` not `decimal`/`NUMERIC`) — read those before changing any of these tables.

**`App\Domain\Wallet`** (`apps/api/app/Domain/Wallet/`) — see the file tree above; every class
carries a docblock explaining its own reasoning. Highlights not obvious from names alone:

- `Enums/RecordSource.php` — one enum shared by `financial_accounts.source`,
  `financial_transactions.source`, and `AccountBalanceSnapshot::$source` (the blueprint names the
  identical `MANUAL|IMPORT|BANK_SYNC` literal for two of these columns verbatim — this is stable
  domain knowledge, not incidental similarity, so it is not duplicated).
- `Services/Interest/DepositRateCoverageValidator.php` — the "rate periods must cover
  `[opened_at, maturity_at)` with no gap or overlap" invariant, extracted into its own class so
  both `OpenDeposit` (eager validation at creation time) and `DepositInterestCalculator` (which
  cannot assume the database still matches what was validated at creation time, since a
  `VARIABLE` deposit's rate periods can be edited later) share one implementation.
- `Services/Fx/DepositInterestFxAnnotator.php` — see "FX conversion is a wallet bookkeeping
  choice, not a fiscal one" below; this is the load-bearing design decision for M6's FX DoD.

**Factories** — `apps/api/database/factories/FinancialAccountFactory.php`,
`DepositFactory.php` (bare deposit only, no rate periods — see that factory's docblock for why).

**Tests** — `apps/api/tests/Unit/Domain/Wallet/` (pure: `MoneyTest`, `DecimalTest`,
`DayCountCalculatorTest`, `DepositPaymentScheduleBuilderTest`, `DepositRateCoverageValidatorTest`,
`DepositInterestCalculatorTest`) and `apps/api/tests/Feature/Domain/Wallet/`
(`CrossTenantIsolationTest`, `RawDatabasePlaintextTest`, `RecordFinancialTransactionTest`,
`DepositInterestScheduleTest`, `DepositInterestFxProvenanceTest`).

**Wiring** (small, additive, same justification pattern as prior handoffs):
- `apps/api/deptrac.php` — added the `Wallet` layer, granted `accesses($support, $subjects, $fx)`.
- `apps/api/tests/Pest.php` — added `uses(RefreshDatabase::class)->in('Feature/Domain/Wallet')`,
  matching the existing per-directory pattern (`docs/handoff/016-postgres-suite-stabilisation.md`).
  Did **not** touch `Feature/Domain/Audit`.

No new Composer dependencies — everything uses BCMath (already required by `Domain/Fx`) and PHP's
built-in `DateTimeImmutable`/`DateInterval`.

## Key decisions

### The money-primitive decision — a second `Money`, deliberately, not a shared one

`Domain/TaxEngine` was, at the time this task started, an empty, not-yet-built module being
developed concurrently by another agent (it now has its own `App\Domain\TaxEngine\ValueObjects\Money`,
visible in `deptrac.php`'s `TaxEngine` layer added after this task began). Three reasons this
module built its own `App\Domain\Wallet\ValueObjects\Money` (integer minor units + ISO-4217-shaped
currency code) instead of depending on that one, or moving one into a shared location:

1. **`docs/AGENTS.md` §6 forbids a module importing another module's types outside the
   DTO/contract/event/application-service shapes deptrac enforces.** A plain value object is none
   of those — it is domain code belonging to whichever module owns it. Depending on
   `Domain\TaxEngine\ValueObjects\Money` from `Domain\Wallet` would require a deptrac grant that
   exists for exactly one reason (borrowing a value type), which is a narrower and stranger
   coupling than the two modules independently agreeing on "integer minor units, no float" — the
   actual rule (`docs/AGENTS.md` rule 6) both must follow regardless.
2. **A plain immutable integer-plus-currency-code container is infrastructure-shaped, not fiscal
   domain knowledge.** `docs/CODE-QUALITY.md` §1's precise nuance is "duplication of stable domain
   knowledge must be removed; incidental similarity does not justify abstraction." A tax rate, a
   CASS threshold, a day-count convention formula — those are domain knowledge, and this task
   extracted `DepositRateCoverageValidator` specifically because that *coverage invariant* is
   shared, stable domain knowledge between two call sites within this module. "How to represent a
   sum of money as an integer" is not comparable in kind — it is closer to "how to represent a
   UUID," which this codebase already solves independently per concern where warranted
   (`HasUuidPrimaryKey` is shared because it also encodes the AAD-ordering trap, a genuine
   invariant; a bare value object holding an `int` and a `string` encodes no comparable invariant
   worth centralising into a third module both would then depend on).
3. **Practically, moving either one into `Support` was out of both tasks' literal scope**, and
   would have created a merge/ownership conflict between two concurrently-working agents over a
   file neither was told to own. Building two small, independently-testable value objects that
   happen to look alike was strictly lower-risk than either agent unilaterally claiming a shared
   `Support/Money` this task was never asked to create.

If a future task wants to unify these, the concrete difference to resolve is worth noting:
`TaxEngine\ValueObjects\Money` (per its own PHPStan errors visible in this task's final full-suite
run — not this task's to fix) represents money as a BCMath decimal string rather than this
module's plain `int` minor units; the two are not drop-in compatible today, so unifying them is a
real design decision for whoever owns that call, not a mechanical rename.

The identical reasoning applies to `App\Domain\Wallet\ValueObjects\Decimal` versus
`App\Domain\Fx\ValueObjects\DecimalRate` — see `Decimal`'s own docblock. The one place this module
*does* reach directly into `Domain/Fx` is `DepositInterestFxAnnotator`, which consumes
`FxRateQuery::rateWithProvenance()`'s `DecimalRate` return value completely unmodified — that value
genuinely originates in `Domain/Fx`, so using its own type for it is not duplication, it is the
correct application-service crossing (`docs/AGENTS.md` §6, the same shape `Domain/Fx` itself uses
against `Domain/TaxKnowledge`).

### FX conversion is a wallet bookkeeping choice, not a fiscal one

`BLUEPRINT.md` §7.4 is explicit that a fiscal `TaxRule` must itself choose spot/monthly-average/
annual-average/etc., per rule, deliberately — no module may bake in one FX method as "the" fiscal
conversion. `Domain/TaxEngine` did not exist as a rule-holding module when this task needed to
produce a concrete FX-provenance chain for M6's own DoD, so `DepositInterestFxAnnotator` uses the
BNR **spot rate on the day the interest is recognised** (`accrual_to`) purely as *this module's own*
wallet bookkeeping conversion — recorded as `fx_method = 'SPOT_DAY_OF_ACCRUAL_END'` on the row
specifically so nothing downstream mistakes it for an authoritative fiscal figure.

**`Domain/TaxEngine`, when it consumes `App\Domain\Wallet\Events\DepositInterestEventRecorded`,
must not treat `ron_equivalent_minor` as the fiscal RON amount for a `TaxFact`.** It should
independently convert `$event->grossInterestMinor` + `$event->currencyCode` + `$event->accrualTo`
via its own `FxRateQuery` call, using whatever method the specific applicable tax rule requires.
This is documented in three places so it cannot be missed: the event class's own docblock, the
`deposit_interest_events` migration's docblock, and here.

### Wallet never computes a withholding-tax rate

`Domain/Wallet` computes only **gross** interest. No Romanian withholding rate is hard-coded
anywhere in this module — that is fiscal domain knowledge requiring a `source_section_id`
(`docs/AGENTS.md` §9) and belongs exclusively to `Domain/TaxEngine`. `withholding_minor` starts
`NULL` (`net_interest_minor` defaults to equal `gross_interest_minor`) until
`RecordDepositInterestWithholding` is called — either by a human transcribing a real bank
statement, or later by `Domain/TaxEngine` once it exists to compute one. `InterestMethod::COMPOUND`
capitalises the **gross** rounded interest into the next period's base for the same reason: this
module has no opinion on what fraction, if any, of a period's interest was withheld before
capitalisation, and guessing would be exactly the kind of fabricated fiscal number
`docs/AGENTS.md` §2 forbids.

### Day-count conventions

Three conventions implemented in `App\Domain\Wallet\Services\DayCount\DayCountCalculator`:
`ACTUAL_360`, `ACTUAL_365` (fixed 365 denominator, never leap-adjusted), and `THIRTY_360` (the
classic "30/360 US" / "Bond Basis" method: every month treated as 30 days, with the standard
31-day end-of-month adjustment rule). These are standard, widely-documented financial-market
conventions, not a fiscal/legal citation — `docs/AGENTS.md` §9's sourcing requirement applies to
tax rates and thresholds, not to a day-count method chosen by a deposit's own contract terms. Every
formula is proven against hand-computed values (exact rational arithmetic, shown in the test
docblocks) in `tests/Unit/Domain/Wallet/DayCountCalculatorTest.php`, not asserted from authority.

### Interest calculation algorithm

`DepositInterestCalculator::calculate()`: the deposit's `[opened_at, maturity_at)` life is split
into payment/capitalisation periods by `payment_frequency`
(`DepositPaymentScheduleBuilder` — clips the final period to maturity when the term is not an
exact multiple of the frequency). Each payment period is further split at any rate-period boundary
inside it (a `VARIABLE` deposit may change rate mid-period); each sub-interval's interest is
`base * (annualRate / 100) * yearFraction`, summed at full BCMath precision across sub-intervals
and rounded exactly once, at the end of the payment period (round-half-up to the nearest minor
unit). `base` starts as the deposit's principal; under `InterestMethod::SIMPLE` it never changes;
under `COMPOUND` it is increased by the *previous period's rounded* gross interest.

Every numeric expectation in `tests/Unit/Domain/Wallet/DepositInterestCalculatorTest.php` is
computed by hand via exact rational (fraction) arithmetic in the test's own docblock, not
approximated — including a 3-period SIMPLE case, the matching COMPOUND case (showing capitalisation
changes periods 2 and 3 but not period 1), and a VARIABLE-rate case that splits one payment period
at a rate-change boundary and confirms rounding happens once, at the end, not per sub-interval.

### A real bug this task's own test suite caught, worth recording for future agents

`pint.json` sets `"strict_comparison": true` project-wide (the PHP-CS-Fixer `strict_comparison`
fixer). Running plain `vendor/bin/pint` (not `--test`) silently rewrote
`DepositRateCoverageValidator`'s `$from != $cursor` (a **value** comparison between two distinct
`DateTimeImmutable` instances representing the same moment) into `$from !== $cursor` — which is
`true` for *any* two distinct object instances regardless of value, since `!==` is identity
comparison, not value comparison. This silently made every coverage check report "gap or overlap"
even for genuinely-valid, fully-covering rate periods. It was caught immediately by running the
real test suite (a same-day-boundary test failed with an obviously-wrong "expected 2026-01-01,
found 2026-01-01" message) — not by PHPStan, which has no rule for this. **Fixed by comparing via
the spaceship operator, `($from <=> $cursor) !== 0`**, which PHP defines as `DateTime`/
`DateTimeImmutable` by-value comparison and which the `strict_comparison` fixer does not rewrite.
**Lesson: never rely on `==`/`!=` for `DateTimeImmutable` value comparison in this codebase** — the
project's own Pint config will silently break it the next time someone runs the auto-fixer, and
the failure mode (always-true "different") is a security/correctness regression, not a syntax
error. Use `<=>` instead, as done here.

## How to verify

Ran against a throwaway `php:8.5-cli-trixie`-based image (the pre-built `anaf-app:latest`, already
present locally) against the **real, already-running** `postgres`/`redis`/`openbao`/`minio`
containers on the `anaf_data_net` network, exactly per
`docs/handoff/015-m1-integration-repairs.md`'s documented pattern. `DB_PASSWORD` is **not** the
blank value in `apps/api/.env` — the real value lives in the repo root `.env`
(`POSTGRES_PASSWORD`), used below.

```text
$ docker run --rm --network anaf_data_net --user root \
    -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_PORT=5432 \
    -e DB_DATABASE=taxos -e DB_USERNAME=taxos -e DB_PASSWORD=<POSTGRES_PASSWORD from root .env> \
    -v "$PWD/apps/api":/app -w /app --entrypoint sh anaf-app -c 'vendor/bin/pint --test'
  PASS .......................................................... 537 files

$ ... -c 'php -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G \
    app/Domain/Wallet database/migrations database/factories/FinancialAccountFactory.php \
    database/factories/DepositFactory.php tests/Unit/Domain/Wallet tests/Feature/Domain/Wallet \
    deptrac.php tests/Pest.php'
 [OK] No errors                                                    (level 9, no baseline)

$ ... -c 'vendor/bin/pest tests/Unit/Domain/Wallet tests/Feature/Domain/Wallet'
  Tests:    56 passed (139 assertions)

$ # ran a second consecutive time — identical result, no state leak
  Tests:    56 passed (139 assertions)

$ ... -c 'vendor/bin/pest'   # whole repo, once
  Tests:    9 failed, 7 skipped, 476 passed (1272 assertions)
  # all 9 failures are in Tests\{Unit,Feature}\Domain\TaxEngine — a different, concurrently
  # in-flight agent's module; none touch Wallet. Confirmed by grepping the FAILED lines.

$ ... -c 'vendor/bin/deptrac analyse --config-file=deptrac.php'
  Violations 0, Skipped violations 0, Uncovered 448, Allowed 227, Warnings 0, Errors 0
```

`composer validate`/`composer audit` were not run — no Composer binary in this throwaway runtime
image (same situation as `docs/handoff/012-encrypted-object-store.md` and
`docs/handoff/014-source-registry-bnr.md`), and no dependency changed, so there is nothing new to
validate/audit.

## Interfaces exposed to other modules

- **`App\Domain\Wallet\Events\DepositInterestEventRecorded`** — dispatched via Laravel's own event
  dispatcher (`event(new self(...))`, plain PHP class, no queue) after
  `GenerateDepositInterestSchedule`'s DB transaction commits. **This is the contract Milestone 5
  (`Domain/TaxEngine`) consumes** to create a `PROPOSED` `TaxFact` from a deposit interest accrual
  (`BLUEPRINT.md` §2.4's Financial Event → Normalized Tax Fact pipeline). Carries:
  `depositInterestEventId`, `taxSubjectId`, `depositId`, `accrualFrom`/`accrualTo`
  (`DateTimeImmutable`), `currencyCode`, `grossInterestMinor` (int), `withholdingMinor` (`?int`),
  `netInterestMinor` (int), and this module's own wallet-bookkeeping `ronEquivalentMinor`/
  `fxRateDecimal`/`fxSourceCode` (**not** to be treated as the fiscal conversion — see "Key
  decisions" above). No listener exists yet — `Domain/TaxEngine` did not have a `TaxFact` model at
  the time this task was written; wiring `Event::listen()` is that module's job.
- **`App\Domain\Wallet\ValueObjects\Money`** — a plain, standalone value object; not intended to be
  imported by other modules (each module builds its own per "Key decisions" above), but its shape
  (`fromMinorUnits(int, string)`, integer minor units, ISO-4217-shaped currency code, same-currency
  arithmetic) is stable if a future task wants to compare it against `Domain/TaxEngine`'s own.
- Every Wallet table is scoped to `tax_subject_id` via `App\Domain\Subjects\Tenancy\
  ScopedToTaxSubject` — any future module reading wallet data (a dashboard, a rental module) needs
  an active `App\Domain\Subjects\Tenancy\TenantContext` bound to the same subject, exactly as
  `docs/handoff/011-identity-tenancy.md` describes.

## What is NOT done

- No HTTP/API layer (controllers, routes, OpenAPI) — this task built the domain layer only, per
  the task's own scope list (`apps/api/app/Domain/Wallet/**`, migrations, tests, factories).
- No UI/dashboard.
- No bank-API sync, PSD2, or statement import parser (`BLUEPRINT.md` §34 explicit non-goal for this
  milestone) — `import_batch_id` and `RecordSource::{IMPORT,BANK_SYNC}` exist in the schema/enum
  because the blueprint's literal schema names them, but nothing in this task's code ever
  populates or constructs them.
- No `Domain/TaxEngine` listener for `DepositInterestEventRecorded` — that module did not have a
  `TaxFact` model when this task was written (see "Interfaces exposed to other modules").
- No properties/rental tables (`rental_contracts`, etc.) — out of this task's scope (Milestone 8).
- No audit-chain integration for Wallet CRUD (opening an account, recording a transaction) — the
  existing `AuditChainService` precedent (`RegisterPfSubject`) audits subject-lifecycle/security
  events, not routine business CRUD; nothing in `BLUEPRINT.md` §16's domain-event list or this task's
  brief asked for one here. Flagged as an open question below rather than decided silently.
- `deposit_rate_periods` for a `VARIABLE`-rate deposit can only be added at `OpenDeposit` time in
  this task's code — no `AddDepositRatePeriod` action exists to append a new period to an
  already-open deposit later (only `GenerateDepositInterestSchedule`'s re-run/idempotency logic
  anticipates that a rate period *could* be added directly via `DepositRatePeriod::create()`, and
  the calculator/coverage-validator would correctly recompute around it — the missing piece is
  purely an ergonomic action wrapper, not a modelling gap).

## Open questions

- Should opening a `financial_accounts`/`deposits` row emit an `AuditChainService` event? Not
  built here (see "What is NOT done"); worth a deliberate decision rather than adding it
  reflexively to every future business action.
- Should `Domain/TaxEngine`'s own `Money`/`Decimal` and this module's eventually be unified into a
  shared `Support`-level primitive? See "Key decisions" — a real design decision once one module's
  API is stable, not something either agent should do unilaterally mid-flight.
- `AccountBalanceSnapshot.as_of` is a `timestampTz`, not a `date` — `BLUEPRINT.md` §6.3 just says
  "as_of" with no type; a snapshot is plausibly intraday (e.g. multiple manual balance checks on
  the same day), so a full timestamp was chosen over a bare date. Reconsider if a future feature
  needs one-snapshot-per-day semantics instead.

## Sources consulted

- `BLUEPRINT.md` §6.3 (wallet schema), §6.4 (deposits schema), §7.4/§7.5 (FX method/reproducibility),
  §2.3/§2.4 (no fiscal logic outside `TaxEngine`; Financial Event → Tax Fact pipeline), §19.1/§21.4
  (money/testing rules), §34 (non-goals).
- `docs/AGENTS.md` §6 (module boundaries, cross-module traffic shapes), §8 (encryption/blind-index/
  tenancy rules), §9 (fiscal sourcing requirements), §21.4 → `docs/CODE-QUALITY.md` §1 (DRY nuance
  applied throughout "Key decisions").
- `docs/handoff/011-identity-tenancy.md` — read in full before writing any model; `ScopedToTaxSubject`/
  `TenantContext`/`HasUuidPrimaryKey`+`FillsSubjectForeignKeyFirst` interaction (the "second
  ordering trap") reused exactly as documented, including the "must pass `tax_subject_id`
  explicitly for a model with both encrypted fields and tenant scoping" caveat.
- `docs/handoff/005-crypto-core.md` — `Encrypted` cast, AAD binding, blind-index formula, the
  UUIDv7-in-constructor trap.
- `docs/handoff/014-source-registry-bnr.md` — `FxRateQuery`/`DecimalRate` contract and the
  multiplier-division rule, reused verbatim via `DepositInterestFxAnnotator`; the "string not
  decimal/NUMERIC" rate-column precedent, reused for `annual_rate_decimal`/`fx_rate_decimal`.
- `docs/handoff/016-postgres-suite-stabilisation.md` — the exact `RefreshDatabase` per-directory
  pattern and the real-Postgres verification command this handoff's own verification follows.
- `docs/handoff/015-m1-integration-repairs.md` — the throwaway-container-against-live-Postgres
  verification recipe (`docker run --network anaf_data_net --user root ...`).
- Read `App\Domain\Subjects\Models\{TaxSubject,PfProfile}`, `App\Domain\Identity\Models\
  UserMfaMethod`, and `App\Domain\Subjects\Actions\RegisterPfSubject` directly (not assumed) to
  confirm the `HasUuidPrimaryKey`/`FillsSubjectForeignKeyFirst`/`ScopedToTaxSubject` combination
  this task uses for `FinancialAccount`/`FinancialTransaction`/`Deposit` has real precedent.
- `apps/api/pint.json`, `apps/api/deptrac.php`, `apps/api/phpstan.neon` — read directly before
  relying on their configured rules (the `strict_comparison` bug in "Key decisions" was found by
  running the actual tool, not by reading its config and assuming behaviour).
