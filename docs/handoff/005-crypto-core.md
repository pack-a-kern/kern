# 005 — Crypto core (envelope encryption, blind indexes, Eloquent cast)

**Milestone:** M1   **Status:** complete
**Date:** 2026-09-08

## What was built

The cryptographic core for Milestone 1, implementing ADR 0002 exactly: per-`TaxSubject` envelope
encryption via OpenBao's transit engine, a global versioned blind-index key, an AEAD ciphertext
envelope bound to `subject_id | table | field | record_uuid`, and an Eloquent cast that makes a
model's encrypted fields transparent to use. No identity tables (`users`, `tax_subjects`) were
created — that is explicitly out of this task's scope; this module exposes the contracts the
Identity/Subjects agent must implement to persist a subject's wrapped DEK.

Entry points:
- `App\Support\KeyManagement\Contracts\KeyManagementService` — wrap/unwrap/rotate a DEK against a KEK.
- `App\Support\KeyManagement\OpenBaoTransitKeyManagementService` — the real implementation, talking to OpenBao's transit engine over HTTP.
- `App\Support\KeyManagement\SubjectDekService` — per-subject DEK lifecycle (generate-on-first-use, unwrap-on-demand, request-scoped cache).
- `App\Support\KeyManagement\Contracts\SubjectDekRepository` — the persistence contract the Identity/Subjects agent must implement against `tax_subjects`.
- `App\Support\Crypto\EnvelopeCipher` — stateless XChaCha20-Poly1305 seal/open, pure functions.
- `App\Support\Crypto\AssociatedData` — builds the AAD string.
- `App\Support\Crypto\Casts\Encrypted` — the Eloquent cast: `protected $casts = ['cnp' => Encrypted::class];`.
- `App\Support\Crypto\Contracts\EncryptsWithSubjectDek` — models using the cast must implement this.
- `App\Support\Crypto\Concerns\HasUuidPrimaryKey` — required trait for any model using the cast (see "The UUIDv7-in-constructor trap" below).
- `App\Support\Crypto\Concerns\FillsSubjectForeignKeyFirst` — required (or an equivalent) for any model whose `encryptionSubjectId()` reads a plain attribute (see "A second ordering trap" below).
- `App\Support\Crypto\BlindIndex\BlindIndexHasher` + `Normalizer` + `ConfigBlindIndexKeyProvider` — the global blind-index primitives.
- `encryption_key_records` migration — KEK/blind-index key lifecycle bookkeeping (not per-subject DEKs — see "Key decisions").

## Files created / changed

**Crypto** (`apps/api/app/Support/Crypto/`)
- `EnvelopeCipher.php` — XChaCha20-Poly1305-IETF seal/open. Stateless, pure, final — no interface,
  because there is exactly one field-encryption algorithm this project uses (ADR 0002 doesn't make
  it swappable); an interface for one implementation would be premature abstraction
  (`docs/CODE-QUALITY.md` §1).
- `AssociatedData.php` — builds `subject_id | table | field | record_uuid`.
- `Casts/Encrypted.php` — the Eloquent cast. Resolves `SubjectDekService` via the `app()` helper —
  documented in the class's own docblock as a deliberate, narrow exception to "no service
  locators" (`docs/CODE-QUALITY.md` §4), forced by Eloquent's own cast-instantiation contract
  (`new $castType(...$args)`, never resolved through the container — see
  `HasAttributes::resolveCasterClass()`).
- `Contracts/EncryptsWithSubjectDek.php` — `encryptionSubjectId(): string`, implemented by every
  model using the cast.
- `Concerns/HasUuidPrimaryKey.php` — see "The UUIDv7-in-constructor trap" below.
- `Concerns/FillsSubjectForeignKeyFirst.php` — see "A second ordering trap" below.
- `Exceptions/EnvelopeException.php`, `Exceptions/CastConfigurationException.php` — every factory
  method takes only structural identifiers (table/field/class names, operation names, HTTP
  statuses) as arguments, never a value that could be plaintext, a key, or ciphertext bytes.
