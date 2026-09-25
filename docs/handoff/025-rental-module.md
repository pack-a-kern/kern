# 025 — Rental module (properties, contracts, amendments, accruals, payments, matching)

**Milestone:** M8   **Status:** complete
**Date:** 2026-09-08

## What was built

`Kern\TaxRomania\Rental` (`BLUEPRINT.md` §6.5): properties (with co-ownership), rental contracts with a
temporal amendment model, generated per-period accruals, real payments, and a heuristic
payment↔wallet-transaction matcher that only ever proposes a link, never confirms one. No tax rate,
threshold, or fiscal figure is computed anywhere in this module (`docs/AGENTS.md` rule 3) — rental
income is surfaced to `Domain/TaxEngine` only via a domain event, exactly as `Domain/Wallet` does
for deposit interest.

Entry points:

- `Kern\TaxRomania\Rental\Actions\RegisterProperty` — creates one `properties` row; validates
  `ownership_percent_decimal` eagerly.
- `Kern\TaxRomania\Rental\Actions\CreateRentalContract` — opens a contract plus its first
  `rental_contract_versions` row (`valid_from = start_date`).
- `Kern\TaxRomania\Rental\Actions\AmendRentalContract` — closes the currently in-force version and
  opens a new one from the amendment's effective date onward. **This is the load-bearing action
  for the temporal model** — read "The temporal amendment model" below before touching it.
- `Kern\TaxRomania\Rental\Actions\TerminateRentalContract` — closes the contract and its open version
  at a given `end_date`.
- `Kern\TaxRomania\Rental\Actions\GetRentalTermsInForce` — "what were this contract's terms on date X",
  exactly one version, never a blend.
- `Kern\TaxRomania\Rental\Actions\GenerateRentalAccruals` — (re)computes `rental_accruals` through a
  given horizon, idempotently, dispatching `Kern\TaxRomania\Rental\Events\RentalAccrualRecorded` per
  row after commit.
- `Kern\TaxRomania\Rental\Actions\RecordRentalPayment` — records a real payment, optionally against an
  accrual (marking it `PAID` once fully covered).
- `Kern\TaxRomania\Rental\Actions\ProposeRentalPaymentTransactionMatch` /
  `ConfirmRentalPaymentTransactionMatch` / `RejectRentalPaymentTransactionMatch` — the matching
  workflow; see "Matching" below for the exact signals.

## Files created

**Migrations** (`apps/api/database/migrations/2026_09_08_800000` through `800004`) —
`properties`, `rental_contracts`, `rental_contract_versions`, `rental_accruals`, `rental_payments`.
Every migration's own docblock explains its schema decisions — read those before changing any of
these tables. Two documented deviations from the blueprint's literal column list, both explained
in full in "Key decisions" below: (1) `rent_minor`/`payment_frequency`/`payment_day` live only on
`rental_contract_versions`, not on `rental_contracts`; (2) `rental_accruals` gains
`rental_contract_version_id`.

**`Kern\TaxRomania\Rental`** (`apps/api/packages/tax-romania/src/Rental/`):

- `Enums/{PropertyType,TenantType,RentalContractStatus,RentalPaymentFrequency,AccrualStatus,
  TransactionMatchStatus}.php`.
- `Models/{Property,RentalContract,RentalContractVersion,RentalAccrual,RentalPayment}.php`.
- `DataTransferObjects/*` — one input DTO per action, plus `TransactionMatchCandidate`.
- `Exceptions/*` — one per named failure mode; no catch-all anywhere in this module.
- `Services/OwnershipShareCalculator.php` — the co-ownership BCMath share calculation.
- `Services/RentalTermsInForceResolver.php` — "exactly one version in force on date X".
- `Services/AccrualPeriodBuilder.php` — splits one version's life into `payment_frequency`-sized
  periods (the `Kern\TaxRomania\Wallet\Services\Interest\DepositPaymentScheduleBuilder` idiom,
  reimplemented independently — see "Key decisions").
- `Services/RentalDueDateCalculator.php` — `payment_day` → an actual due date, clamped into the
  period.
