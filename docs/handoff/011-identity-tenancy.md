# 011 — Identity, tenancy and authorization

**Milestone:** M1   **Status:** complete
**Date:** 2026-09-08

## What was built

The data model and scoping mechanism for `BLUEPRINT.md` §5 (Identity, tenancy, authorization): the
`users`, `tax_subjects`, `subject_memberships`, `pf_profiles`, and `security_events` tables, the
concrete `SubjectDekRepository` implementation `docs/handoff/005-crypto-core.md` left for this
module to write, and — the load-bearing deliverable — a reusable mechanism (`TenantContext` +
`ScopedToTaxSubject` + `TaxSubjectScope`) that every future business module (Wallet, Ledger,
Documents, …) uses to scope its own tables to one `tax_subject_id`, with cross-tenant isolation
proven end-to-end against a throwaway fixture table.

No authentication flow (passkeys/TOTP/login/password verification) was built — that is explicitly
a separate, later task per this task's own brief. What exists here is the data these tables and
this scoping mechanism give that future task to build on.

Entry points:
- `App\Domain\Subjects\Tenancy\TenantContext::authorizeAndActivate(string $userId, string $subjectId): void`
  — the **only** sanctioned way to bind "which subject is this request acting on behalf of."
- `App\Domain\Subjects\Tenancy\ScopedToTaxSubject` — the trait any future model with a
  `tax_subject_id` column uses to get automatic tenant-scoped reads and auto-tagged creates.
- `App\Domain\Subjects\Actions\RegisterPfSubject` — the only place `SubjectType::PF` is ever
  registered in this task's code; the reference implementation of "how to create a `TaxSubject`
  correctly" and "how to append an audit event from your module."
- `Kern\Platform\Identity\Models\User::createWithEmail()` — the only correct way to create a `User`.
- `App\Domain\Subjects\Infrastructure\EloquentSubjectDekRepository` — implements
  `App\Support\KeyManagement\Contracts\SubjectDekRepository` against **both** `tax_subjects` and
  `users` (see "Key decisions" below for why both).

## Files created / changed

Migrations (`apps/api/database/migrations/`)
- `2026_09_08_200000_create_users_table.php` — `users` per `BLUEPRINT.md` §5.1. `email_ciphertext`/
  `email_blind_index` are nullable at the DB level despite being conceptually mandatory — see
  "Key decisions" #1. Adds `encrypted_dek`/`dek_kek_version` (a `User`'s own DEK, not borrowed from
  any `TaxSubject`).
- `2026_09_08_200001_create_tax_subjects_table.php` — `tax_subjects` per `BLUEPRINT.md` §5.2/§2.2.
  `type` enum includes PFA/II/IF/SRL (schema only, no logic — §2.2). Encrypted columns nullable for
  the same two-phase-creation reason as `users`.
- `2026_09_08_200002_create_subject_memberships_table.php` — `subject_memberships` per §5.3.
  Postgres-only partial unique index (`WHERE revoked_at IS NULL`) enforcing at most one active
  membership per `(subject_id, user_id)` pair — guarded by driver, matching the `audit_events`
  append-only trigger's precedent.