- `BlindIndex/Normalizer.php` — `email()`, `cnp()`, `iban()`, `text()` (Romanian diacritic folding
  via `Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC')`, which folds both
  comma-below ș/ț (U+0219/U+021B) and cedilla ş/ţ (U+015F/U+0163) forms to the same base letter).
- `BlindIndex/BlindIndexHasher.php` — `HMAC-SHA-256(index_key, normalized_value)`.
- `BlindIndex/Contracts/BlindIndexKeyProvider.php`, `BlindIndex/ConfigBlindIndexKeyProvider.php` —
  reads the versioned global key from `config/crypto.php`.

**Key management** (`apps/api/app/Support/KeyManagement/`)
- `Contracts/KeyManagementService.php` — `wrapDataKey`, `unwrapDataKey`, `currentKekVersion`, `rotateKek`.
- `OpenBaoTransitKeyManagementService.php` — the real implementation. Talks to
  `{transit_mount}/encrypt|decrypt|keys/{key}` and `{transit_mount}/keys/{key}/rotate` over
  `Illuminate\Http\Client\Factory` (constructor-injected, not the `Http` facade, so it's testable
  with a plain instance). KEK version is parsed from transit's own `vault:v<N>:...` ciphertext
  prefix, never tracked separately, so a `WrappedDataKey`'s version can never disagree with what
  actually unwraps it.