- `Services/Matching/TransactionMatchCandidateFinder.php` — the heuristic; see "Matching" below.
- `Actions/*` — listed above.
- `Events/RentalAccrualRecorded.php` — the M5 contract; see "Interfaces exposed to other modules".

**Factories** — `apps/api/database/factories/PropertyFactory.php` only. No
`RentalContract`/`RentalContractVersion` factory: both always need to be created together (a
contract with no version is invalid), and `Kern\TaxRomania\Rental\Actions\CreateRentalContract` is the
one correct way to do that — a factory that fabricated a paired contract+version silently would
duplicate that action's own validation, the same reasoning `docs/handoff/021-wallet.md` gives for
`DepositFactory` shipping no `deposit_rate_periods`.

**Tests** — `apps/api/tests/Unit/Domain/Rental/` (`OwnershipShareCalculatorTest`,
`AccrualPeriodBuilderTest`, `RentalDueDateCalculatorTest`, `MoneyNeverFloatTest` — all pure, no
DB) and `apps/api/tests/Feature/Domain/Rental/` (`CrossTenantIsolationTest`,
`RawDatabasePlaintextTest`, `RentalContractAmendmentAccrualTest`, `CoOwnershipAccrualShareTest`,
`RentalPaymentMatchingTest`).

**Wiring** (small, additive, same justification pattern as every prior handoff):
- `apps/api/deptrac.php` — added the `Rental` layer, granted `accesses($support, $subjects)` only.
- `apps/api/tests/Pest.php` — added `uses(RefreshDatabase::class)->in('Feature/Domain/Rental')`.
  Did **not** touch `Feature/Domain/Audit`.

No new Composer dependencies — everything uses BCMath (`ext-bcmath`, already required) and PHP's
built-in `DateTimeImmutable`/`DateInterval`/`Carbon`.

## The temporal amendment model — read this before touching `AmendRentalContract`

