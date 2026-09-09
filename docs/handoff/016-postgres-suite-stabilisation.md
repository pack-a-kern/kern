# 016 — Postgres suite stabilisation (coordinator)

**Milestone:** M1/M2   **Status:** complete   **Date:** 2026-09-08

## Context
After M2 landed, the suite passed on sqlite (228 passed) but **76 tests failed on real Postgres**.
Two distinct defects, both hidden by sqlite.

## Defect 1 — the audit trigger function made migrations non-repeatable

`2026_09_08_100001_create_audit_events_table.php` created its append-only guard with
`CREATE FUNCTION audit_events_deny_mutation()`.

`migrate:fresh` drops **tables**, not **functions**, and never calls `down()` (which *does* drop it).
So the function survived, and the next `up()` failed with:

```
SQLSTATE[42723]: Duplicate function: function "audit_events_deny_mutation" already exists
```

Because M2's tests call `migrate` per test, every one of them failed. Invisible on sqlite, which
has no such function at all.

**Fix:** `CREATE OR REPLACE FUNCTION`. This makes the migration idempotent, which a migration that
creates database objects outside Laravel's schema builder must be.

## Defect 2 — fixed fixture rows with no isolation

The remaining failures were `UniqueConstraintViolationException`: the Fx/TaxKnowledge suites insert
fixed rows (a BNR `fx_sources` row, a source-registry entry) and relied on sqlite giving every test
a fresh in-memory database. Against a persistent Postgres the second test collided with the first.

**Fix:** `RefreshDatabase`, applied **per-directory in `tests/Pest.php`, not globally**:

```php
uses(RefreshDatabase::class)->in('Feature/Domain/Fx');
uses(RefreshDatabase::class)->in('Feature/Domain/TaxKnowledge');
uses(RefreshDatabase::class)->in('Feature/Domain/Subjects');
```

`Feature/Domain/Audit` is deliberately **excluded**. `AuditChainConcurrencyTest` forks OS processes
that open their own connections and must see *committed* rows; wrapping it in a transaction would
make the children observe an empty table and would silently destroy the strongest concurrency
guarantee in the codebase. Verified afterwards that it still genuinely **runs and passes**, not
skips.

## Result — verified, not asserted

| Check | Result |
|---|---|
| Postgres, fresh DB, run 1 | **242 passed, 6 skipped, 0 failed** |
| Postgres, run 2 (leak check) | **242 passed — identical** |
| sqlite | 228 passed |
| Audit concurrency test | passes, genuinely executed |
| Pint | PASS, 208 files |
| PHPStan level 9, no baseline | No errors |
| Deptrac | 0 violations |

## Also done: a latent trap documented
M2 stores rates as `string(32)` rather than `decimal`, because sqlite's `NUMERIC` affinity silently
round-trips through a PHP float (I reproduced this: `4.987612345678901234` → `4.987612345678901`).
The storage choice is correct and exact, but it means **SQL compares these columns
lexicographically** — `"10" < "9"`. A prominent warning is now in all three fx migrations: never
`orderBy`, range-compare or aggregate a rate column in SQL; filter by `rate_date`, load, and compare
in PHP via the BCMath-backed `DecimalRate`.

## The standing lesson
This is the third time sqlite-only verification hid a Postgres defect. **Run the suite against
Postgres before claiming done.** CI does.
