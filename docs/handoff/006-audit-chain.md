# 006 — Audit chain (append-only, hash-linked) and sensitive-log sanitizer

**Milestone:** M1   **Status:** complete
**Date:** 2026-09-08

## What was built

Two independent deliverables per the task brief, both scoped to `BLUEPRINT.md` §4.8 (audit tamper
evidence) and §4.10/§23 (no PII in logs):

1. **`Kern\TaxRomania\Audit`** — a per-subject, append-only, hash-chained audit trail with a
   database-level (not just application-level) tamper barrier, plus a service that walks a chain
   and reports the exact sequence number where it breaks.
2. **`App\Support\Logging\SensitiveDataProcessor`** — a Monolog processor, wired into every log
   channel via `config/logging.php`'s `tap` option, that redacts CNP, IBAN, passwords, tokens/API
   keys, `Authorization` headers, and OTP codes — by key name and by pattern, recursively through
   nested arrays/objects — before a record reaches any handler.

Entry points for other modules:
- `Kern\TaxRomania\Audit\Services\AuditChainService::record(AuditEventInput $input): AuditEventRecord`
- `Kern\TaxRomania\Audit\Services\ChainVerifier::verify(string $chainKey): ChainVerificationResult`
- `config/logging.php` — every real channel now carries `'tap' => [RedactSensitiveData::class]`;
  nothing else needs to change for a new channel to inherit redaction (just add the same line).

## Files created / changed

Domain/Audit
- `packages/tax-romania/src/Audit/Support/CanonicalJson.php` — deterministic, byte-stable JSON encoder. This is
  the single place the canonical-JSON rule is implemented; see **Canonical JSON rule** below.
- `packages/tax-romania/src/Audit/Support/AuditEventHasher.php` — the *one* place
  `event_hash = SHA256(canonical_json(event_without_hash) || previous_hash)` is computed. Both the
  writer and the verifier call this so they can never independently drift apart.
- `packages/tax-romania/src/Audit/Services/AuditChainService.php` — the module's public entry point (see
  **How to append an event** below). Owns chain-key derivation, row-locking, sequence assignment,
  and the insert.
- `packages/tax-romania/src/Audit/Services/ChainVerifier.php` — walks one chain in sequence order, recomputing
  each event's hash from its own stored columns, and reports the first sequence that fails.
- `packages/tax-romania/src/Audit/ChainVerificationFailureReason.php` — enum of the seven ways a chain can fail
  verification (gap, duplicate sequence, wrong start, previous/event hash mismatch, head mismatch,
  chain-not-found-but-head-says-otherwise).
- `packages/tax-romania/src/Audit/DataTransferObjects/AuditEventInput.php` — what a caller passes to `record()`.
  `occurredAt` is a mandatory constructor argument (`AuditEventInput::occurringNow(...)` for the
  common "right now" case) — no implicit clock inside the DTO itself, per `docs/AGENTS.md` §6.
- `packages/tax-romania/src/Audit/DataTransferObjects/AuditEventRecord.php` — what `record()` returns: exactly
  the row that was written.
- `packages/tax-romania/src/Audit/DataTransferObjects/ChainVerificationResult.php` — what `verify()` returns:
  `ok`, `verifiedCount`, and on failure `brokenAtSequence` + `reason`.
- `packages/tax-romania/src/Audit/Models/AuditEvent.php` — read-oriented Eloquent model over `audit_events`.
  Overrides `performUpdate()`/`performDeleteOnModel()` to throw `LogicException` as a second,
  application-layer line of defence (the real enforcement is the database trigger, see below);
  `$guarded = ['*']` additionally blocks mass-assignment (`->update([...])`) even earlier, with its
  own `MassAssignmentException`.
- `packages/tax-romania/src/Audit/Models/AuditChainHead.php` — Eloquent model over `audit_chain_heads` (the one
  table in this module that is legitimately mutated on every append).

