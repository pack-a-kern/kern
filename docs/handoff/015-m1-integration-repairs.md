# 015 — M1 integration repairs (coordinator)

**Milestone:** M1   **Status:** complete   **Date:** 2026-09-08

## Context
Modules 005 (crypto), 006 (audit), 011 (identity) and 012 (object store) were each green in
isolation. Running them **together against real Postgres** surfaced three defects that no
individual agent could have seen. All three shared one root cause: **tests that only ever ran
against sqlite**.

## The three defects

### 1. Audit tests broke on Identity's new foreign keys
Handoff 011 added `audit_events.subject_id → tax_subjects` and `actor_id → users`
(`restrictOnDelete`, correctly — `SET NULL` is itself an UPDATE and would fire the append-only
trigger). The audit tests predated those tables and used synthetic UUIDs, so every insert hit
`SQLSTATE[23503] foreign key violation`.

**Fix:** the audit tests now create real `TaxSubject` / `User` rows via factories. Because that
provisions a per-subject DEK, they also bind `InMemoryKeyManagementService` and a test
`BlindIndexHasher` — matching the convention already used by the Subjects tests. The audit suite
tests the chain, so depending on a live OpenBao would be the wrong coupling.

### 2. Crypto cast tests used non-UUID identifiers
`EncryptedCastTest` declared `uuid('tax_subject_id')` but inserted the literal `'subject-1'`.
Sqlite's loose typing accepted it; Postgres rejected it with
`SQLSTATE[22P02] invalid input syntax for type uuid`. Replaced with valid fixed UUIDs.

### 3. Key-record tests leaked state between runs
`EncryptionKeyRecordsMigrationTest` reused a fixed identifier and never cleaned up. Sqlite hands
each test a fresh in-memory database, so it passed; against a persistent Postgres the second test
collided with the first's rows.

**Fix:** a unique identifier per test. **Global `RefreshDatabase` was deliberately rejected** — it
wraps each test in a transaction, and the audit concurrency test forks processes that must see
*committed* rows, so a suite-wide transaction would silently break the strongest test we have.

## How to verify
```
docker compose up -d postgres redis
docker run --rm --network anaf_data_net --user root \
  -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_PORT=5432 \
  -e DB_DATABASE=... -e DB_USERNAME=... -e DB_PASSWORD=... \
  -v "$PWD/apps/api":/app -w /app --entrypoint sh anaf-app -c 'vendor/bin/pest'
```
Result: **166 passed, 6 skipped** on Postgres — identical on a second consecutive run, proving no
state leaks. **152 passed, 20 skipped** on sqlite. Pint clean; Deptrac 0 violations.

## The lesson for every future agent
**A green sqlite run is not evidence.** All three defects were invisible under sqlite and obvious
under Postgres. Sqlite accepts loose types and gives every test a fresh database, hiding both
type errors and state leakage. Run your suite against the real engine before claiming done —
CI does, and it will find what you skipped.

## What is NOT done
- PHPStan currently reports 20 errors, all in `app/Domain/Fx/**` and `packages/legislation/src/**`
  (handoff 014's in-flight work). Not touched — that agent owns those files.
- Authentication (passkeys, TOTP, recovery codes, session lifecycle) is still outstanding for M1.
