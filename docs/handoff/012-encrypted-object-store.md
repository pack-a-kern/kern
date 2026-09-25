# 012 — Encrypted object store

**Milestone:** M1   **Status:** complete
**Date:** 2026-09-08

## What was built

The encrypted object store for Milestone 1 (`BLUEPRINT.md` §4.6 object encryption, §4.9 document
integrity, §14.2 file security). Every document, OCR derivative, and declaration artifact this
product stores will go through this module's single entry point, `EncryptedObjectStore`. It
streams content through the existing crypto core (`docs/handoff/005-crypto-core.md`'s
`EnvelopeCipher`/`AssociatedData`/`SubjectDekService`) under the subject's own DEK before any byte
reaches the configured Flysystem disk (MinIO in dev, any S3-compatible bucket in production) —
plaintext never lands in the bucket, not even transiently.

No document tables were created — that is explicitly Milestone 7's scope. This module only
defines the object key scheme, the encrypted container format, and the store/retrieve/delete API
Milestone 7 will build directly on.

Entry points:
- `App\Support\ObjectStore\EncryptedObjectStore` — `put(ObjectKey, resource): ObjectManifest`,
  `get(ObjectKey): resource`, `delete(ObjectKey): void`. **This is the only class other modules
  should call.**
- `App\Support\ObjectStore\ObjectKey` — `ObjectKey::forDocument($subjectId, $documentId, $kind)`,
  the only way to construct a key; guarantees the PII-free `subjects/{uuid}/documents/{uuid}/{kind}`
  shape by construction.
- `App\Support\ObjectStore\DocumentObjectKind` — the closed enum of derivative kinds a key may name.
- `App\Support\ObjectStore\StreamEnvelopeWriter` / `StreamEnvelopeReader` — the low-level chunked
  seal/verify machinery `EncryptedObjectStore` is built on. Not meant to be called directly by
  other modules, but public (not `Internal\*`) so tests can pin the exact wire format.
- `App\Support\ObjectStore\ObjectStoreAssociatedData` — the AAD construction rule (see below).
- `App\Support\ObjectStore\ValueObjects\ObjectManifest` — the integrity record (§4.9).
- `App\Support\ObjectStore\ObjectStoreServiceProvider` — registers `EncryptedObjectStore` in the
  container.
- `config/objectstore.php` — which Flysystem disk, and the chunk size.

## Files created / changed