Migrations
- `database/migrations/2026_09_08_100000_create_audit_chain_heads_table.php` — `chain_key` (PK,
  string), `subject_id` (nullable uuid, **no FK** — see **Open questions**), `last_sequence`,
  `last_event_hash`, `timestampsTz()` (UTC explicitly, not the bare `timestamps()` default).
- `database/migrations/2026_09_08_100001_create_audit_events_table.php` — the `audit_events` table
  per `BLUEPRINT.md` §4.8 plus `chain_key` (see **Key decisions** #1). `occurred_at` is
  `timestampTz('occurred_at', precision: 6)` — **not** the `timestampTz()` default of precision 0.
  Also creates a Postgres trigger (see **Append-only enforcement** below) guarded by
  `DB::connection()->getDriverName() === 'pgsql'`, so the migration is a no-op past table creation
  on the sqlite connection most of this app's Feature tests use.

Logging
- `app/Support/Logging/SensitiveDataProcessor.php` — the redaction logic itself.
- `app/Support/Logging/RedactSensitiveData.php` — the Laravel "tap" class that pushes the processor
  onto a channel's underlying Monolog logger.
- `config/logging.php` — added `'tap' => [RedactSensitiveData::class]` to `single`, `daily`,
  `monthly`, `stderr`, `syslog`, `errorlog`. Not added to `stack` (inherits from the channels it
  fans out to), `null` (discards everything before it could be redacted), or `emergency`
  (bypasses the tap pipeline entirely — see **What is NOT done**).

Tests (all real, all executed — see **How to verify**)
- `tests/Unit/Domain/Audit/CanonicalJsonTest.php` — key-order independence, exact pinned byte
  output, float rejection, non-string-key rejection, null preservation, list-vs-object handling.
- `tests/Unit/Support/Logging/SensitiveDataProcessorTest.php` — every redaction category
  (CNP, IBAN, password, token/API-key under multiple naming conventions, JWT, `Authorization`
  header, OTP by key and inline in text), nested arrays, a plain object, a `Throwable`, a circular
  object reference, and a non-sensitive-data passthrough check.
- `tests/Feature/Support/Logging/SensitiveLogRedactionFeatureTest.php` — end-to-end: writes through
  the real `single` channel to an actual file on disk and asserts the raw secret never appears,
  including through `{placeholder}` substitution.
- `tests/Feature/Domain/Audit/AuditChainServiceTest.php` — **requires Postgres**, self-skips
  otherwise. Builds a 3-event chain and checks linkage; two subjects get independent chains; a
  null-subject event lands on the system chain; the database rejects `UPDATE`/`DELETE` directly;
  the Eloquent model's three lines of defence (mass-assignment guard, `performUpdate`,
  `performDeleteOnModel`); the **tamper test** (see below); an empty chain verifies trivially ok;
  `hashPayload()` is deterministic.
- `tests/Feature/Domain/Audit/AuditChainConcurrencyTest.php` — **requires Postgres + ext-pcntl**,
  self-skips otherwise. See **Concurrency proof** below.

## Canonical JSON rule (`Kern\TaxRomania\Audit\Support\CanonicalJson`)

Given the same logical data, the encoding must always produce the same bytes, regardless of how
the caller happened to build the array. The rules, all enforced in code (not just documented):

1. **Key order**: associative arrays are sorted recursively at every nesting level via
   `ksort($array, SORT_STRING)` (byte-wise). Insertion order of the input is irrelevant.
2. **Separators**: compact — no space after `,` or `:` (PHP's `json_encode` default with no
   pretty-print flag).
3. **Non-ASCII**: escaped as `\uXXXX` (lower-case hex) — `JSON_UNESCAPED_UNICODE` is *never* passed.
4. **Forward slashes**: left unescaped (`JSON_UNESCAPED_SLASHES`), applied unconditionally so it
   can never introduce a second valid encoding of the same data.
5. **Floats**: rejected outright (`InvalidArgumentException`). Every numeric value that can
   legally appear in an audited event is an integer (`BLUEPRINT.md` §11.3/§19.1 — no floats for
   money), so a float anywhere is a bug, not a formatting problem to solve carefully.