- `SubjectDekService.php` — see "Interfaces exposed to other modules" below.
- `KeyManagementServiceProvider.php` — binds `KeyManagementService` (driver from
  `config('crypto.kms.driver')`, only `'openbao'` is a real driver — there is deliberately no
  no-op/fake production driver, per ADR 0002's "the security control wins over convenience")
  and registers `SubjectDekService::class` as a **scoped** container binding.
- `Contracts/SubjectDekRepository.php` — the persistence contract (see below).
- `ValueObjects/WrappedDataKey.php` — `{ciphertext, kekVersion}`.
- `Exceptions/KeyManagementException.php` — same rule as `EnvelopeException`: never constructed
  with a token, plaintext, or response/request body.

**Config**
- `apps/api/config/crypto.php` — `kms.driver`/`kms.openbao.*` (address, token, transit mount, key
  name, timeout — all env-driven) and `blind_index.keys`/`blind_index.current_version`.

**Migration**
- `apps/api/database/migrations/2026_09_08_000001_create_encryption_key_records_table.php` —
  `id (ulid), key_class (KEK|BLIND_INDEX), identifier, version, status (ACTIVE|RETIRED),
  activated_at, retired_at, notes, timestamps`, unique on `(key_class, identifier, version)`.

**Wiring** (outside the strict path list, minimal and necessary — see "Key decisions" #6)
- `apps/api/bootstrap/providers.php` — registered `KeyManagementServiceProvider`.
- `apps/api/phpunit.xml` — added a `Unit` testsuite (`tests/Unit`) alongside the existing
  `Feature` one; this module's pure, DB-free tests live there.

**Tests** — see "How to verify" for the full list and real output. Fakes live under
`apps/api/tests/Fakes/` (not `app/Support`) since they are test doubles, not a production driver.

## Key decisions

1. **Field ciphertext "key version" ≠ KEK version.** The envelope header's `keyVersion` field
   (see wire format below) is the *DEK generation* that encrypted the value — always `1` today,
   since no DEK-rotation feature exists yet. `WrappedDataKey::$kekVersion` (tracked on
   `tax_subjects.dek_kek_version` once that table exists) is a completely different number: it's
   which *KEK* version wrapped the DEK, and rotating the KEK does **not** change the DEK's own
   bytes, so it never changes any field ciphertext's embedded key version. Do not conflate these
   two numbers — a future DEK-rotation feature is the only thing that would ever make a field's
   embedded `keyVersion` exceed `1`.

2. **Ciphertext envelope wire format** (before base64 encoding, all integers big-endian):

   ```text
   byte 0      format version (currently 1)
   byte 1      algorithm id (currently 1 = XChaCha20-Poly1305-IETF)
   bytes 2-5   key version (uint32) — the DEK generation, see decision #1
   bytes 6-29  nonce (24 bytes)
   remainder   ciphertext with the 16-byte Poly1305 tag appended (libsodium combined mode)
   ```

   The whole byte string is base64-encoded to round-trip through a `text` column.
   `EnvelopeCipher::keyVersionOf($envelope)` reads the header without decrypting.

3. **AAD construction rule** — exactly `subject_id | table | field | record_uuid`, joined with a
   literal `|`, no escaping. Safe because every segment is a system-controlled identifier (a
   UUID/ULID, a table name, a column name), never free-form user input, so no segment can contain
   `|` in a way that collides two distinct tuples. Implemented in `AssociatedData::for(...)`.

4. **The UUIDv7-in-constructor trap** (exactly the trap named in the task brief). Eloquent casts
   run at attribute *assignment* time (`fill()`/`setAttribute()`), which for `new Model([...])`
   happens synchronously inside the constructor — long before a `creating` event would fire (that
   only fires on `save()`). Since the AAD needs `record_uuid` at encryption time, the primary key
   must exist before any attribute is assigned. **`Concerns\HasUuidPrimaryKey` solves this**: it
   overrides `__construct()` to call `parent::__construct()` with no attributes, assign a
   `Str::uuid7()` primary key via `setAttribute()`, *then* call `$this->fill($attributes)`. It also
   sets `$incrementing = false` / `$keyType = 'string'` via Eloquent's `initializeHasUuidPrimaryKey()`
   trait-init hook. **Every model using `Casts\Encrypted` must use this trait** (or otherwise
   guarantee its primary key is set before any attribute assignment — no other approach was found
   that doesn't reimplement this trait's logic). `Casts\Encrypted` fails loudly
   (`CastConfigurationException::missingPrimaryKey`) if a model's key is unset when an encrypted
   attribute is assigned, rather than silently binding to an empty record id.

5. **A second, related ordering trap this module's own design introduces.**
   `Contracts\EncryptsWithSubjectDek::encryptionSubjectId()` typically reads a foreign-key
   attribute (e.g. `tax_subject_id`). Eloquent's `fill()` assigns attributes in the array order the
   caller happens to pass — `new Model(['cnp' => $cnp, 'tax_subject_id' => $id])` would try to
   encrypt `cnp` while `tax_subject_id` is still unset, silently binding the AAD to an empty
   subject id. **`Concerns\FillsSubjectForeignKeyFirst` solves this** the same way
   `HasUuidPrimaryKey` solves the first trap: it overrides `fill()` to set the subject foreign key
   (default attribute name `tax_subject_id`, override `subjectForeignKeyName()` if different)
   before delegating the rest of the array to `parent::fill()`, making assignment order
   irrelevant. **Every model that reads its subject id from a plain attribute (not injected some
   other way) must use this trait too.** `tests/Feature/Support/Crypto/EncryptedCastTest.php`'s
   "lets fields be assigned in any order" test exists specifically to pin this down as a
   regression test, not folklore.

   A required, non-array constructor parameter (`__construct(string $subjectId, array $attrs)`)
   was considered and rejected: Eloquent's own hydration path
   (`Model::newFromBuilder()` → `newInstance($attributes)` → `new static($attributes)`) always
   calls the constructor with exactly one array argument. A different constructor signature breaks
   loading existing rows from the database with a `TypeError`.

6. **`bootstrap/providers.php` and `phpunit.xml` were edited despite being outside the literal
   scope list.** Both are small, additive, one-purpose changes (register one provider; add one
   testsuite directory) with no plausible conflict with the Audit or CI agents' concurrent work,
   and were necessary: without the provider registration, `KeyManagementService`/`SubjectDekService`
   are unreachable via the container anywhere in the app; without the `Unit` testsuite entry,
   `tests/Unit/**` (this module's pure, DB-free tests — `EnvelopeCipherTest`,
   `BlindIndexHasherTest`, `NormalizerTest`, etc.) would never run under `vendor/bin/pest` at all,
   silently.

7. **`SubjectDekService`'s cache is safe under php-fpm without extra plumbing.** The blueprint's
   runtime is php-fpm (`docs/handoff/002-laravel-scaffold.md`), not a persistent worker
   (Octane/RoadRunner) — Laravel's front controller builds a brand-new `Application` container
   per request regardless of OS process reuse, so a container-level singleton/scoped binding
   already cannot leak a plaintext DEK across requests. The `scoped()` binding
   (`KeyManagementServiceProvider`) exists so *multiple consumers within the same request* share
   one cache (avoiding redundant KMS round-trips), not to bound the cache's lifetime — php-fpm
   already does that. `forgetAll()` is exposed for long-running processes (queue workers,
   `schedule:work`) that must call it between jobs; nothing in this module calls it automatically,
   since this module owns no job/worker lifecycle.

8. **A real bug found only by testing against a live OpenBao, not by unit tests alone**:
   `OpenBaoTransitKeyManagementService::rotateKek()` originally POSTed an empty PHP array (`[]`)
   as the rotate request body. Laravel's HTTP client JSON-encodes `[]` as a JSON *array* (`[]`),
   and OpenBao's rotate endpoint rejects that with HTTP 400 ("failed to decode JSON input: json:
   cannot unmarshal array into Go value of type map[string]interface {}") because it expects a
   JSON object. Fixed by sending a non-empty associative array (`['_unused' => true]`), which
   PHP's `json_encode` correctly serializes as a JSON object; OpenBao ignores the unrecognised
   field with a warning (rotate takes no real parameters). This could not have been caught by the
   `Http::fake()`-based test suite alone, since a fake trivially accepts any body — see "How to
   verify" for the live reproduction. **Lesson recorded for future agents touching this class:
   `Http::fake()` proves request/response *shape* handling; it does not prove wire-format
   compatibility with the real service.**

9. **Blind-index rotation is deliberately not automated here.** `BlindIndexHasher::computeWithVersion()`
   exists so a future re-indexing batch job can recompute every blind index under a new key
   version before retiring the old one (ADR 0002: "a real batch job, not a config flag flip"). No
   such job exists yet — building it is out of this task's scope (no table to reindex exists
   either).

10. **No new Composer dependencies.** Everything here uses libsodium (already enabled in the
    target PHP image per `docs/reference/verified-versions.md`), `ext-intl`'s `Transliterator`
    (already added per the same doc), Laravel's own HTTP client, and `Illuminate\Support\Str::uuid7()`
    (backed by the already-installed `ramsey/uuid` 4.9.3, confirmed in `composer.lock`).
    `composer.json`/`composer.lock` are untouched.

## How to verify

Host PHP is 8.3 and cannot run this dependency tree (Pest 5 needs PHP ^8.4). Followed
`docs/handoff/002-laravel-scaffold.md`'s documented approach: a throwaway `php:8.5-cli-trixie`
container with `pdo_pgsql pdo_sqlite bcmath intl pcntl redis sodium` (the last three ship enabled
by default; `pdo_sqlite`/`sqlite3` also ship enabled by default — confirmed by `php -m`) plus
Composer, running against the real, already-vendored `apps/api/vendor/`. The Dockerfile used was
scratch space, not a deliverable, and was not committed.

All commands below were actually run; output is real.

```text
$ composer validate --strict
./composer.json is valid

$ composer audit
No security vulnerability advisories found.

$ vendor/bin/pint --test
  PASS .......................................................... 82 files

$ vendor/bin/phpstan analyse --memory-limit=1G
 [OK] No errors

$ vendor/bin/pest
  Tests:    14 skipped, 90 passed (149 assertions)
  Duration: 0.68s

$ vendor/bin/deptrac analyse --config-file=deptrac.php
  Violations 0, Skipped violations 0, Uncovered 40, Allowed 0, Warnings 0, Errors 0
```

The 14 skipped: 12 belong to the Audit module's tests (`tests/Feature/Domain/Audit/**`, require a
real Postgres connection — not this module's concern) and 2 are this module's own
`OpenBaoLiveIntegrationTest` (skipped unless `OPENBAO_TEST_ADDR`/`OPENBAO_TEST_TOKEN` are set,
since CI/`make test` don't start OpenBao alongside the PHP test image). "Uncovered 40" in deptrac
is expected — it covers `config/`, `database/migrations/`, `routes/`, and framework bootstrap
files, none of which are inside a declared module layer; `Violations 0` is what matters, and
`app/Support/**` (this module's home) is fully covered by the existing `Support` layer with zero
changes needed to `deptrac.php`.

**Live OpenBao proof** (not part of the routine `make test` flow — run once here to prove the
adapter against the real thing, per the task's explicit ask):

```text
$ docker compose up -d openbao openbao-init
$ docker compose exec openbao bao read transit/keys/tax-subject-kek
type    chacha20-poly1305
...

$ docker run --rm --network anaf_app_net \
    -e OPENBAO_TEST_ADDR=http://openbao:8200 \
    -e OPENBAO_TEST_TOKEN=<the OPENBAO_DEV_ROOT_TOKEN from .env> \
    -e OPENBAO_TEST_KEK_NAME=kern-crypto-core-test-kek-v2 \
    -e APP_KEY=base64:VApg/GsNVsAh2pMcDj/bBX/UvAFnrMANzk9EHgs5fRI= \
    -v <repo>/apps/api:/var/www/html -w /var/www/html <php-8.5-cli image> \
    vendor/bin/pest tests/Feature/Support/KeyManagement/OpenBaoLiveIntegrationTest.php

  PASS  Tests\Feature\Support\KeyManagement\OpenBaoLiveIntegrationTest
    ✓ it wraps and unwraps a real DEK through a live OpenBao transit engine
    ✓ it rotates the live KEK: old wraps still unwrap, new wraps carry the new version
  Tests: 2 passed (5 assertions)
```

This live run is what caught the empty-array/JSON-object bug in decision #8 — the first attempt
failed with a real HTTP 400 from OpenBao that no fake would have produced.

Test-only transit keys created during this verification
(`kern-crypto-core-test-kek*`, `tax-subject-kek`) live only in OpenBao's in-memory dev-mode
storage and are wiped on the next `openbao` container restart — no manual cleanup was needed or
performed.

## Interfaces exposed to other modules

- **`SubjectDekRepository` — what the Identity/Subjects agent must implement.** Two methods:
  - `find(string $subjectId): ?WrappedDataKey` — read `tax_subjects.encrypted_dek` +
    `tax_subjects.dek_kek_version` for the given subject, return `null` if the subject has no DEK
    yet (this is the normal, expected first-use state, not an error).
  - `save(string $subjectId, WrappedDataKey $wrapped): void` — persist both columns. **Must be
    create-only**: `SubjectDekService` calls this exactly once per subject, the first time
    `find()` returns `null`. A concurrent-creation race (two requests both see `null` and both try
    to `save()`) should be prevented at the database level (e.g. `tax_subjects.encrypted_dek`
    populated in the same transaction/row `TaxSubject` is created in, rather than as a nullable
    column filled in later) — this repository contract doesn't itself enforce that, and it's worth
    deciding deliberately rather than discovering it as a race condition later.
  - Bind the concrete implementation in the Identity/Subjects module's own service provider:
    `$this->app->bind(SubjectDekRepository::class, EloquentSubjectDekRepository::class);`. Once
    that binding exists, `SubjectDekService` (already registered as a scoped singleton by this
    module's `KeyManagementServiceProvider`) becomes fully resolvable via the container.

- **`EncryptsWithSubjectDek` + `HasUuidPrimaryKey` + `FillsSubjectForeignKeyFirst` — what every
  future model with encrypted fields must do.** See decisions #4/#5 above. A minimal real example
  (e.g. a future `PfProfile`):

  ```php
  final class PfProfile extends Model implements EncryptsWithSubjectDek
  {
      use HasUuidPrimaryKey;
      use FillsSubjectForeignKeyFirst; // reads 'tax_subject_id' by default

      protected $casts = [
          'cnp' => Encrypted::class,
          'address' => Encrypted::class,
      ];

      public function encryptionSubjectId(): string
      {
          $value = $this->getAttribute('tax_subject_id');
          if (! is_string($value)) {
              throw new RuntimeException('tax_subject_id must be a string.');
          }
          return $value;
      }
  }
  ```

- **Blind indexes** — for a field needing exact lookup (email, CNP, IBAN), a consuming migration
  adds both a `_ciphertext` column (via `Encrypted` cast) and a `_blind_index` column (raw
  `char(64)`/`string`, hex HMAC output). Compute it with:
  `app(BlindIndexHasher::class)->compute(Normalizer::cnp($rawCnp))` before storing, and the same
  expression again to look up by exact value. This module does not provide a cast or Eloquent
  event to compute this automatically — deliberately: unlike field encryption, which is symmetric
  and mechanical, *which* fields get a blind index and what the lookup query looks like is a
  per-field, per-module decision (`docs/AGENTS.md` §8 explicitly ties this to login-by-email and
  duplicate-CNP detection, both outside this module's scope).

- **`encryption_key_records`** — insert a row here (`key_class: 'KEK'`,
  `identifier: 'tax-subject-kek'`) when OpenBao's KEK is rotated, and one more
  (`key_class: 'BLIND_INDEX'`, `identifier` matching whatever name is chosen for the global index
  key) if/when that key is rotated. No code in this module writes to this table automatically —
  it's an audit/bookkeeping table for whoever operates key rotation, not a cache or index the
  application reads at runtime.

## How to rotate a key

**KEK rotation** (OpenBao's `tax-subject-kek`):
1. Call `$kms->rotateKek()` (or `bao write -f transit/keys/tax-subject-kek/rotate` directly) — this
   is instantaneous and non-disruptive: OpenBao retains old key versions, so every already-wrapped
   DEK keeps decrypting exactly as before.
2. Insert a row into `encryption_key_records` recording the new version (`ACTIVE`) and mark the
   previous version's row `RETIRED` (with `retired_at`) — bookkeeping only, doesn't affect
   decryption.
3. New DEKs (new `TaxSubject`s created from now on) are wrapped under the new KEK version
   automatically — nothing else to do.
4. Existing subjects' `encrypted_dek` blobs remain wrapped under the old version until
   individually re-wrapped. Re-wrapping an existing subject (recommended periodically, not
   required immediately) is: unwrap under the old version (still works), wrap the same plaintext
   DEK again (now under the new version), update the stored `encrypted_dek`/`dek_kek_version`. No
   field ciphertext needs touching — the DEK's own bytes don't change, so nothing encrypted under
   it needs re-encryption.
5. Retiring (deleting) an old KEK version entirely is a separate, deliberate, higher-risk
   operation this module does not automate — do it only after confirming no `encrypted_dek` still
   depends on that version.

**Blind-index key rotation** (the global HMAC key):
1. Add the new version's key to `CRYPTO_BLIND_INDEX_KEYS` (keep the old version's entry too) and
   bump `CRYPTO_BLIND_INDEX_CURRENT_VERSION`.
2. All *new* writes now index under the new version (`BlindIndexHasher::compute()` uses
   `currentVersion()`).
3. Run a batch job (not built here — see decision #9) that recomputes every existing row's blind
   index under the new version using `computeWithVersion($normalizedValue, $oldVersion)` to find
   candidates and `compute($normalizedValue)` to write the new value, per ADR 0002 ("a real batch
   job, not a config flag flip").
4. Once every row is re-indexed, remove the old version's key from `CRYPTO_BLIND_INDEX_KEYS`.

## What is NOT done

- No `tax_subjects`, `users`, or any identity table — explicitly out of scope; this handoff's
  "Interfaces exposed to other modules" section is the contract the Identity/Subjects agent
  implements against.
- No concrete `SubjectDekRepository` implementation exists yet, so `SubjectDekService` cannot
  actually be resolved end-to-end via the container yet (it throws Laravel's own
  `BindingResolutionException` until that binding exists) — this is the expected, honest state,
  not a bug.
- No blind-index re-indexing batch job (decision #9) — nothing to reindex yet (no table uses a
  blind index yet).
- No automatic wiring of `encryption_key_records` inserts on rotation — rotation today is a manual
  operational step (documented above), not a one-command tool. Building that tool is a reasonable
  follow-up task but wasn't asked for here.
- `SubjectDekService`'s in-memory cache cannot be securely zeroed (PHP strings are immutable;
  `sodium_memzero` requires the original variable, not a copy, and PHP's copy-on-write semantics
  make guaranteeing a single physical buffer unreliable) — plaintext DEK material lingers in
  process memory until PHP's own garbage collection, same as any PHP cryptography code. This is a
  known, accepted limitation of PHP's memory model, not something this module invented or can fix.
- Per-subject DEK **rotation** (as opposed to KEK rotation) — the envelope's `keyVersion` field
  (decision #1) is designed to support it later, but no code generates a DEK version other than
  `1` today.
- Did not touch `app/Domain/Audit/**`, `app/Support/Logging/**`, `.github/workflows/**`,
  `apps/processing/**`, or any ADR — other agents' concurrent scope.

## Open questions

- Should `SubjectDekRepository::save()`'s create-only/race-condition concern (see "Interfaces
  exposed to other modules") be resolved by a unique constraint on `tax_subjects.id` alone (DEK
  created in the same insert as the subject row) or by a separate uniqueness/locking mechanism?
  This is an Identity/Subjects module decision, not this module's to make.
- The blind-index re-indexing batch job (decision #9) needs a home (Identity module? a dedicated
  `Domain/Security` module?) once a real blind-indexed field exists — not decided here.

## Sources consulted

- `BLUEPRINT.md` §4.2–§4.4 (envelope encryption, encrypted fields, blind indexes), §6.1/§6.2 (schema).
- `docs/AGENTS.md` §8 (AAD binding rule, blind index formula, no PII in logs).
- `docs/adr/0002-envelope-encryption.md` — the decision this task implements; re-read in full
  before writing any code.
- `docs/handoff/001-docker-topology.md` — OpenBao dev-mode setup, transit key name
  (`tax-subject-kek`), key type (`chacha20-poly1305`), token env var
  (`OPENBAO_DEV_ROOT_TOKEN`), network (`app_net`).
- `docs/handoff/002-laravel-scaffold.md` — host-PHP-8.3-vs-target-8.5 verification approach (the
  throwaway `php:8.5-cli-trixie` container), existing `deptrac.php`/`phpstan.neon` conventions.
- `docker/openbao/init.sh`, `compose.yaml` (openbao service definition) — read directly to confirm
  transit engine setup rather than assumed.
- Laravel 13 framework source read directly for behavior claims made above (not assumed):
  `vendor/laravel/framework/.../Eloquent/Model.php` (`fill()`'s exact signature/behavior,
  `newFromBuilder()`/`newInstance()`'s hydration path), `HasAttributes::resolveCasterClass()`
  (custom cast classes are `new`'d directly, never container-resolved),
  `Illuminate\Http\Client\PendingRequest::post()` (body-format/JSON-encoding behavior that caused
  the empty-array bug in decision #8).
- OpenBao transit API behavior confirmed against the real running dev-mode server (not assumed
  from Vault documentation by analogy) — see "How to verify" for the exact commands and real
  responses, including the `vault:v<N>:...` ciphertext format and the rotate-endpoint JSON-object
  requirement.
- `composer.lock` — confirmed `ramsey/uuid` 4.9.3 already present (backing `Str::uuid7()`) before
  relying on it, rather than assuming.