**Object store** (`apps/api/app/Support/ObjectStore/`)
- `EncryptedObjectStore.php` — the public API. `put()` streams plaintext → sealed container →
  `Filesystem::writeStream()`, then seals and writes the manifest. `get()` reads the manifest
  first (so a `put()` interrupted after writing content but before the manifest reports "manifest
  missing," never trusts partial content), then streams content back through
  `StreamEnvelopeReader`, verifying fully before returning anything to the caller.
- `ObjectKey.php` — `subjects/{subject_id}/documents/{document_id}/{kind}.enc`. Both ids are
  validated as UUID or ULID (`Illuminate\Support\Str::isUuid()`/`isUlid()`); the last segment can
  only be a `DocumentObjectKind` case. This is what makes "an object key cannot contain PII" a
  property of the type, not a convention every caller has to remember.
- `DocumentObjectKind.php` — `ORIGINAL|PREVIEW|THUMBNAIL|PDF_NORMALIZED|OCR|EXTRACTED_TEXT|STRUCTURED_JSON`
  (BLUEPRINT.md §6.6's `document_derivatives.kind` enum, plus `ORIGINAL`/`PREVIEW` from §4.6's own
  examples). `OCR` maps to `ocr.json.enc`, matching §4.6's literal example; every other kind is
  `{value}.enc`.
- `ObjectStoreAssociatedData.php` — the AAD construction rule (see "Key decisions" below).
- `StreamEnvelopeWriter.php` / `StreamEnvelopeReader.php` — the chunked container format (see
  "Wire format" below).
- `ValueObjects/ObjectManifest.php` — `{sha256, sizeBytes, chunkSize, chunkCount, formatVersion}`,
  JSON-serializable, strictly validated on decode.
- `Exceptions/InvalidObjectKeyException.php`, `Exceptions/ObjectNotFoundException.php`,
  `Exceptions/ObjectIntegrityException.php` — same rule as the crypto core's exceptions: never
  constructed with plaintext, key material, ciphertext bytes, or a content hash in the message.
  The object key itself (two ids + a fixed kind name) is safe to include — it is structural, never
  PII.
- `ObjectStoreServiceProvider.php` — binds `EncryptedObjectStore` as a transient (not singleton)
  service, resolving `Storage::disk(config('objectstore.disk'))` and `SubjectDekService` fresh
  each time (see "Key decisions" #5).

**Config**
- `apps/api/config/objectstore.php` — `disk` (default `s3`, i.e. the already-configured MinIO/S3
  disk in `config/filesystems.php`), `chunk_size` (default 4 MiB).

**Wiring** (outside the strict path list, minimal and additive — same justification pattern as
`docs/handoff/005-crypto-core.md` decision #6)
- `apps/api/bootstrap/providers.php` — registered `ObjectStoreServiceProvider`. One line; no
  plausible conflict with concurrent agents' work.

**Test fakes**
- `apps/api/tests/Fakes/InMemorySubjectDekRepository.php` — create-only in-memory
  `SubjectDekRepository`, matching the contract `docs/handoff/005-crypto-core.md` documented for
  the Identity/Subjects agent to implement. This file did not exist on disk when this task began
  despite `tests/Feature/Support/Crypto/EncryptedCastTest.php` already importing it — writing it
  satisfied both that pre-existing test and this module's own tests. (By the time this task
  finished, a concurrent Subjects-module agent had also landed a real
  `EloquentSubjectDekRepository` — this fake remains the correct choice for this module's own
  tests, which must not depend on a real `tax_subjects` table or Postgres.)

**Tests** — see "How to verify" for the full list and real output.
- `apps/api/tests/Unit/Support/ObjectStore/ObjectKeyTest.php`
- `apps/api/tests/Unit/Support/ObjectStore/ObjectManifestTest.php`
- `apps/api/tests/Unit/Support/ObjectStore/StreamEnvelopeTest.php` — the chunking/AAD/tamper/
  streaming-memory proofs, entirely without a Laravel container or MinIO.
- `apps/api/tests/Feature/Support/ObjectStore/EncryptedObjectStoreTest.php` — the same guarantees
  through the real `EncryptedObjectStore`/service-provider wiring, against `Storage::fake('s3')`.
  Runs on every `vendor/bin/pest` invocation.
- `apps/api/tests/Feature/Support/ObjectStore/EncryptedObjectStoreLiveMinioTest.php` — the same
  round trip and, critically, the raw-bucket marker assertion, against a **real** MinIO. Skipped
  unless `OBJECTSTORE_TEST_ENDPOINT`/`OBJECTSTORE_TEST_BUCKET` are set (see "Key decisions" #6).

## Key decisions

1. **Wire format — the encrypted container** (all integers big-endian):

   ```text
   byte 0-3    magic "TXOS"
   byte 4      container format version (currently 1)
   repeated for each chunk, in order, until the plaintext source reaches EOF:
     byte 0-3    sealed frame length in bytes (uint32)
     remainder   EnvelopeCipher::seal() output for this chunk (ASCII base64 text)
   ```

   Zero plaintext bytes (an empty document) is valid: the header is written and the loop produces
   zero frames. This container is what gets written, as-is, to the object key's path
   (`.../original.enc`, etc.) — Flysystem never sees plaintext.

2. **Chunking**: `StreamEnvelopeWriter`/`StreamEnvelopeReader` read/seal/verify at most
   `chunk_size` plaintext bytes (default 4 MiB) at a time. Neither ever holds more than one
   chunk's plaintext, one chunk's ciphertext, and one chunk's base64 text in memory simultaneously
   — proven in `StreamEnvelopeTest`'s "streams a large payload without holding the whole thing in
   memory" test (a 50 MB fixture, `memory_reset_peak_usage()` before/after, asserting the peak
   delta stays under 30 MB — a naive whole-file implementation would need roughly 150+ MB
   simultaneously: 50 MB plaintext + 50 MB ciphertext + ~67 MB base64). `EncryptedObjectStore`
   itself buffers the sealed container / decrypted plaintext through `php://temp` streams (spill
   to a local temp file past 2 MiB, never held fully in memory, never themselves the thing
   uploaded/downloaded), so a large document is streamed to/from Flysystem in bounded memory end
   to end, not just at the cipher layer.

3. **AAD scheme — reuses `App\Support\Crypto\AssociatedData::for()` exactly**, per the task's
   explicit instruction to follow the pattern handoff 005 established rather than inventing a
   parallel one:

   ```text
   subject_id  -> ObjectKey::$subjectId
   table       -> the fixed literal "object_store"
   field       -> the full object key (e.g. "subjects/{uuid}/documents/{uuid}/original.enc")
   record_uuid -> the chunk index (decimal string) for a content frame, or "manifest" for the
                  manifest object
   ```

   `field` carrying the whole key path (not just a column name, as field-level encryption uses it)
   is deliberate and safe for the same reason `AssociatedData`'s own docblock gives: it is
   entirely system-generated identifiers, never free-form input, so it cannot itself contain `|`
   in a way that collides two distinct keys. This binds a sealed frame to its exact subject,
   exact object key, *and* exact position in the sequence — not just to "some frame belonging to
   this object" — which is what makes reordering/duplication detectable (a frame decrypted at the
   wrong position was sealed under a different `record_uuid`, so its AEAD tag fails to verify
   there) and what makes the manifest itself equally tamper-evident (sealed with `record_uuid =
   "manifest"`, so it cannot be swapped for another object's manifest either).

4. **Integrity, three layers, all enforced before anything is returned to a caller**:
   - **Per-chunk AEAD authentication** (Poly1305 tag via `EnvelopeCipher`) — catches any bit
     flip, truncated frame, or frame moved to a different position (wrong AAD).
   - **Frame-count / byte-count tally against the manifest** — catches a truncated or extended
     container that happens to consist entirely of individually-valid-looking frames (e.g. the
     last frame dropped, or a frame duplicated at the very end where a chunk-index collision with
     a legitimately absent later chunk could otherwise slip through byte-count alone).
   - **SHA-256 of the full reassembled plaintext against the manifest** — computed before
     encryption on `put()` (BLUEPRINT.md §4.9), recomputed during verification on `get()`.

   The manifest carrying these expected values is itself sealed (AEAD, its own AAD binding) —
   an attacker with raw bucket write access cannot forge a new manifest to match tampered content
   without the subject's DEK. `StreamEnvelopeReader::read()` only returns normally once every
   check has passed; `EncryptedObjectStore::get()` only rewinds and returns its output stream
   after `read()` returns without throwing. Nothing is handed back to a caller ahead of that.

5. **`EncryptedObjectStore` is bound transient, not singleton.** It holds a `SubjectDekService`,
   which `docs/handoff/005-crypto-core.md` requires to be scoped (per-request) so its plaintext-DEK
   cache cannot outlive one request. A singleton `EncryptedObjectStore` would pin whatever
   `SubjectDekService` instance existed the first time it was resolved. Resolving it fresh (cheap —
   it's three constructor-injected references) keeps it correctly wired every time.

6. **The live-MinIO test uses a dedicated `OBJECTSTORE_TEST_*` env-var namespace, not the app's
   own `AWS_*`/`config('filesystems.disks.s3')`.** This was a real bug caught during this task's
   own verification, not a hypothetical: `apps/api/.env` (created by a concurrent agent for local
   `php artisan serve` development) legitimately sets `AWS_ENDPOINT=http://127.0.0.1:9000` and
   `AWS_BUCKET=kern-documents`. A skip-check keyed on those env vars read "available" in a
   throwaway test container with no route to `127.0.0.1:9000` and no credentials, and the test
   then failed loudly (`writeStream()` returning `false`) instead of skipping cleanly. This is
   exactly the reason `docs/handoff/005-crypto-core.md`'s `OpenBaoLiveIntegrationTest` uses
   `OPENBAO_TEST_ADDR`/`OPENBAO_TEST_TOKEN` rather than the app's own `OPENBAO_ADDR`/
   `OPENBAO_TOKEN` — this module now follows that same precedent instead of the shortcut that
   broke. `configureLiveDisk()` in the live test overrides `config('filesystems.disks.s3')`
   wholesale from `OBJECTSTORE_TEST_ENDPOINT`/`_BUCKET`/`_KEY`/`_SECRET`/`_REGION`.

7. **No new bucket-provisioning code.** `kern-documents` did not exist on MinIO when this task
   began (`docs/handoff/001-docker-topology.md` "What is NOT done" flagged this explicitly). It
   was created once, out of band, for this task's own live verification
   (`mc mb --ignore-existing local/kern-documents`) — not via a committed script, since
   provisioning it permanently is infra/`docker/**` scope this task was told to stay out of.
   **Whoever builds Milestone 7 needs a real, idempotent bucket-provisioning step** (following the
   `minio-init-perms`/`openbao-init` pattern) before this module is useful outside tests.

8. **No new Composer dependencies.** `league/flysystem-aws-s3-v3` was already required; this
   module uses Laravel's own `Illuminate\Contracts\Filesystem\Filesystem`/`Factory` and the
   existing `s3` disk in `config/filesystems.php` (already wired to MinIO via `AWS_*`/dev
   `.env.example`). `composer.json`/`composer.lock` are untouched.

## How to verify

Host PHP is 8.3 and cannot run this dependency tree. Followed the same approach as
`docs/handoff/005-crypto-core.md`: a throwaway `php:8.5-cli-trixie` container with `intl`
(`apt-get install -y libicu-dev && docker-php-ext-install intl`, per this task's brief) plus
`pdo_pgsql`/`bcmath`/`pcntl`/`redis` (needed for the rest of the test suite to load, not for this
module itself), running against the real, already-vendored `apps/api/vendor/`.

All commands below were actually run; output is real.

```text
$ vendor/bin/pint --test app/Support/ObjectStore tests/Unit/Support/ObjectStore \
    tests/Feature/Support/ObjectStore tests/Fakes/InMemorySubjectDekRepository.php config/objectstore.php
  PASS .......................................................... 18 files

$ php -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G app/Support/ObjectStore \
    tests/Unit/Support/ObjectStore tests/Feature/Support/ObjectStore \
    tests/Fakes/InMemorySubjectDekRepository.php config/objectstore.php
 [OK] No errors                                                                 (level 9)

$ vendor/bin/pest tests/Unit/Support/ObjectStore
  Tests:    27 passed (42 assertions)

$ vendor/bin/pest tests/Feature/Support/ObjectStore/EncryptedObjectStoreTest.php
  Tests:    11 passed (15 assertions)

$ vendor/bin/deptrac analyse --config-file=deptrac.php
  Violations 0, Skipped violations 0, Uncovered 76, Allowed 31, Warnings 0, Errors 0
```

**Live MinIO proof** (bucket created once via `mc mb --ignore-existing local/kern-documents`
against the real `docker compose up -d minio` service; run attached to `anaf_data_net` with
dedicated test credentials — see decision #6 for why these are not the app's own `AWS_*` vars):

```text
$ docker run --rm --network anaf_data_net \
    -e APP_KEY=base64:<...> \
    -e OBJECTSTORE_TEST_KEY=<MINIO_ROOT_USER> -e OBJECTSTORE_TEST_SECRET=<MINIO_ROOT_PASSWORD> \
    -e OBJECTSTORE_TEST_REGION=eu-central-1 -e OBJECTSTORE_TEST_BUCKET=kern-documents \
    -e OBJECTSTORE_TEST_ENDPOINT=http://minio:9000 \
    <php-8.5-cli image> vendor/bin/pest tests/Feature/Support/ObjectStore/EncryptedObjectStoreLiveMinioTest.php

  PASS  Tests\Feature\Support\ObjectStore\EncryptedObjectStoreLiveMinioTest
    ✓ it round-trips a small document through a real MinIO bucket, byte-identical    0.19s
    ✓ it never stores the plaintext marker in the raw MinIO object — M1 definition of done  0.03s
    ✓ it rejects content moved to a different subject's key path on a real bucket   0.05s
    ✓ it round-trips a 50 MB payload through a real MinIO bucket                    1.82s
  Tests: 4 passed (5 assertions)
```

Confirmed the bucket is left clean after this run (`mc ls --recursive local/kern-documents`
→ empty) — every test deletes what it created, even on failure (`try`/`finally`).

Confirmed the live test **skips** cleanly (does not attempt a connection) when
`OBJECTSTORE_TEST_*` are absent, even though `apps/api/.env`'s own dev `AWS_ENDPOINT`/`AWS_BUCKET`
are present in the same environment — this is the specific regression decision #6 fixes.

**Whole-suite run** (`vendor/bin/pest --exclude-group=slow`, real `APP_KEY`): 132 passed, 19
skipped (my env-gated live tests plus others'), 19 failed — **all 19 failures are in
`tests/Feature/Domain/Subjects/**`**, a concurrent agent's in-progress work (`Class
"Database\Factories\Domain\Identity\Models\UserFactory" not found`, a DEK-lifecycle assertion
mismatch), entirely unrelated to and unaffected by this module. Not fixed here — outside this
task's scope, and `docs/AGENTS.md` §10 says record rather than silently fix; **this task was
instructed not to edit `docs/STATUS.md`**, so it is recorded here instead for whoever picks this
up next.

`composer validate`/`composer audit` were not re-run — no dependency changed (decision #8), and no
Composer binary was installed in the throwaway image used for this session (PHPStan/Pint/Pest ran
directly against the already-vendored `vendor/`).

## Interfaces exposed to other modules

**Storing a document original** (Milestone 7's expected shape):

```php
use App\Support\ObjectStore\DocumentObjectKind;
use App\Support\ObjectStore\EncryptedObjectStore;
use App\Support\ObjectStore\ObjectKey;

$key = ObjectKey::forDocument($taxSubjectId, $documentId, DocumentObjectKind::ORIGINAL);
$manifest = $objectStore->put($key, $uploadedFileStream); // $manifest->sha256, ->sizeBytes, ...

$plaintext = $objectStore->get($key); // resource, verified, rewound — caller must fclose()
$objectStore->delete($key); // removes content + manifest together
```

- `ObjectKey::forDocument()` throws `Exceptions\InvalidObjectKeyException` if either id is not a
  UUID/ULID.
- `EncryptedObjectStore::get()` throws `Exceptions\ObjectNotFoundException` (content or manifest
  absent) or `Exceptions\ObjectIntegrityException` (any verification failure) — never returns
  partial or unverified content.
- `EncryptedObjectStore::put()`'s `$plaintext` argument is a readable PHP stream resource the
  *caller* owns and must close; `get()`'s returned resource is owned by the *caller* of `get()`,
  who must close it.

**What Milestone 7 still needs to add**: the `documents`/`document_derivatives` tables
(BLUEPRINT.md §6.6), the quarantine/AV-scan/native-extraction/OCR pipeline feeding into `put()`, and
a real bucket-provisioning step (decision #7).

## What is NOT done

- No `documents`/`document_derivatives` tables, no quarantine/AV/OCR pipeline — explicitly
  Milestone 7 scope.
- No bucket auto-provisioning in code — see decision #7.
- No per-subject DEK rotation re-encryption of already-stored objects — this module reads
  whatever the current unwrapped DEK is via `SubjectDekService`; DEK rotation itself and any
  re-encryption sweep are `docs/handoff/005-crypto-core.md`'s stated open scope, not this
  module's.
- No object listing/enumeration API (e.g. "all documents for subject X") — not asked for, and
  would belong to the Documents module's own metadata tables (Postgres), not this content-only
  store.
- No multipart-upload tuning for S3 (Flysystem's S3 adapter handles this internally for
  `writeStream()`; this module did not need to configure it explicitly, and did not).
- `composer validate`/`composer audit` not re-run this session (no dependency changed).

## Open questions

- Milestone 7's bucket-provisioning step: a `docker compose` one-shot service (like
  `minio-init-perms`) or an `artisan` command run once during deploy? Not decided here — infra
  scope (`docker/**`) this task was told to stay out of.
- Whether `chunk_size` should ever be tuned per-document-type (e.g. larger for known-large bank
  statement PDFs) — no evidence yet that the 4 MiB default is wrong for anything; leave as a single
  global config value until real usage says otherwise.

## Sources consulted

- `BLUEPRINT.md` §4.6 (object encryption, exact key-path example), §4.9 (document integrity/SHA-256/
  manifest), §14.2 (file security), §6.6 (`documents`/`document_derivatives` schema — read for
  context, not implemented).
- `docs/AGENTS.md` §6/§8 (module boundaries, AAD binding rule, no PII in logs).
- `docs/handoff/005-crypto-core.md` — read in full before writing any code; reused
  `EnvelopeCipher`/`AssociatedData`/`SubjectDekService` verbatim, followed its AAD pattern,
  followed its `OPENBAO_TEST_*`-style live-test env-var precedent (decision #6), reused its
  `tests/Fakes/InMemoryKeyManagementService.php`.
- `docs/handoff/001-docker-topology.md` — MinIO service name/network (`minio`, `data_net`/
  `processing_net`), bucket not yet provisioned (confirmed, then provisioned once out of band for
  this task's own verification — see decision #7).
- `docs/handoff/002-laravel-scaffold.md` — the host-PHP-8.3-vs-target-8.5 verification approach.
- `apps/api/config/filesystems.php`, `compose.yaml` (the `s3` disk / MinIO env wiring) — read
  directly rather than assumed.
- Laravel 13 framework source read directly: `Illuminate\Filesystem\FilesystemAdapter`/
  `Illuminate\Contracts\Filesystem\Filesystem` (`get()`/`put()`/`readStream()`/`writeStream()`
  signatures and `null`/`false` return semantics on miss/failure), confirmed rather than assumed.