6. **Keys**: must be strings for associative data; a `array_is_list()` array encodes as a JSON
   array in its existing order (never re-sorted — order within a list is caller-meaningful); a
   non-string key anywhere raises `InvalidArgumentException`.
7. **Null**: preserved and encoded as JSON `null`, never dropped.

Proven in `tests/Unit/Domain/Audit/CanonicalJsonTest.php`, including one test that pins the *exact*
expected byte string for a realistic payload — not just self-consistency between two encodings.

## How to append an event from another module

```php
use Kern\TaxRomania\Audit\DataTransferObjects\AuditEventInput;
use Kern\TaxRomania\Audit\Services\AuditChainService;

final class SomeApplicationService
{
    public function __construct(private readonly AuditChainService $audit) {}

    public function doSomething(string $subjectId): void
    {
        // ... your module's own work, e.g. persisting a business row ...

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: $subjectId,          // or null for a system/actor-only event
            actorId: $currentUserId,        // nullable
            eventType: 'DocumentUploaded',  // your module's own event vocabulary
            resourceType: 'Document',
            resourceId: $document->id,      // nullable
            payloadHash: $this->audit->hashPayload(['sha256' => $document->sha256, ...]),
        ));
    }
}
```

No service provider binding is required — `AuditChainService` is a concrete class with only
container-resolvable dependencies (`Illuminate\Database\ConnectionResolverInterface`, aliased to
the `db` singleton by Laravel's own `Application::registerCoreContainerAliases()`), which is one of
`docs/AGENTS.md` §6's explicitly sanctioned cross-module traffic shapes ("an application service").
`Audit` is not yet declared as a layer other domain layers may depend on in `deptrac.php`'s
rulesets (only `Audit -> Support` exists, added by the scaffold agent) — whoever adds the first
real consumer (e.g. `Identity` calling this on login) must add
`Ruleset::forLayer($identity)->accesses($support, $audit)` in that same change, or Deptrac will
flag the new dependency as a violation.

`payloadHash` is a hash of your *own* business data — Audit never stores or sees the payload
itself, only its hash (`BLUEPRINT.md` §4.8: `payload_hash`, not an encrypted payload). Use
`AuditChainService::hashPayload()` for a canonical hash of a PHP array, or compute your own (e.g. a
file's SHA-256 per `BLUEPRINT.md` §4.9) and pass it directly.

## How to verify a chain

```php
$result = app(Kern\TaxRomania\Audit\Services\ChainVerifier::class)->verify($subjectId);
// or AuditChainService::SYSTEM_CHAIN_KEY for the system chain

if (! $result->ok) {
    // $result->brokenAtSequence — the exact sequence number
    // $result->reason           — a ChainVerificationFailureReason case
}
```

## Concurrency proof

`tests/Feature/Domain/Audit/AuditChainConcurrencyTest.php` forks 5 real OS processes
(`pcntl_fork()`), each opening its **own** Postgres connection (`DB::purge()` immediately after
`fork()`, both in the child and defensively in the parent before forking — without this, forked
children would fight over the same inherited PDO socket file descriptor, which is undefined
behaviour, not a test of database locking) and each appending 15 events to the *same* chain with
random microsecond jitter between appends. The parent then asserts, from a fresh connection:

- exactly 75 rows exist for that chain, with sequences forming the contiguous range `1..75` (no
  gaps, no duplicates — proven by comparing against `range(1, 75)` directly, not just counting);
- the `audit_chain_heads` row's `last_sequence` is 75;
- `ChainVerifier::verify()` walks all 75 and reports `ok`.

This is real to a Postgres row lock: `AuditChainService::lockOrCreateHead()` takes
`SELECT ... FOR UPDATE` on the single `audit_chain_heads` row for that chain key, so the 5
processes' transactions serialize on that one row rather than racing on `sequence` assignment.
Appends to *different* chains never contend for this lock at all — that is the entire reason the
chain is scoped per-subject instead of being one global table-wide sequence.

## Append-only enforcement (database level)

`database/migrations/..._create_audit_events_table.php` creates (Postgres only):

```sql
CREATE FUNCTION audit_events_deny_mutation() RETURNS trigger AS $trigger$
BEGIN
    RAISE EXCEPTION 'audit_events is append-only: % is not permitted (id=%)', TG_OP, OLD.id;
END;
$trigger$ LANGUAGE plpgsql;

CREATE TRIGGER audit_events_no_update BEFORE UPDATE ON audit_events
    FOR EACH ROW EXECUTE FUNCTION audit_events_deny_mutation();
CREATE TRIGGER audit_events_no_delete BEFORE DELETE ON audit_events
    FOR EACH ROW EXECUTE FUNCTION audit_events_deny_mutation();
```

A trigger that raises an exception, not a `RULE` that would make `UPDATE`/`DELETE` a *silent*
no-op — an attempted mutation must be observable, not swallowed. Proven directly against a real
Postgres connection with a raw `DB::table('audit_events')->where(...)->update(...)` /
`->delete()`, both asserted to throw `Illuminate\Database\QueryException` containing
"append-only".

**This is defence against a compromised application credential or an SQL-injection-shaped write —
not against a Postgres superuser**, who can `ALTER TABLE audit_events DISABLE TRIGGER ...` first.
That second threat (`BLUEPRINT.md` §4.1's "administrator DB curios") is exactly what
`ChainVerifier` defends against instead: the **tamper test** in `AuditChainServiceTest.php`
builds a 3-event chain, disables the trigger, directly corrupts the *second* event's
`payload_hash`, re-enables the trigger, then asserts `ChainVerifier::verify()` reports
`ok = false`, `brokenAtSequence = 2` (the exact tampered sequence, not "somewhere"), and
`reason = EVENT_HASH_MISMATCH`. The event *before* the tampered one verifies fine
(`verifiedCount = 1`); the event after is never even reached.

Neither the trigger nor the verifier is sufficient alone against a fully compromised superuser
who also disables the trigger *and* recomputes a plausible-looking `event_hash` for the tampered
row content — that residual risk is exactly why `BLUEPRINT.md` §4.8 calls for a periodic, externally
signed Merkle checkpoint exported to WORM storage (not built here — see **What is NOT done**).

## Key decisions

1. **`chain_key` is an addition to `BLUEPRINT.md` §4.8's literal column list.** The blueprint's
   `audit_events` schema does not name a chain-scoping column; the task brief explicitly requires
   "per-subject chains, not one global chain, so writes parallelise", which needs an explicit key
   to lock on. `chain_key` is `subject_id` verbatim (as text) when present, or the literal string
   `"system"` when it is null (a UUID string can never equal that literal). This is documented at
   length in `AuditChainService`'s class docblock so nobody has to re-derive it from the blueprint
   diff.
2. **`signature_key_version` is nullable and always written as `null` by `record()`.** It is part
   of §4.8's schema, but it cannot be part of the `event_hash` input (a column populated *after* an
   event already has its immutable hash cannot also be part of what produced that hash), and — since
   `audit_events` is append-only — it can never be backfilled onto an existing row either. It is
   scoped here as "populated at INSERT time by a future signed-checkpoint job on rows it creates for
   a batch", not as an `UPDATE` target. See **What is NOT done**.
3. **`occurred_at` needed explicit `precision: 6`.** `Blueprint::timestampTz()` defaults to
   precision 0 (whole seconds). Since `AuditEventHasher` formats `occurred_at` with microsecond
   precision as part of the hashed payload, the default would have silently truncated every stored
   timestamp below what was hashed at write time — every `EVENT_HASH_MISMATCH` on every row, always,
   for a reason that has nothing to do with tampering. Caught by actually running the tests against
   real Postgres (see **How to verify**), not by inspection — `\d audit_events` showed
   `timestamp(0) with time zone` before the fix.
4. **`ChainVerifier` re-normalises `occurred_at` to UTC before re-formatting it, even though the
   writer already sends a `Z`-suffixed string.** Postgres returns `timestamptz` columns as text
   formatted in the *reading connection's* session `timezone` setting, not necessarily UTC (the
   writer's insert is immune to this because Postgres parses a trailing `Z` as UTC on input
   regardless of session timezone — but that only helps on the way in, not on the way out). Without
   `->setTimezone(new DateTimeZone('UTC'))` after re-parsing, a connection whose session timezone is
   not UTC would recompute a different formatted string — and therefore a different hash — for a
   row that was never actually tampered with. There is no test connection with a non-UTC session
   timezone exercising this specific path; it is a correctness argument in code and docblock, not
   (yet) a regression test. Flagging under **Open questions**.
5. **No `AuditEventRecorder` interface.** One implementation, no test needs a fake (Pest exercises
   `AuditChainService` itself against real Postgres) — an interface here would be exactly the
   "pointless abstraction" `docs/CODE-QUALITY.md` §1 warns against. `docs/AGENTS.md` §6 explicitly
   lists "an application service" as a sanctioned cross-module traffic shape, so this needs no
   provider binding either.
6. **No `AuditEvent`/`AuditChainHead` foreign keys to `subject_id`/`actor_id`.** Per this task's
   scope note, the Identity/Subjects tables (`users`, `tax_subjects`) do not exist yet. Both columns
   are plain nullable `uuid`, unindexed by any FK. **Whoever builds Identity/Subjects must add**
   `->foreign('subject_id')->references('id')->on('tax_subjects')` (and the `actor_id` equivalent
   against `users`) **in a follow-up migration** — nothing here assumes their absence beyond that.
7. **Test placement**: pure-logic tests (`CanonicalJson`, `SensitiveDataProcessor`) live under
   `tests/Unit/...`, matching the `tests/Unit` testsuite another agent added to `phpunit.xml` in the
   same wave (not edited by this task). Postgres-dependent tests live under `tests/Feature/...` and
   self-skip via `$this->markTestSkipped(...)` when `DB::connection()->getDriverName() !== 'pgsql'`
   — no `phpunit.xml`/`phpunit.pgsql.xml` change was needed to run them against real Postgres: since
   PHPUnit's `<env>` entries only `putenv()` when the variable **isn't already set**
   (`force="true"` would override; none of `phpunit.xml`'s `<env>` tags for `DB_*` set it), exporting
   `DB_CONNECTION=pgsql` etc. as real shell/container environment variables before invoking
   `vendor/bin/pest` overrides the sqlite defaults without touching `phpunit.xml` at all (verified
   directly against `vendor/phpunit/phpunit/src/TextUI/Configuration/PhpHandler.php`).
8. **Sensitive-log sanitizer wired via `tap`, not a channel's `processors` key.** Laravel only reads
   `processors` for the `monolog`-driver channels (`LogManager::createMonologDriver()`); `tap` is
   applied uniformly to every driver (`LogManager::get()`), which is why `single`/`daily`/`stderr`
   all pick up the same processor through the same mechanism. Push-order matters too: `tap` runs
   *after* a channel's initial Monolog construction, and `Monolog\Logger::pushProcessor()`
   `array_unshift`s (LIFO) — so `SensitiveDataProcessor` always runs **before**
   `PsrLogMessageProcessor` (added at construction time for channels with
   `'replace_placeholders' => true`), meaning `{placeholder}` substitution copies already-redacted
   context values into the message, not raw ones. Proven end-to-end in
   `SensitiveLogRedactionFeatureTest.php` with a literal `{cnp}` placeholder.
9. **Exception objects in log context are converted to a small redacted array** (class, redacted
   message, file, line — no trace) rather than left as the live `Throwable`. Monolog's own exception
   formatting calls `getTraceAsString()`, and a stack trace can carry full argument values from every
   frame — PII this processor has no generic way to inspect. This is a real, documented trade-off
   (poorer traces in logs) for not leaking arguments; see the class docblock's "Known limitation".
10. **Free-text `key=value`/`key: value` secrets are pattern-matched too** (`password=...`,
    `token: ...`, etc.), not only structured context keys — found by an actual failing test (an
    exception message built with string interpolation, not an array), not by inspection.

## Dependencies

None added. `ramsey/uuid` (already a transitive dependency of `laravel/framework` via
`illuminate/support`'s `Str::uuid7()`) is used directly (`Ramsey\Uuid\Uuid::uuid7()`) rather than
through the `Str` facade, per `docs/CODE-QUALITY.md`/`BLUEPRINT.md` §19.3's "no facades... inside
domain core".

## How to verify

Host PHP is 8.3.32; this project's target is 8.4+ (Pest 5 requires `^8.4`), so everything below ran
inside a throwaway `docker build -f docker/php/Dockerfile --target test` image (not the production
`runtime` stage), matching `docs/handoff/002-laravel-scaffold.md`'s documented approach — built and
discarded for this task, not a deliverable. A real Postgres (`pgvector/pgvector:pg18-trixie`, this
project's pinned tag) is required for the trigger and concurrency tests; a plain
`docker run -d --name <x> -e POSTGRES_USER=kern -e POSTGRES_PASSWORD=kern -e POSTGRES_DB=kern
pgvector/pgvector:pg18-trixie` on a shared Docker network with the test image is sufficient — the
full `compose.yaml` stack is not required for this module in isolation.

```bash
# From apps/api/, inside the built `test`-stage image, network-joined to a real Postgres:

composer validate --strict
# ./composer.json is valid

composer audit
# No security vulnerability advisories found.

vendor/bin/pint --test
# PASS .......................................................... 82 files

vendor/bin/phpstan analyse --memory-limit=1G     # level 9, no baseline, per phpstan.neon
# [OK] No errors

vendor/bin/deptrac analyse --config-file=deptrac.php
# Violations 0, Skipped violations 0, Uncovered 40, Allowed 0, Warnings 0, Errors 0
# ("Uncovered" = pre-existing app/Http and app/Providers files with no declared layer — not a
# violation, and not introduced by this task; Audit's own files are fully covered by the "Audit"
# layer the scaffold agent already declared in deptrac.php.)

# Migrations (real Postgres):
php artisan migrate --force
#   0001_01_01_000001_create_failed_jobs_table ..................... DONE
#   2026_09_08_000001_create_encryption_key_records_table .......... DONE   (another agent's table)
#   2026_09_08_100000_create_audit_chain_heads_table ............... DONE
#   2026_09_08_100001_create_audit_events_table .................... DONE
php artisan migrate:rollback --step=2 --force && php artisan migrate --force
#   rolls back cleanly and re-applies cleanly (both migrations' down() verified for real)

# Full suite, default sqlite connection (proves nothing else broke):
touch .env   # known pre-existing quirk, see .github/workflows/ci.yml's own "Touch empty .env"
             # step — Laravel's dotenv loader warns (Pest turns it into a WARN) with no .env
             # present at all, even though every value comes from real env vars. Not this task's
             # bug; not fixed here (outside this task's file scope).
vendor/bin/pest
# Tests:    14 skipped, 90 passed (149 assertions)
# (14 skipped = this task's 12 Postgres-only tests correctly self-skipping on sqlite, plus 2
# pre-existing OpenBaoLiveIntegrationTest cases needing a live OpenBao service — not this task's.)

# Same suite, DB_CONNECTION=pgsql/DB_HOST=<postgres>/... exported as real env vars:
vendor/bin/pest --filter=Audit
# Tests:    20 passed (53 assertions)
#   Tests\Unit\Domain\Audit\CanonicalJsonTest .............. 8 passed
#   Tests\Feature\Domain\Audit\AuditChainConcurrencyTest ... 1 passed
#   Tests\Feature\Domain\Audit\AuditChainServiceTest ....... 11 passed
```

**Do not run the full suite (unfiltered) against `DB_CONNECTION=pgsql`** — two other agents'
Postgres-agnostic tests (`Tests\Feature\Support\Crypto\EncryptedCastTest`,
`Tests\Feature\Support\KeyManagement\EncryptionKeyRecordsMigrationTest`) were authored assuming
sqlite's looser column typing (e.g. a non-UUID string in a `uuid` column) and fail under real
Postgres for reasons that have nothing to do with this task — confirmed by running the full suite
both ways and diffing the failures, not fixed here (outside this task's file scope, and not this
task's tests). Always target `--filter=Audit` (or the equivalent path) when running with
`DB_CONNECTION=pgsql`.

## Interfaces exposed to other modules

- `Kern\TaxRomania\Audit\Services\AuditChainService::record(AuditEventInput $input): AuditEventRecord`
- `Kern\TaxRomania\Audit\Services\AuditChainService::hashPayload(array $payload): string`
- `Kern\TaxRomania\Audit\Services\ChainVerifier::verify(string $chainKey): ChainVerificationResult`
- `Kern\TaxRomania\Audit\DataTransferObjects\AuditEventInput` (+ `::occurringNow()` named constructor)
- `Kern\TaxRomania\Audit\ChainVerificationFailureReason` enum
- `config/logging.php`'s `RedactSensitiveData::class` tap — add the same `'tap' => [...]` line to
  any new log channel to inherit redaction; nothing else is required.

## What is NOT done

- **Signed Merkle checkpoints / WORM export** (`BLUEPRINT.md` §4.8's "periodic checkpoint, Merkle
  root, signed with a separate KMS/HSM key, exported to WORM storage") — explicitly deferred per
  this task's brief pending a WORM storage target and the KMS/KeyManagement work another agent
  (005, crypto core) owns. What that job will need from this module, concretely:
  - A way to select "all events with `sequence` in `[a, b]` for chain `X`" — already possible via
    `DB::table('audit_events')->where('chain_key', $x)->whereBetween('sequence', [$a, $b])`.
  - `AuditEventHasher::payload()`/`::hash()` are already the canonical hashing primitives to reuse
    for computing a Merkle leaf per event, or a root over `event_hash` values directly.
  - `signature_key_version` on `audit_events` is reserved for that job to write **at insert time on
    rows it creates for a batch, never as a retroactive `UPDATE`** (the table is append-only — see
    **Key decisions** #2). A checkpoint job's own record of "which events does checkpoint N cover,
    signed with which key version" more naturally belongs on a new `audit_checkpoints` table
    (named in `BLUEPRINT.md` §6.1, not created by this task) than as a backfilled column here.
- **Foreign keys from `audit_events`/`audit_chain_heads` to `users`/`tax_subjects`** — those tables
  do not exist yet (Identity/Subjects modules). See **Key decisions** #6.
- **`deptrac.php` does not yet grant any other domain layer access to `Audit`** — only
  `Audit -> Support` exists (added before this task). The first real caller (e.g. Identity logging
  a login event) must add its own layer's access to `Audit` in `deptrac.php` in the same change.
- **The `emergency` log channel is not tapped.** `Illuminate\Log\LogManager::createEmergencyLogger()`
  builds a hardcoded `StreamHandler` directly, bypassing the channel-config/tap pipeline entirely —
  it only fires when building the *configured* channel itself throws. A record logged through this
  path (a rare failure mode, not normal operation) would not be redacted. No test exercises this
  path (deliberately hard to trigger without breaking the app's real logging config).
- **No non-UTC-session-timezone regression test** for `ChainVerifier`'s `occurred_at` re-normalisation
  (**Key decisions** #4) — the argument for correctness is in code/docblock only.
- **`security_events` table** (named in `BLUEPRINT.md` §6.1 alongside `audit_events`) — not built;
  out of this task's explicit scope (`packages/tax-romania/src/Audit/**`, and this table wasn't named in the
  brief).

## Open questions

- Should a future agent add a dedicated integration test that sets the Postgres session
  `timezone` to something other than UTC and re-runs `AuditChainServiceTest`'s basic
  three-event-chain test against it, to turn **Key decisions** #4 into an executable regression
  test rather than a code-review argument?
- `ChainVerificationFailureReason::CHAIN_NOT_FOUND` is currently reachable only in the
  (essentially impossible under normal operation) case where an `audit_chain_heads` row exists with
  `last_sequence != 0` but zero matching `audit_events` rows exist for that `chain_key` — e.g. a
  hand-edited head row, or a partial restore. No test constructs this state directly (doing so
  requires bypassing the service layer entirely, since `record()` cannot itself produce it). Worth
  a dedicated test if `audit_chain_heads` ever becomes directly writable by another path.

## Known issue found outside this task's scope (not fixed here)

`tests/Unit/Support/Crypto/BlindIndex/NormalizerTest.php`'s "it folds cedilla diacritics to the
same result as comma-below" test (comparing `\u{0163}\u{0103}` cedilla forms against `Ș`/`Ț`
comma-below forms) **failed once, then passed on an immediate unmodified re-run**, in the default
sqlite full-suite run — output: expected `'stefan tariceanu'`, got `'stefan ttariceanu'` (an extra
`t`). `App\Support\Crypto\BlindIndex\Normalizer` is outside this task's file scope
(`app/Support/Crypto/**` belongs to the parallel crypto-core agent per this task's own
instructions), so this was not investigated further or fixed — recording it here per
`docs/AGENTS.md` §10 ("if you find a bug outside your scope, record it... rather than fixing it
silently"). **I could not edit `docs/STATUS.md`'s "Known issues" table myself — this task's
instructions explicitly excluded that file — so whoever next touches `docs/STATUS.md` should copy
this entry there.** The flakiness (fails once, passes immediately after with no code change)
suggests a normalization-order or caching issue specific to that Unicode-folding logic, not an
environment issue like the ones in this handoff's other sections.

## Sources consulted

- `BLUEPRINT.md` §4.1 (threat model), §4.8 (audit tamper evidence — the exact schema and hash
  formula), §4.10 (secret handling), §6.1 (table names), §11.3/§19.1 (no floats for money), §16
  (domain events), §23 (observability/no PII in logs).
- `docs/AGENTS.md` §6 (module boundary rules, sanctioned cross-module traffic shapes), §6b/
  `docs/CODE-QUALITY.md` §1 (DRY nuance — why `AuditEventHasher` exists as one shared place),
  §7 (quality gates).
- `docs/handoff/002-laravel-scaffold.md` (how to run the app/tests on this host, the `.env` `touch`
  workaround referenced from `.github/workflows/ci.yml`).
- `docs/STATUS.md` (file-ownership table — confirmed `app/Support/Crypto/**` and
  `app/Support/KeyManagement/**` belong to a parallel agent before touching anything adjacent).
- Source read directly, not assumed: `vendor/laravel/framework/src/Illuminate/Log/LogManager.php`
  (`tap`/`processors` handling, confirmed `tap` applies uniformly across drivers while `processors`
  is monolog-driver-only), `vendor/monolog/monolog/src/Monolog/Logger.php` (`pushProcessor`'s
  `array_unshift` — confirms processor execution order), `vendor/monolog/monolog/src/Monolog/
  LogRecord.php` (immutable record, `with()` method), `vendor/laravel/framework/src/Illuminate/
  Foundation/Application.php` (`ConnectionResolverInterface`/`DatabaseManager` aliasing to the `db`
  singleton — how `AuditChainService` gets a real connection without a facade),
  `vendor/phpunit/phpunit/src/TextUI/Configuration/PhpHandler.php` (`<env>` only `putenv()`s when
  not already set, confirming the `DB_CONNECTION=pgsql` shell-env-var override approach needs no
  `phpunit.xml` change).