A contract's terms live **only** on `rental_contract_versions`, never on `rental_contracts` itself
(see "Key decisions" #1 for why). At any moment, exactly one version per contract has
`valid_to IS NULL` — "the currently in-force version" — and every other version has a concrete
`valid_to` equal to the *next* version's `valid_from`. This invariant is maintained **by
construction**, not by a database constraint or a full-table re-validation:

- `CreateRentalContract` creates exactly one version, `valid_from = start_date`, `valid_to = null`.
- `AmendRentalContract` finds the one `valid_to IS NULL` row, sets its `valid_to` to the
  amendment's `effectiveFrom`, then inserts a new row `valid_from = effectiveFrom`,
  `valid_to = null`. It refuses (`InvalidAmendmentDateException`) an `effectiveFrom` that is not
  strictly after the current version's own `valid_from`, and — if the contract has an `end_date` —
  not strictly before it. **Only ever appends to the end of the timeline.** Backdating an
  amendment into already-amended history is not supported; nothing in this task's brief asked for
  it, and doing it correctly would require re-deriving every accrual generated under the version
  being split, which is a materially bigger, separate problem.
- `TerminateRentalContract` closes the open version's `valid_to` to the same `end_date` it sets on
  the contract, and marks the contract `TERMINATED` (after which `AmendRentalContract` refuses).

**Reading "the terms in force on date X"** (`Kern\TaxRomania\Rental\Services\
RentalTermsInForceResolver`, wrapped by `Kern\TaxRomania\Rental\Actions\GetRentalTermsInForce`) walks
the ordered versions and returns the one where `valid_from <= date` and (`valid_to` is null or
`valid_to > date`) — exactly one match or `NoContractVersionInForceException`, never a guess, never
a blend. Every date comparison here and throughout this module uses the spaceship operator
(`<=>`), never `==`/`!=`, per `docs/CODE-QUALITY.md` §4b — Pint's `strict_comparison` fixer
silently rewrites those to `===`/`!==`, which is always-true/always-false object-identity
comparison for two distinct `DateTimeImmutable`/`Carbon` instances regardless of value. This bug
has already bitten `Domain/Wallet` once (`docs/handoff/021-wallet.md` "Key decisions"); every date
comparison in this module was written with that already in mind, not discovered the same way.

**Generating correct accruals on both sides of an amendment**
(`Kern\TaxRomania\Rental\Actions\GenerateRentalAccruals`) schedules **each version independently**:
for a version's own `[valid_from, min(valid_to ?? $until, $until))`, it calls
`Kern\TaxRomania\Rental\Services\AccrualPeriodBuilder::build($version->payment_frequency, ...)`, which
splits that interval into `payment_frequency`-sized periods, clipping the final one to the
version's own end. Because scheduling restarts from each version's own `valid_from`, a rent change
always lands exactly on a period boundary — no single `rental_accruals` row is ever asked to
represent two different rent amounts. Proven directly in
`tests/Feature/Domain/Rental/RentalContractAmendmentAccrualTest.php`: a contract at 1000.00 RON/mo
amended to 1500.00 RON/mo mid-February produces four accruals — `[01-01,02-01)` and `[02-01,02-15)`
both at the old rate and tied to the old version's id, `[02-15,03-15)` and `[03-15,04-01)` both at
the new rate and tied to the new version's id.

**Not implemented, deliberately**: day-count proration of a partial period's rent. A period
shortened by a mid-month amendment (e.g. `[02-01,02-15)` above) still accrues one *full* period's
worth of rent for that partial span, not 14/28ths of it. `BLUEPRINT.md`'s rental schema names no
day-count convention for rent (unlike deposit interest, which explicitly has one), and inventing a
proration rule here would be an un-sourced business rule this module has no authority to invent.
If a future task wants proration, `AccrualPeriodBuilder`'s period boundaries and
`OwnershipShareCalculator`'s BCMath share calculation are the two places to extend.

## Co-ownership — `properties.ownership_percent_decimal`

`BLUEPRINT.md` §33 names co-ownership as a first-class design constraint. `Property` carries
`ownership_percent_decimal`, a validated decimal string in `(0, 100]` (`Kern\TaxRomania\Rental\Actions\
RegisterProperty` validates the shape and range eagerly; `Kern\TaxRomania\Rental\Services\
OwnershipShareCalculator` trusts it without re-validating). Every `rental_accruals.gross_minor`
this module ever writes is **already the owning tax subject's share** —
`OwnershipShareCalculator::shareOf($version->rent_minor, $property->ownership_percent_decimal)`,
round-half-up to the nearest minor unit via the exact `bcadd($ratio, '0.5', 0)` idiom
`Kern\TaxRomania\Wallet\ValueObjects\Decimal::roundedTo()` already documents and uses in this codebase.
Proven directly in `tests/Feature/Domain/Rental/CoOwnershipAccrualShareTest.php`: a 50%-owned
property with a 2000.00 RON/mo contract produces accruals of exactly 1000.00 RON, never the full
2000.00; a 33.33%-owned property with a 1000.00 RON/mo contract produces exactly 333.30 RON.

## Matching — the exact signals (documented once, in
`Kern\TaxRomania\Rental\Services\Matching\TransactionMatchCandidateFinder`)

A `financial_transactions` (`Domain/Wallet`) row is a candidate for a `rental_payments` row when
**all** of:

1. same `tax_subject_id`;
2. same `currency_code` (exact);
3. same `amount_minor` (exact — a rent receipt is assumed a full, unsplit credit; no
   partial-payment reconciliation is attempted by this heuristic);
4. `booked_at` within **3 days** (`TransactionMatchCandidateFinder::DATE_WINDOW_DAYS`) of the
   payment's own `paid_at`;
5. not already the `CONFIRMED` match of a *different* `rental_payments` row (prevents the same
   real-world bank transaction being confirmed against two different rent payments).

Among survivors, the smallest date distance wins; ties break on the transaction id's lexical order
(determinism only, not a real signal). **This never produces more than `PROPOSED`.**
`Kern\TaxRomania\Rental\Actions\ProposeRentalPaymentTransactionMatch` writes `transaction_id` +
`transaction_match_status = PROPOSED` and stops there — `BLUEPRINT.md` §14.6's "never auto-confirm"
principle, applied to a financial link exactly as it applies to a document extraction. Only
`ConfirmRentalPaymentTransactionMatch` (a human decision) can reach `CONFIRMED`.
`RejectRentalPaymentTransactionMatch` moves to `REJECTED` but **keeps** `transaction_id` for audit
— the same precedent `Kern\Documents\Models\DocumentExtraction` already sets for keeping
`result_ciphertext` regardless of review decision. All six matching scenarios (propose, confirm,
reject-keeps-candidate, refuse-to-decide-an-unproposed-match, outside-date-window,
already-confirmed-elsewhere) are proven in
`tests/Feature/Domain/Rental/RentalPaymentMatchingTest.php`.