- `2026_09_08_200003_create_pf_profiles_table.php` — `pf_profiles` per §6.2, keyed by
  `tax_subject_id` itself (no surrogate id — the blueprint's literal schema says "PK/FK").
  `cnp_blind_index` globally unique — the actual DB-level enforcement of "a PF must not register
  twice under a different TaxSubject" (§4.1).
- `2026_09_08_200004_create_security_events_table.php` — `security_events` per §6.1: a lightweight,
  non-hash-chained operational log, distinct from `App\Domain\Audit`'s tamper-evident chain. Its
  only writer in this task is `TenantContext` (denied cross-tenant access).
- `2026_09_08_200005_add_identity_foreign_keys_to_audit_tables.php` — the FK constraints
  `docs/handoff/006-audit-chain.md` asked for, but with `restrictOnDelete()`, not the
  `nullOnDelete()`/unqualified `->foreign()` that handoff's own snippet implied — see "Key
  decisions" #4, this needed real investigation, not just following the earlier instruction.

Identity (`apps/api/packages/platform/src/Identity/`)
- `Enums/UserStatus.php` — `ACTIVE|SUSPENDED|DELETED`.
- `Models/User.php` — the `users` Eloquent model. Implements `EncryptsWithSubjectDek`
  self-referentially (its own id, like `TaxSubject`). `scopeWhereEmail()` for blind-index login
  lookup. `createWithEmail()` is the only correct constructor — see "Key decisions" #1.

Subjects (`apps/api/app/Domain/Subjects/`)
- `Enums/SubjectType.php`, `Enums/SubjectStatus.php`, `Enums/MembershipRole.php`.
- `Models/TaxSubject.php`, `Models/PfProfile.php`, `Models/SubjectMembership.php`,
  `Models/SecurityEvent.php`.
- `Infrastructure/EloquentSubjectDekRepository.php` — the `SubjectDekRepository` implementation,
  backing both `tax_subjects` and `users` (see "Key decisions" #2).
- `Tenancy/TenantContext.php` — see "How another module scopes a query to a subject correctly"
  below; this is the section every future module's author should read first.
- `Tenancy/TaxSubjectScope.php` — the global scope `ScopedToTaxSubject` installs.
- `Tenancy/ScopedToTaxSubject.php` — the trait future modules use.
- `Tenancy/Exceptions/NoTenantContextException.php`,
  `Tenancy/Exceptions/UnauthorizedSubjectAccessException.php`.
- `DataTransferObjects/RegisterPfSubjectInput.php`.
- `Actions/RegisterPfSubject.php` — registers a PF `TaxSubject` + `PfProfile` + `OWNER`
  membership + one audit event, after a duplicate-CNP check.
- `Exceptions/DuplicateCnpException.php`.
- `SubjectsServiceProvider.php` — binds `SubjectDekRepository`, registers `TenantContext` scoped,
  and (see "Key decisions" #3) binds `BlindIndexHasher`/`BlindIndexKeyProvider`, which had no
  binding anywhere in the codebase before this task.

Wiring (outside the strict path list, small and additive — same justification handoff 005 gave for
its own equivalent edits)
- `apps/api/bootstrap/providers.php` — registered `SubjectsServiceProvider`.
- `apps/api/deptrac.php` — added `Ruleset::forLayer($subjects)->accesses($support, $audit)` (only
  new grant; `Identity`/`Subjects` already had `Support` access from the M0 scaffold).

Factories (`apps/api/database/factories/`)
- `UserFactory.php` — two-phase (`configure()`'s `afterCreating` sets a random unique email after
  the row exists), plus a `withEmail()` state.
- `TaxSubjectFactory.php` — two-phase (`configure()` sets a display name after the row exists),
  plus a `withFiscalIdentifier()` state. No `PfProfile`/`SubjectMembership` factories — see "Key
  decisions" #5.

Tests (`apps/api/tests/Feature/Domain/Subjects/`) — all real, all executed, see "How to verify":
- `RawDatabasePlaintextTest.php` — Milestone 1's definition of done: creates a `User` and a full PF
  `TaxSubject`+`PfProfile` with known email/CNP/name/address, queries the **raw** columns, asserts
  none of the plaintexts (or their base64-decoded bytes) appear anywhere, including in the DEK
  column itself.
- `BlindIndexLookupTest.php` — email lookup tolerant of case/whitespace; CNP duplicate detection
  tolerant of spaces/dashes. Explains in its own docblock why diacritic folding isn't separately
  exercised here (neither field uses `Normalizer::text()`; that's already covered by crypto-core's
  own `NormalizerTest`).
- `MembershipAuthorizationTest.php` — role resolution; denial + `security_events` row for no
  membership; a **revoked** membership denies identically to no membership; unbound context throws.
- `SubjectDekLifecycleTest.php` — no DEK right after a bare insert; DEK created on first encrypted
  write and reused after; a `User`'s DEK is independent of any `TaxSubject`'s.
- `RegisterPfSubjectTest.php` — OWNER membership created; a verifiable audit event recorded
  (`ChainVerifier::verify()` reports `ok`); duplicate-CNP rejected; two different CNPs both succeed.
- `SubjectMembershipUniquenessTest.php` — Postgres-only partial-unique-index behaviour, self-skips
  under sqlite (matching `AuditChainServiceTest`'s own precedent).
- `CrossTenantIsolationTest.php` — **the mandatory test.** Read/list/update/delete denial across
  subjects, auto-tagging on create, fail-closed with no context bound, and the explicit
  `withoutGlobalScope` escape hatch — against `Fixtures/ScopedFixtureRecord.php`, a throwaway
  fixture model standing in for a future real business table (same precedent as crypto-core's own
  `EncryptedNoteA`/`EncryptedNoteB`).

## How another module scopes a query to a subject correctly

This is the part every later milestone depends on getting right.

**1. Put `tax_subject_id` on your table**, and use the trait on your model:

```php
use App\Domain\Subjects\Tenancy\ScopedToTaxSubject;

final class FinancialAccount extends Model
{
    use ScopedToTaxSubject;

    protected $fillable = ['tax_subject_id', /* ... your own columns ... */];
}
```

You will need to add your module's own `deptrac.php` grant
(`Ruleset::forLayer($yourModule)->accesses($support, $subjects)`) in the same change — an
undeclared dependency on `Subjects` is invisible to Deptrac, not automatically forbidden, so add
it deliberately.

**2. Somewhere in your request/job lifecycle (a middleware, a controller action, a job's own setup —
this task builds none of those), bind the subject once, with authorization:**

```php
use App\Domain\Subjects\Tenancy\TenantContext;

app(TenantContext::class)->authorizeAndActivate($currentUserId, $requestedSubjectId);
```

This throws `UnauthorizedSubjectAccessException` (and records a `security_events` row) if the user
has no active membership for that subject — including a **revoked** one, which fails identically to
"never existed." There is no lower-level "just trust me and set a subject id" method exposed. This
is what makes the *unsafe* thing hard to write: skipping this call means every subsequent
`ScopedToTaxSubject` query throws `NoTenantContextException` rather than silently returning
nothing or everything.

**3. From then on, `FinancialAccount::query()` (or `::create()`, `::find()`, any Eloquent access)
is automatically scoped**, and a create automatically stamps `tax_subject_id` if you didn't set it
yourself:

```php
FinancialAccount::create(['name' => 'Current account']); // tax_subject_id filled in for you
FinancialAccount::query()->get();                        // only this subject's rows, always
```

**4. The only escape hatch is explicit and named**, for genuinely cross-tenant system operations
(duplicate-CNP detection, admin tooling):

```php
FinancialAccount::withoutGlobalScope(\App\Domain\Subjects\Tenancy\TaxSubjectScope::class)->get();
```

**One real trap to know about**: if your model *also* has an encrypted field (`Casts\Encrypted`),
you cannot rely on `ScopedToTaxSubject`'s auto-fill-on-`creating` for `tax_subject_id` — that hook
fires on the `creating` event, but `Casts\Encrypted::set()` needs `tax_subject_id` *during*
attribute assignment, which happens earlier (inside `fill()`/construction). Pass `tax_subject_id`
explicitly in your constructor array in that case (and use
`App\Support\Crypto\Concerns\FillsSubjectForeignKeyFirst` too) — `ScopedToTaxSubject`'s own
docblock documents this interaction in detail.

## Key decisions

1. **`users.email_ciphertext`/`email_blind_index` and `tax_subjects.display_name_ciphertext`/
   `fiscal_identifier_ciphertext` are nullable at the database level, despite being conceptually
   mandatory.** Both `User` and `TaxSubject` encrypt a field using **their own id** as the
   encryption subject (`encryptionSubjectId()` returns `$this->getKey()`). `Casts\Encrypted`
   needs the row to already exist in the database before it can create that row's own DEK
   (`EloquentSubjectDekRepository::find()`/`save()` query by id) — but a single-statement
   `Model::create([...encrypted field...])` tries to encrypt *during construction*, before any
   `INSERT` has run. **Found empirically, not by inspection**: an early version of this task's own
   tests used `User::create(['email_ciphertext' => ...])` and failed with "Could not create a
   wrapped DEK... no row without an existing DEK was found" — the row genuinely didn't exist yet.
   The fix is the same two-write pattern in every case: insert a bare row first (no encrypted
   columns set), then assign the encrypted columns in a second `save()`, which is the write that
   triggers first-use DEK creation. `App\Domain\Subjects\Actions\RegisterPfSubject` does this for
   `TaxSubject`; `Kern\Platform\Identity\Models\User::createWithEmail()` does it for `User`, and is
   the **only** sanctioned way to create a `User` — documented prominently in that class's own
   docblock precisely so nobody rediscovers this failure mode by trial and error. `TaxSubjectFactory`
   and `UserFactory` both use the identical `configure()`/`afterCreating()` pattern for the same
   reason a factory's `definition()` cannot include either encrypted column.

2. **`EloquentSubjectDekRepository` backs two tables (`tax_subjects` and `users`), not one**,
   despite handoff 005 saying "implement against `tax_subjects`." Resolving why: `App\Support\
   KeyManagement\SubjectDekService` is a single scoped container binding wired to exactly one
   `SubjectDekRepository` implementation — but this task gives **both** `TaxSubject` and `User`
   their own per-record DEK (see decision #3 below for why `User` needs one at all). Rather than
   inventing a second cast/service pair for `User` (real, avoidable duplication of the already-
   tested `Casts\Encrypted`/`SubjectDekService` machinery — `docs/CODE-QUALITY.md` §1), one
   repository transparently checks both tables by id, using the plain query builder
   (`DB::table(...)`, never an Eloquent model import from the other module), so it needs no extra
   `deptrac.php` grant. This is a considered answer to handoff 005's own explicit "Open question"
   about how `save()`'s create-only race should be resolved — not an oversight of that handoff's
   wording. A cleaner long-term home for this class might be a `Support`-level provider once one
   exists for exactly this purpose; nothing here assumes it stays in `Subjects` forever.

3. **A `User` gets its own per-record DEK, independent of any `TaxSubject`'s.** `BLUEPRINT.md` §4.2's
   envelope-encryption design is written entirely in terms of "per-`TaxSubject` DEK," and §4.3
   flags email as needing an explicit design decision ("dacă se folosește ca identity login,
   modelul trebuie proiectat explicit"). A `User` is not a `TaxSubject` and can hold memberships
   across several subjects with independent blast radii (`SubjectMembership`) — there is no single
   tenant whose key could legitimately own a user's own email without an arbitrary, unjustifiable
   choice. Giving every `User` its own DEK (self-referential, exactly like `TaxSubject`) extends
   ADR 0002's actual design intent ("bound blast radius") to the one entity type the ADR didn't
   explicitly cover, rather than inventing a third key class or leaving email unencrypted contrary
   to the literal `users.email_ciphertext` column `BLUEPRINT.md` §5.1 specifies.

4. **The `audit_events`/`audit_chain_heads` foreign keys use `restrictOnDelete()`, not
   `nullOnDelete()`** (which is what handoff 006's own snippet showed). This needed checking, not
   assuming: `App\Domain\Audit\Support\AuditEventHasher::payload()` includes `subject_id`/
   `actor_id` in the exact fields `event_hash` is computed over (confirmed by reading the class,
   not assumed), and `audit_events` carries a `BEFORE UPDATE OR DELETE` trigger that raises on any
   mutation. A `SET NULL` foreign-key action *is* an `UPDATE` under the hood — so `nullOnDelete()`
   would make the trigger raise `audit_events is append-only: UPDATE is not permitted` the moment
   any audit history exists for the subject/actor being deleted, aborting the whole transaction;
   and even without the trigger, silently nulling those columns on an already-hashed row would make
   `ChainVerifier::verify()` recompute a different hash than the one stored, reporting a false
   tamper detection. `restrictOnDelete()` is also the semantically correct choice independent of
   that: `BLUEPRINT.md` §25 already describes account deletion as crypto-shredding (destroy the
   wrapped DEK; the row becomes permanently unrecoverable garbage) plus a status change, never a
   raw `DELETE FROM tax_subjects`/`users` — a literal SQL-level row delete while audit history
   still references it is exactly the case this constraint should block, not silently allow.
   **This surfaced a real, load-bearing consequence for the Audit module**: see "Known issue found
   outside this task's scope" below.

5. **No `PfProfile` or `SubjectMembership` factory.** `PfProfile`'s primary key *is* its owning
   `TaxSubject`'s id (no surrogate id — see decision #6) and its `cnp_blind_index` must be globally
   unique, so a generic factory would either need a real `TaxSubject` passed in every time (no
   value over `PfProfile::create([...])` directly) or would risk colliding CNPs across tests.
   `SubjectMembership` similarly has no interesting "random" state worth generating — every test
   needing one creates it directly with an explicit role. Per `docs/CODE-QUALITY.md` §2, a factory
   that exists only to wrap a two-line `::create()` call with no randomised value worth generating
   is exactly the kind of abstraction with a single, non-generic use that shouldn't exist.

6. **`pf_profiles` has no surrogate id — `tax_subject_id` is both primary key and foreign key**,
   following the blueprint's literal §6.2 schema. This means `PfProfile` cannot use
   `HasUuidPrimaryKey` (which always assigns a *fresh* random id — wrong here, since the id must be
   an already-existing `TaxSubject`'s), only `FillsSubjectForeignKeyFirst`, with
   `$primaryKey = 'tax_subject_id'` / `$incrementing = false` / `$keyType = 'string'` set directly.

7. **Registration order matters for a UUID-primary-keyed model with no encrypted fields, too** —
   found while debugging `tests/Feature/Domain/Subjects/Fixtures/ScopedFixtureRecord.php`
   (initially hand-rolled a `creating` hook assigning `Str::uuid7()` without also setting
   `$incrementing = false`/`$keyType = 'string'`). Eloquent's default `performInsert()` treats any
   model as auto-incrementing unless told otherwise, and — even though the manually-assigned UUID
   *was* included in the `INSERT` statement — it then unconditionally overwrites the model's `id`
   attribute with `PDO::lastInsertId()` (SQLite's internal `rowid`, e.g. `"1"`) after insert,
   silently corrupting the id every subsequent query in that test file depended on. Reusing
   `App\Support\Crypto\Concerns\HasUuidPrimaryKey` (which sets both) fixed it immediately. This has
   nothing to do with encryption — any future model using a UUID primary key, encrypted fields or
   not, should use that trait (or independently replicate both of its effects) rather than a
   hand-rolled `creating` hook.

8. **The duplicate-CNP pre-check in `RegisterPfSubject` runs before, not inside, the DB
   transaction**, so a genuine race between two concurrent registrations of the same CNP is caught
   by the `pf_profiles.cnp_blind_index` unique constraint (a raw `QueryException`), not by the
   friendly `DuplicateCnpException`. Not fixed here — an application-level `SELECT ... FOR UPDATE`
   or a retry-and-recheck wrapper would close this, but nothing in this task's brief asked for
   race-safe registration UX, only for the duplicate to be **detectable and blocked**, which it is.

9. **`security_events` lives under `Domain\Subjects`, not `Domain\Identity`**, even though
   `BLUEPRINT.md` §6.1 lists it in the same schema group as `users`/`audit_events`. This project's own
   `docs/ARCHITECTURE.md` module table does not assign it to either module, and its only writer in
   this task is `TenantContext` (in `Subjects`) — placing it there avoids an otherwise-unneeded
   `Subjects → Identity` Eloquent-model dependency. A later task adding login-failure security
   events from `Identity` writes into the same table via `DB::table('security_events')->insert(...)`
   or a small DTO-based service call, never by importing `App\Domain\Subjects\Models\SecurityEvent`
   directly (`docs/AGENTS.md` §6).

## Dependencies

None added. `Illuminate\Database\Eloquent\Attributes\UseFactory` (Laravel 13's own PHP-attribute
factory resolution) is used on `User`/`TaxSubject` instead of overriding `newFactory()` — needed
because both live outside Laravel's default `App\Models` convention Eloquent's factory-name
guesser assumes, confirmed by an actual `Class "Database\Factories\Domain\Identity\Models\
UserFactory" not found` error before switching to the attribute (not assumed from documentation).

## How to verify

Host PHP is 8.3 and cannot run this tree (Pest 5 needs PHP ^8.4). Used a throwaway
`php:8.5-cli-trixie` image with `libicu-dev`/`ext-intl` added (per this task's explicit
instructions — without it the blind-index normalizer correctly throws instead of degrading,
per `docs/handoff/010-crypto-hardening.md`), plus `pdo_pgsql`, `bcmath`, `pcntl`, `redis`,
`sodium`, and Composer. Built and discarded — not a deliverable, matching every prior M1 handoff's
documented approach.

```text
$ composer validate --strict
./composer.json is valid

$ composer audit
No security vulnerability advisories found.

$ vendor/bin/pint --test
  PASS ......................................................... 134 files

$ php -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G   # level 9, no baseline
 [OK] No errors

$ vendor/bin/deptrac analyse --config-file=deptrac.php
  Violations 0, Skipped violations 0, Uncovered 80, Allowed 35, Warnings 0, Errors 0

$ vendor/bin/pest                                    # default sqlite connection, full suite
  Tests:    20 skipped, 152 passed (317 assertions)
  # 20 skipped = other agents' live-service-dependent tests (OpenBao, MinIO) + the two
  # Postgres-only tests in this task's own SubjectMembershipUniquenessTest.php, all
  # correctly self-skipping — not a gap.
```

Migrations and Postgres-only behaviour, against a real `pgvector/pgvector:pg18-trixie` container
(this project's pinned tag), torn down afterward — not part of this deliverable:

```text
$ php artisan migrate --force
  ... (all ten migrations, including this task's six) ... DONE

$ php artisan migrate:rollback --step=6 --force && php artisan migrate --force
  # rolls back cleanly and re-applies cleanly

$ DB_CONNECTION=pgsql ... vendor/bin/pest tests/Feature/Domain/Subjects
  Tests:    26 passed (113 assertions)
  # every test in this task's own suite, including the two Postgres-only partial-unique-index
  # tests that correctly self-skip under sqlite
```

**Do not run the full unfiltered suite against `DB_CONNECTION=pgsql`** — see "Known issue found
outside this task's scope" below; two pre-existing Audit tests now fail under Postgres because of
a foreign key this task added at handoff 006's own request.

## Interfaces exposed to other modules

- `App\Domain\Subjects\Tenancy\TenantContext::authorizeAndActivate(string, string): void`,
  `::currentSubjectId(): string`, `::currentRole(): MembershipRole`, `::hasActiveSubject(): bool`.
- `App\Domain\Subjects\Tenancy\ScopedToTaxSubject` — the trait, `App\Domain\Subjects\Tenancy\
  TaxSubjectScope` — the scope class other modules pass to `Model::withoutGlobalScope()`.
- `App\Domain\Subjects\Tenancy\Exceptions\{NoTenantContextException,UnauthorizedSubjectAccessException}`.
- `App\Domain\Subjects\Actions\RegisterPfSubject` + `DataTransferObjects\RegisterPfSubjectInput` —
  callable from a future controller/command once auth exists.
- `App\Domain\Subjects\Exceptions\DuplicateCnpException`.
- `Kern\Platform\Identity\Models\User::createWithEmail()`, `::scopeWhereEmail()`.
- `App\Domain\Subjects\Models\{TaxSubject,PfProfile,SubjectMembership,SecurityEvent}` — Eloquent
  models, importable only from within `Subjects`/`Identity` per `deptrac.php`'s current rules;
  another module needing subject/membership data goes through `TenantContext` or a future
  application service, not a direct model import.

## What is NOT done

- **No authentication flow** — no passkeys, TOTP, password verification, session management, or
  login endpoint. Explicitly out of this task's scope. `User.password_hash`/`status` exist as
  columns only.
- **No HTTP layer at all** — no controllers, routes, form requests, or middleware. This task builds
  the data model and the scoping *mechanism*; wiring `TenantContext::authorizeAndActivate()` into
  an actual request lifecycle (e.g. a `ResolveTaxSubject` middleware reading a route parameter) is
  for whichever task builds the first real HTTP endpoint needing it.
- **No PFA/II/IF/SRL logic** — `SubjectType` includes all five cases in the schema (§2.2), but only
  `PF` has any registration logic (`RegisterPfSubject`). This is deliberate, not a gap.
- **No membership grant/revoke service** — tests create/revoke `SubjectMembership` rows directly
  (`::create()`, setting `revoked_at`). An invitation/revocation flow with its own validation,
  notification, and audit trail is a reasonable follow-up but wasn't asked for here.
- **Race-safe CNP registration** — see "Key decisions" #8: the duplicate is always detected and
  blocked, but a genuine concurrent race surfaces as a raw `QueryException`, not the friendly
  `DuplicateCnpException`.
- **No signed Merkle checkpoint** — unrelated to this task; still open per handoff 006.
- Did not touch `apps/spv-browser/**`, `docker/**`, `app/Support/**` (beyond binding two of its
  interfaces from this module's own service provider — see "Key decisions" #2), or any other
  domain module.

## Known issue found outside this task's scope (not fixed here)

**Adding the `audit_events`/`audit_chain_heads` → `tax_subjects` foreign keys (this task's own
migration, done at `docs/handoff/006-audit-chain.md`'s explicit request) breaks two pre-existing
Postgres-only Audit tests**: `tests/Feature/Domain/Audit/AuditChainServiceTest.php` and
`tests/Feature/Domain/Audit/AuditChainConcurrencyTest.php` both call `AuditChainService::record()`
with synthetic `Str::uuid()` subject ids that were never backed by a real `tax_subjects` row —
which was correct and unavoidable when handoff 006 was written (`tax_subjects` didn't exist yet),
but now violates the new `restrictOnDelete()` foreign key with `SQLSTATE[23503]: Foreign key
violation` on every insert into `audit_chain_heads`/`audit_events` with a non-existent subject id.
Confirmed directly: `vendor/bin/pest tests/Feature/Domain/Audit` against a real Postgres connection
now shows 10 failures, all the identical FK-violation shape, none of them present before this
task's migration existed.

This is `app/Domain/Audit/**` test code, outside this task's file scope
(`packages/platform/src/Identity/**`/`app/Domain/Subjects/**` only) — not fixed here, per `docs/AGENTS.md` §10
("record a bug found outside your scope, don't fix it silently in an unrelated change"). The fix
is mechanical: those two test files need to create a real `TaxSubject`
(`App\Domain\Subjects\Models\TaxSubject::factory()->create()`, which this task provides) before
calling `AuditChainService::record()` with its id, instead of a bare `Str::uuid()`. **I could not
edit `docs/STATUS.md`'s "Known issues" table myself** — this task's instructions explicitly
excluded that file — so whoever next touches `docs/STATUS.md` should copy this entry there, exactly
as handoff 006 asked for its own out-of-scope finding.

## Open questions

- Should `EloquentSubjectDekRepository`'s two-table design (Key decisions #2) move to a
  `Support`-level location once a natural home exists there, so `Subjects` doesn't need to know
  about `users` by table name at all? Left as a `Subjects`-owned class for now since no such
  `Support` provider exists yet and moving it wasn't asked for.
- Should the duplicate-CNP check in `RegisterPfSubject` be made race-safe (Key decisions #8)? Not
  needed for this task's stated acceptance criteria, but worth deciding before a real onboarding
  flow launches under concurrent load.
- The Audit test regression above needs a human or the Audit module's owner to actually apply the
  mechanical fix — flagging rather than fixing across module boundaries.

## Sources consulted

- `BLUEPRINT.md` §2.2 (PF-first, entity-agnostic), §4.1/§4.3/§4.4 (threat model, encrypted fields,
  blind indexes), §5 (identity/tenancy schema), §6.1/§6.2 (table definitions), §21.4 (security
  tests), §25 (crypto-shredding/deletion).
- `docs/AGENTS.md` §6 (module boundaries, sanctioned cross-module traffic shapes), §8 (blind index
  formula, mandatory cross-tenant test), §10 (recording out-of-scope bugs).
- `docs/handoff/005-crypto-core.md` — read in full before writing any code; the `SubjectDekRepository`
  contract, the two ordering traps (`HasUuidPrimaryKey`, `FillsSubjectForeignKeyFirst`), and the
  explicit "Open question" this task's decision #2 resolves.
- `docs/handoff/010-crypto-hardening.md` — why the blind-index normalizer throws instead of
  degrading, and the verification-container requirement (`ext-intl`) this task followed.
- `docs/handoff/006-audit-chain.md` — how to append an audit event (`AuditChainService::record()`),
  and the explicit ask (Key decisions #6 there) this task's migration `2026_09_08_200005_*`
  implements — with a correction (decision #4 above) found by actually reading
  `AuditEventHasher::payload()` and the append-only trigger before trusting that ask literally.
- `docs/adr/0002-envelope-encryption.md` — per-subject DEK design, the global blind-index key's
  deliberate cross-tenant tradeoff, re-read before extending it to `User` (decision #3).
- `docs/ARCHITECTURE.md` — module ownership table (confirmed `security_events` is unassigned
  before placing it, decision #9).
- `docs/CODE-QUALITY.md` §1/§2 — DRY nuance and no-pointless-abstraction, applied to decisions
  #2 and #5.
- Framework/library source read directly, not assumed: `vendor/laravel/framework/src/Illuminate/
  Database/Eloquent/Factories/HasFactory.php` and `.../Attributes/UseFactory.php` (factory-name
  resolution, confirmed via an actual `Class ... not found` error before switching approach),
  `Model::performInsert()`/`insertAndSetId()` (confirmed the `$incrementing`/`lastInsertId()`
  interaction via a real reproduction, not by inspection alone), `App\Domain\Audit\Support\
  AuditEventHasher` (confirmed `subject_id`/`actor_id` are part of the hashed payload before
  choosing `restrictOnDelete()`).
- All empirical claims in "Key decisions" were reproduced with a standalone PHP script against the
  real container before being written down here, not inferred from reading code alone.