**The `financial_transactions` table is read via `Illuminate\Support\Facades\DB::table(...)`, never
`Kern\TaxRomania\Wallet\Models\FinancialTransaction`** — this is the reason `deptrac.php`'s new `Rental`
layer needs no grant on `Wallet` at all. This is not a novel shortcut invented here: it is the
identical sanctioned shape `Kern\TaxRomania\Subjects\Infrastructure\EloquentSubjectDekRepository`
already establishes in this codebase for a one-off cross-table read
(`docs/handoff/011-identity-tenancy.md` "Key decisions" #2) — a genuine, documented interim
measure, since `Domain/Wallet` exposes no DTO-returning application service for reading its own
transactions and building one there was outside this task's path scope
(`apps/api/packages/tax-romania/src/Rental/**` only). Only plain, unencrypted columns are read (`id`,
`account_id`, `booked_at`, `amount_minor`, `currency_code`) — never `description_ciphertext`/
`counterparty_ciphertext`, which this module has no key material to decrypt and does not need to.
**If `Domain/Wallet` ever renames or restructures `financial_transactions`, this finder breaks
silently at runtime, not at compile time** — flagged as an open question below.

## Money

Every amount in this module — `rent_minor`, `deposit_minor`, `gross_minor`, `amount_minor` — is a
plain PHP `int` of minor units, `bigInteger` at the database level, never a float
(`docs/AGENTS.md` rule 6; `tests/Unit/Domain/Rental/MoneyNeverFloatTest.php` reflects over every
class under `packages/tax-romania/src/Rental` and asserts none declares a `float` type anywhere). **No `Money`
value object was introduced for this module** — a deliberate choice, not an oversight, checked
against `docs/handoff/021-wallet.md`'s own decision before making it: `Kern\TaxRomania\Wallet\
ValueObjects\Money` exists in that module but, confirmed by grepping the actual call sites, is used
by **none** of `Domain/Wallet`'s own actions — `RecordFinancialTransaction`/`OpenDeposit` operate
on plain `int` minor units directly. Since the one arithmetic operation this module needs
(a percentage share) is a single pure BCMath calculation with no other operations to bundle
alongside it, wrapping it in a `Money`/`Decimal` type would be exactly
`docs/CODE-QUALITY.md` §2's "abstraction with a single, non-generic use that shouldn't exist" —
`Kern\TaxRomania\Rental\Services\OwnershipShareCalculator` is that one calculation, and nothing more
was built around it.

## Tax facts — the M5 contract

`Kern\TaxRomania\Rental\Events\RentalAccrualRecorded` is dispatched (`event(new self(...))`, plain
Laravel event dispatch, no queue) after `GenerateRentalAccruals`'s transaction commits — the
identical contract shape `Kern\TaxRomania\Wallet\Events\DepositInterestEventRecorded` already
establishes for the same "financial event → tax fact" problem (`BLUEPRINT.md` §2.4). This module
**never** imports `Kern\TaxRomania\TaxEngine`'s Eloquent models (`docs/AGENTS.md` §6) and never
constructs a `TaxFact` itself. The event carries: `rentalAccrualId`, `taxSubjectId`, `propertyId`,
`rentalContractId`, `tenantType` (PF/PJ/UNKNOWN — fiscally relevant per §6.5, this module records
it but decides nothing from it), `periodStart`/`periodEnd`/`dueDate`, `currencyCode`, `grossMinor`
(**already the owner's share** — a consumer must not apply `ownershipPercentDecimal` a second
time), and `ownershipPercentDecimal` itself (for audit only).

**What M5 must do with this event** (no listener exists yet — `Domain/TaxEngine` has no reason to
depend on `Domain/Rental` and this task builds no `Event::listen()` wiring):

1. Register a listener for `RentalAccrualRecorded`.
2. Create a `tax_facts` row with `status = PROPOSED` — never `CONFIRMED` automatically
   (`BLUEPRINT.md` §14.6, applied here exactly as `docs/handoff/021-wallet.md` applies it to a
   deposit interest accrual).
3. Independently choose and apply its own FX conversion method (`BLUEPRINT.md` §7.4) if
   `currencyCode !== 'RON'`, using `grossMinor`/`currencyCode`/`periodEnd` — this event carries
   **no** FX provenance of its own (unlike `DepositInterestEventRecorded`): this module performs
   no currency conversion anywhere.
4. Use `tenantType` if the applicable tax rule's withholding treatment differs for a PJ tenant.

## Key decisions

1. **`rent_minor`/`payment_frequency`/`payment_day` live only on `rental_contract_versions`, not
   also on `rental_contracts`**, a deliberate deviation from `BLUEPRINT.md` §6.5's literal column
   list (which names them on `rental_contracts`). Duplicating them on both tables would create two
   sources of truth for "the current rent" that could silently drift the moment
   `AmendRentalContract` updates one but not the other — and §6.5's own text says an amended
   contract "must produce correct accruals on both sides of the change", which is only possible if
   there is exactly one place the rent for a given date is read from. `deposit_minor` stays on
   `rental_contracts`: a security deposit is a one-time fact of the original contract, never
   versioned by an amendment, unlike rent.

2. **`rental_accruals.rental_contract_version_id`** is a justified addition beyond §6.5's literal
   column list (which names only `rental_contract_id`). Without it there would be no way to prove
   — the exact requirement this milestone states — that an accrual generated on either side of a
   mid-period amendment used the correct terms; `RentalContractAmendmentAccrualTest` asserts
   against this column directly. `restrictOnDelete()` on its foreign key: an already-computed
   accrual must never survive its generating version being deleted out from under it.

3. **`Kern\TaxRomania\Rental\Services\AccrualPeriodBuilder` reimplements `Kern\TaxRomania\Wallet\Services\
   Interest\DepositPaymentScheduleBuilder`'s exact scheduling idiom independently, rather than
   importing it.** `docs/AGENTS.md` §6 forbids a module importing another module's classes outside
   the DTO/contract/event/application-service shapes `deptrac` enforces, and this task's path
   scope is `packages/tax-romania/src/Rental/**` only — moving the shared logic into a `Support`-level class was
   out of scope and would have created a merge/ownership question over a file this task was never
   asked to own (the identical reasoning `docs/handoff/021-wallet.md` gives for why `Wallet`'s own
   `Money`/`Decimal` are not shared with `Domain/TaxEngine`'s).

4. **No `Money` value object for this module** — see "Money" above for the full reasoning,
   including the empirical check (grepping `Domain/Wallet`'s own call sites) that motivated it.

5. **Matching reads `financial_transactions` via the raw query builder, not an Eloquent import** —
   see "Matching" above; this is a documented, sanctioned interim measure with real prior art in
   this codebase, not a shortcut invented for this task.

6. **`RentalAccrual.status` / `RentalPayment.transaction_match_status` are deliberately excluded
   from `$fillable`**, matching `Kern\Documents\Models\DocumentExtraction`'s precedent
   exactly: mass-assignment can never move an accrual to `PAID` or fabricate a payment's
   transaction link from outside this module's own sanctioned actions. **This surfaced a real bug
   during development, worth recording**: because these columns are not fillable, a freshly
   `::create()`d instance held `null` in memory for them — Eloquent does not re-read a column's
   database-level `default()` after `INSERT` — even though the *database row itself* correctly got
   `'PENDING'`/`'UNMATCHED'` from the column default, and a **fresh query** for the same row
   correctly hydrated the real value. Four `RentalPaymentMatchingTest` assertions failed on this
   exact mismatch before the fix. Fixed with Laravel's documented "default attribute values"
   mechanism (`protected $attributes = ['status' => 'PENDING']` / `['transaction_match_status' =>
   'UNMATCHED']` on the model class) — the identical value as the migration's own DB-level
   `->default(...)`, kept in the two places deliberately (one for the database, one for PHP object
   construction), not a violation of DRY's "stable domain knowledge" rule since they serve two
   different mechanisms (SQL `INSERT` vs. PHP object initialization) that happen to need the same
   literal. `RentalContract.status` gets the identical treatment for defense in depth, even though
   it *is* fillable and `CreateRentalContract` sets it explicitly — a future caller that omits it
   should not silently get `null` in memory either.

7. **No `RentalServiceProvider`.** Nothing in this module needs a container binding — every
   dependency (`OwnershipShareCalculator`, `AccrualPeriodBuilder`, `RentalDueDateCalculator`,
   `RentalTermsInForceResolver`, `TransactionMatchCandidateFinder`) is a plain, constructor-
   injectable class with no interface to bind. Matches `Domain/Wallet`'s own precedent (no service
   provider there either) — adding an empty provider "for consistency" would be exactly the
   speculative scaffolding `docs/CODE-QUALITY.md` §2 forbids.

## How to verify

Verified against the project's own already-running stack — real `postgres`/`redis`/`openbao`
containers on `anaf_data_net`, the pre-built `anaf-app` image — per
`docs/handoff/016-postgres-suite-stabilisation.md`/`docs/handoff/015-m1-integration-repairs.md`'s
documented pattern. `DB_PASSWORD` is the real `POSTGRES_PASSWORD` from the repo root `.env`.

```text
$ docker run --rm --user root -v "$PWD/apps/api":/app -w /app --entrypoint sh anaf-app -c \
    'vendor/bin/pint --test packages/tax-romania/src/Rental <rental migrations/factory/tests> deptrac.php tests/Pest.php'
  PASS .......................................................... 58 files

$ docker run --rm --user root -e APP_KEY=base64:0000...= -v "$PWD/apps/api":/app -w /app \
    --entrypoint sh anaf-app -c \
    'php -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G packages/tax-romania/src/Rental ...'
 [OK] No errors                                                    (level 9, no baseline)

$ docker run --rm --network anaf_data_net --user root \
    -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_PORT=5432 -e DB_DATABASE=kern \
    -e DB_USERNAME=kern -e DB_PASSWORD=<POSTGRES_PASSWORD from root .env> \
    -e APP_KEY=base64:0000...= -v "$PWD/apps/api":/app -w /app --entrypoint sh anaf-app -c \
    'vendor/bin/pest tests/Unit/Domain/Rental tests/Feature/Domain/Rental'
  Tests:    33 passed (141 assertions)
  # ran a second consecutive time — identical result, no state leak
  Tests:    33 passed (141 assertions)

$ # whole-project suite, real Postgres, a real (non-placeholder) APP_KEY:
  Tests:    3 warnings, 10 skipped, 609 passed (1719 assertions)
  # ran twice — identical both times, 0 failed. (With the placeholder all-zero APP_KEY used for
  # the scoped runs above, 43 pre-existing Identity/Documents/HomePage tests fail with
  # "Unsupported cipher or incorrect key length" — confirmed unrelated to this task: none of the
  # 43 failing test names contain "Rental", and none touch packages/tax-romania/src/Rental.)

$ docker run --rm --user root -v "$PWD/apps/api":/app -w /app --entrypoint sh anaf-app -c \
    'vendor/bin/deptrac analyse --config-file=deptrac.php'
  Violations 0, Skipped violations 0, Uncovered 508, Allowed 254, Warnings 0, Errors 0
```

`composer validate`/`composer audit` were not run — no Composer binary in the `anaf-app` runtime
image (same situation `docs/handoff/021-wallet.md`/`docs/handoff/014-source-registry-bnr.md`
record), and no dependency was added.

Migrations were not rolled back/reapplied in isolation for this handoff — `php artisan
migrate --force` against the shared dev Postgres (already holding every prior milestone's schema,
concurrently modified by other agents' in-flight work) reported all five new migrations already
applied and `migrate:status` confirmed all five `Ran` with no errors at any point during this
task's own verification runs.

## Interfaces exposed to other modules

- **`Kern\TaxRomania\Rental\Events\RentalAccrualRecorded`** — the M5 contract; see "Tax facts" above
  for the full field list and what a future listener must do with it.
- Every Rental table is scoped to `tax_subject_id` via `Kern\TaxRomania\Subjects\Tenancy\
  ScopedToTaxSubject` — any future module reading rental data needs an active
  `Kern\TaxRomania\Subjects\Tenancy\TenantContext` bound to the same subject.
- `rental_contracts.source_document_id`/`extraction_id` and `rental_contract_versions.
  source_document_id` are real foreign keys into `Domain/Documents`' `documents`/
  `document_extractions` tables (schema-level only, `nullOnDelete()`) — a future Documents-side
  extraction pipeline that proposes a rental contract from an uploaded lease can populate these
  directly via `Kern\TaxRomania\Rental\Actions\CreateRentalContract`'s `sourceDocumentId`/
  `extractionId` input fields; this task builds no such extraction mapping itself.
- `rental_payments.transaction_id` is a real foreign key into `Domain/Wallet`'s
  `financial_transactions` table (schema-level only, `nullOnDelete()`).

## What is NOT done

- **No HTTP/API layer** — no controllers, routes, or OpenAPI. This task built the domain layer
  only, per its own scope list.
- **No day-count proration of a partial accrual period** — see "The temporal amendment model"
  above for the explicit reasoning.
- **No amendment backdating into already-amended history** — `AmendRentalContract` only ever
  appends to the end of a contract's timeline.
- **No `AddRentalContractVersion`-style correction tool** for a mistakenly-recorded amendment —
  the only way to change history today is to accept that history and record a further amendment
  on top of it; nothing in this task's brief asked for a correction/rectification flow (D212's own
  rectificative lifecycle, per `BLUEPRINT.md` §12.3, is a separate, later concern).
- **No `Domain/TaxEngine` listener for `RentalAccrualRecorded`** — that module's own contract
  surface for consuming domain events is unchanged by this task; wiring `Event::listen()` is M5's
  job (the identical gap `docs/handoff/021-wallet.md` records for its own event).
- **No audit-chain integration for Rental CRUD** — matches `Domain/Wallet`'s own precedent
  (`docs/handoff/021-wallet.md`'s open question, still open, not decided unilaterally here either).
- **Partial rent payments do not update a `rental_accruals` row's status beyond binary
  `PENDING`/`PAID`** — see `Kern\TaxRomania\Rental\Enums\AccrualStatus`'s own docblock for why a
  `PARTIALLY_PAID` state was not added (not asked for, would be speculative scope).
- Did not touch `packages/tax-romania/src/{Wallet,Documents,TaxEngine,TaxKnowledge,Subjects,Identity,Audit,Fx}/**`
  or `app/Support/**` — other agents' concurrent scope. Did not touch `docs/STATUS.md` per this
  task's explicit instruction.

## Open questions

- **`TransactionMatchCandidateFinder`'s raw-table coupling to `financial_transactions`** (see
  "Matching" above) has no compile-time safety net — if `Domain/Wallet` renames or restructures
  that table, this finder fails at runtime with a SQL error, not a `deptrac`/PHPStan failure at
  build time. A future task could close this by having `Domain/Wallet` expose a small DTO-
  returning application service (e.g. `FinancialTransactionCandidateQuery`) that `Domain/Rental`
  calls instead — not built here, since it requires a change to `Domain/Wallet`, outside this
  task's path scope.
- **Should `Kern\TaxRomania\Rental\Actions\RecordRentalPayment` also attempt matching automatically**
  (call `ProposeRentalPaymentTransactionMatch` itself right after recording a payment), rather than
  leaving that as an explicit, separate caller step? Left separate deliberately — a payment
  recorded from a lease document extraction, a bank statement import, or a manual entry may all
  want different matching timing/triggers, and this task's brief did not specify one. Not decided
  here.
- **`ownership_percent_decimal` is not itself versioned** — if a property's ownership share
  changes over time (e.g. a sale of a fractional interest), `GenerateRentalAccruals` always uses
  the property's *current* share for every period, including already-generated historical periods
  it recomputes. `BLUEPRINT.md` §33 does not describe a temporal ownership model, and building one
  speculatively was out of this task's scope — flagged here rather than silently assumed away.

## Sources consulted

- `BLUEPRINT.md` §6.5 (rental schema, in full), §33 (co-ownership design constraint), §2.3/§2.4 (no
  fiscal logic outside `TaxEngine`; Financial Event → Tax Fact pipeline), §14.6 (never
  auto-confirm), §19.1/§21.4 (money/testing rules).
- `docs/AGENTS.md` §6 (module boundaries, cross-module traffic shapes), §8 (encryption/tenancy
  rules), §9 (fiscal sourcing — confirmed nothing in this module needed it, since no rate/threshold
  is introduced anywhere here).
- `docs/CODE-QUALITY.md` §1 (DRY's precise "incidental similarity" carve-out, applied to the
  `AccrualPeriodBuilder`/`Money` decisions), §4b (the `strict_comparison`/date-comparison trap,
  applied throughout every date comparison in this module from the start, not discovered by a
  failing test the way `Domain/Wallet` first found it).
- `docs/handoff/011-identity-tenancy.md` — `ScopedToTaxSubject`/`TenantContext`/
  `HasUuidPrimaryKey`/`FillsSubjectForeignKeyFirst`, reused exactly as documented; the
  `EloquentSubjectDekRepository` raw-query-builder precedent this module's matching finder follows.
- `docs/handoff/005-crypto-core.md` — the `Encrypted` cast, AAD binding, and both ordering traps
  (UUID-in-constructor, subject-foreign-key-fill-order), read in full before writing any model.
- `docs/handoff/021-wallet.md` — read in full before writing any code in this module: the
  money-primitive decision (reused/extended here, including the empirical re-check of whether
  `Wallet`'s own `Money` is actually used anywhere in its own actions — it is not), the
  `DepositInterestEventRecorded` event shape (the template `RentalAccrualRecorded` follows), the
  `DepositRateCoverageValidator`/`DepositPaymentScheduleBuilder` scheduling idiom
  (`AccrualPeriodBuilder` reimplements it independently, by design, per that same handoff's own
  reasoning for not sharing `Money`/`Decimal`), and the `strict_comparison` date-comparison bug it
  first discovered.
- `docs/handoff/020-tax-engine.md` — the `TaxFact` contract shape and `docs/AGENTS.md` rule 3's
  "no tax rate without a `source_section_id`" boundary, confirmed against before writing
  `RentalAccrualRecorded`'s docblock describing what M5 must do with it.
- `docs/handoff/022-document-pipeline.md` — `DocumentExtraction`'s `PROPOSED`-until-reviewed
  pattern, reused for `RentalPayment.transaction_match_status` and referenced directly for the
  `rental_contracts.source_document_id`/`extraction_id`/`rental_contract_versions.
  source_document_id` foreign keys.
- `docs/handoff/016-postgres-suite-stabilisation.md` / `docs/handoff/015-m1-integration-repairs.md`
  — the exact `RefreshDatabase` per-directory pattern and the real-Postgres, throwaway-container
  verification recipe this handoff's own verification follows; also the standing lesson ("run the
  suite against Postgres before claiming done") that caught nothing new here but was followed in
  full regardless.
- Read `Kern\TaxRomania\Wallet\Models\{FinancialAccount,FinancialTransaction,Deposit,
  DepositRatePeriod}`, `Kern\TaxRomania\Wallet\Actions\{OpenDeposit,RecordFinancialTransaction,
  GenerateDepositInterestSchedule}`, `Kern\TaxRomania\Wallet\Services\Interest\{
  DepositRateCoverageValidator,DepositPaymentScheduleBuilder,DepositInterestCalculator}`,
  `Kern\TaxRomania\Wallet\ValueObjects\{Money,Decimal}`, `Kern\Documents\Models\{Document,
  DocumentExtraction}` directly (not assumed) before writing the equivalent Rental code.
- `apps/api/pint.json`, `apps/api/deptrac.php`, `apps/api/phpstan.neon` — read directly before
  relying on their configured rules.
