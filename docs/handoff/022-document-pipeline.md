# 022 — Document pipeline (upload, quarantine, ClamAV, processing lifecycle)

**Milestone:** M7   **Status:** partial — Laravel side complete; blocked on two decisions outside
this task's scope (Python text-extraction endpoint, external AI provider)
**Date:** 2026-09-08

## What was built

The Laravel half of Milestone 7 (`BLUEPRINT.md` §14, §6.6, §16): upload → quarantine → ClamAV scan →
magic-byte/type verification → registered → native text extraction → OCR decision → structured-
extraction seam → `PROPOSED` → human review. Every stage is a real, tested class; the two stages
that need a decision this task could not make (the Python text-extraction HTTP endpoint, and which
external AI provider/DPA to use) are built as concrete, tested seams rather than guesses.

Entry points:

- `Kern\Documents\Actions\InitiateDocumentUpload` — validates a temp file, creates the
  `documents` row, writes to quarantine, dispatches the scan job. The one place a file can be
  rejected before ever touching storage.
- `Kern\Documents\Jobs\ScanQuarantinedDocument` — the ClamAV stage.
- `Kern\Documents\Jobs\ProcessScannedDocument` — native text extraction, the OCR decision,
  and the external-extraction attempt.
- `Kern\Documents\Consent\DocumentAiConsentGate` — ADR 0005's per-subject consent record.
- `Kern\Documents\ExternalAi\RequestExternalDocumentExtraction` — the consent-gated,
  audited, currently-unimplemented external send.
- `Kern\Documents\Quarantine\QuarantineObjectStore` — the physically separate store that
  makes an unscanned upload unreachable by construction, not by convention.
- `POST /api/v1/subjects/{subject}/documents`, `GET .../documents/{document}`,
  `POST`/`DELETE .../document-ai-consent` — the HTTP surface (`routes/api.php`).

## The exact lifecycle states

`documents.status` (`Kern\Documents\Enums\DocumentStatus`):

```text
QUARANTINE -> SCANNED -> PROCESSING -> READY
QUARANTINE -> REJECTED
```

No code path ever moves a document backwards, and nothing advances a document past a stage whose
safety-relevant check could not be completed:

- **`QUARANTINE`**: the file passed `FileSecurityValidator` (size, magic bytes vs. declared
  extension, image pixel ceiling) and is sitting, encrypted, on the `quarantine` disk —
  `Kern\Documents\Quarantine\QuarantineObjectStore`, a disk the rest of the app never reads
  documents from. A scanner that cannot be reached (`VirusScannerUnavailableException`) leaves the
  document here indefinitely (Horizon retries `ScanQuarantinedDocument`); this is deliberate, not a
  bug — `BLUEPRINT.md` §14.2 "a scan failure or unreachable scanner must block, never fail open."
- **`REJECTED`**: ClamAV found something (`rejection_reason = INFECTED`). The quarantine object is
  deleted immediately; nothing is ever written to the real store.
- **`SCANNED`**: ClamAV reported clean. Bytes are read back out of quarantine, written to the real
  `EncryptedObjectStore`, and the quarantine copy is deleted. `ProcessScannedDocument` is
  dispatched.
- **`PROCESSING`**: native text extraction (PDF) or the trivial "no text layer" decision (image) is
  in flight. If the (currently unbuilt — see "What is NOT done") Python text-extraction endpoint is
  unreachable, the job throws and the document stays here, retried, exactly like `QUARANTINE`.
- **`READY`**: text extraction/OCR-decision finished (a PDF's native text is stored as an encrypted
  `document_derivatives` row, kind `EXTRACTED_TEXT`); the document is available for human review
  regardless of whether the external-extraction attempt that follows succeeds. A subject who has
  not consented, or the absence of a configured provider, does **not** hold the document back from
  `READY` — ADR 0005 treats that as a visible product limitation, not a retryable error.

`document_extractions.status` (`ExtractionStatus`) is a separate axis: `PROPOSED` is the only value
this task's code ever writes; `REVIEWED`/`ACCEPTED`/`REJECTED` are reachable only through
`DocumentExtraction::review($reviewerId, $decision)`, which no automatic pipeline code calls.

## What the PWA frontend will call (not built here — Milestone 7's frontend is a separate task)

- `POST /api/v1/subjects/{subject}/documents` — multipart, fields `file` and `type` (one of
  `Kern\Documents\Enums\DocumentType`). `202 {"id", "status": "QUARANTINE"}` or `422
  {"message", "reason"}` where `reason` is a closed `RejectionReason` value
  (`INVALID_MAGIC_BYTES`... actually the exact values are `EXTENSION_MISMATCH`,
  `UNSUPPORTED_FILE_TYPE`, `FILE_TOO_LARGE`, `IMAGE_PIXEL_LIMIT_EXCEEDED`, `INFECTED` — the last
  only ever appears via the async `DocumentScanned` broadcast, never a synchronous 422).
- `GET /api/v1/subjects/{subject}/documents/{document}` — the authorised-HTTPS re-fetch after a
  Reverb event (`BLUEPRINT.md` §16); metadata only (`id`, `type`, `status`, `rejection_reason`,
  `mime_type`, `size_bytes`, `received_at`) — never content, never a ciphertext column, never
  `object_path`.
- `POST`/`DELETE /api/v1/subjects/{subject}/document-ai-consent` — grant/revoke ADR 0005 consent.
- **Reverb**: private channel `tax-subjects.{subject}.documents` (`routes/channels.php`,
  authorized against an active `SubjectMembership` — the same check `TenantContext` performs for
  HTTP). Four events, each `status`/`progress`/ids only, never content:
  - `DocumentUploaded {document_id, status, progress: 10}`
  - `DocumentScanned {document_id, status, rejection_reason, progress: 40|100}`
  - `DocumentTextExtracted {document_id, status, needs_ocr, progress: 70}`
  - `DocumentExtractionProposed {document_id, extraction_id, status, extraction_status, progress: 90}`

  No document-download endpoint was built — this task's brief explicitly restricted the HTTP
  surface to upload + status + consent. Serving original bytes back to a client is Milestone 7
  frontend/future-task scope; whoever builds it must go through `EncryptedObjectStore::get()`
  only after re-checking `documents.status === READY` (or, for the original file, any non-
  `QUARANTINE`/`REJECTED` status) and must never expose `object_path` verbatim as a public URL.

## Files created / changed

**Migrations** (`apps/api/database/migrations/`)
- `2026_09_08_700000_create_documents_table.php` — `documents` (§6.6). `cascadeOnDelete()` on
  `tax_subject_id`, deliberately different from `audit_events`' `restrictOnDelete()` — see "Key
  decisions" #1.
- `2026_09_08_700001_create_document_derivatives_table.php` — `document_derivatives`, `kind`
  reusing `App\Support\ObjectStore\DocumentObjectKind` rather than a duplicate enum.
- `2026_09_08_700002_create_document_extractions_table.php` — `document_extractions`.
  `confidence_decimal` is `decimal(5,4)`, cast to `string` in the model — never a PHP float.
- `2026_09_08_700003_create_document_ai_consents_table.php` — **not** one of §6.6's literal
  tables; see "Key decisions" #2 for why it exists.

**Domain** (`apps/api/packages/documents/src/`)
- `Enums/{DocumentStatus,DocumentType,DocumentSource,RejectionReason,ExtractorType,ExtractionStatus}.php`
- `Models/{Document,DocumentDerivative,DocumentExtraction,DocumentAiConsent}.php`
- `DataTransferObjects/ValidatedUpload.php`
- `FileSecurity/{FileSecurityValidator,MagicByteSniffer,FilenameSanitizer,SniffedFileType}.php`,
  `FileSecurity/Exceptions/*` — `BLUEPRINT.md` §14.2, runs before any byte is stored or forwarded.
- `Scanning/{VirusScanner,ClamdVirusScanner,ScanOutcome}.php`,
  `Scanning/Exceptions/VirusScannerUnavailableException.php` — a hand-written INSTREAM client, not
  a dependency (see "Key decisions" #3).
- `Quarantine/QuarantineObjectStore.php` — the physically-separate-disk quarantine mechanism.
- `Extraction/{TextExtractionGateway,HttpTextExtractionGateway}.php`,
  `Extraction/DataTransferObjects/NativeTextExtractionResult.php`,
  `Extraction/Exceptions/TextExtractionUnavailableException.php`.
- `Consent/DocumentAiConsentGate.php`, `Consent/Exceptions/AiConsentRequiredException.php`.
- `ExternalAi/{ExternalDocumentAiProvider,NullDocumentAiProvider,RequestExternalDocumentExtraction}.php`,
  `ExternalAi/DataTransferObjects/{DocumentAiRequest,DocumentAiResponse}.php`,
  `ExternalAi/Exceptions/ExternalDocumentAiProviderNotConfiguredException.php`.
- `Actions/InitiateDocumentUpload.php`.
- `Jobs/{ScanQuarantinedDocument,ProcessScannedDocument}.php`.
- `Events/{DocumentUploaded,DocumentScanned,DocumentTextExtracted,DocumentExtractionProposed}.php`,
  `Events/Concerns/BroadcastsOnDocumentsChannel.php`.
- `DocumentsServiceProvider.php` — every binding; see class docblock.

**HTTP** (`apps/api/app/Http/`)
- `Controllers/Api/V1/Documents/{UploadDocumentController,ShowDocumentController,DocumentAiConsentController}.php`.
- `Middleware/BindTaxSubjectContext.php` — the first HTTP consumer of `TenantContext`; binds it
  from a `{subject}` route parameter, aliased `tax-subject`.

**Config**
- `apps/api/config/documents.php` — quarantine disk, size/pixel limits, ClamAV connection,
  text-extraction base URL, upload rate limit.
- `apps/api/config/filesystems.php` — added the `quarantine` disk (same MinIO endpoint/creds as
  `s3`, a different bucket, `QUARANTINE_BUCKET` env, default `kern-quarantine`).

**Wiring** (outside the strict path list, minimal and additive — same justification pattern every
prior handoff in this repo uses for this kind of change)
- `apps/api/bootstrap/providers.php` — registered `DocumentsServiceProvider`.
- `apps/api/bootstrap/app.php` — added `channels: routes/channels.php` to `withRouting()`
  (nothing used Reverb channel authorization before this task — see "Key decisions" #4); the
  `tax-subject` middleware alias; a central `$exceptions->render()` for
  `UnauthorizedSubjectAccessException` → HTTP 403 (nothing rendered this exception anywhere before
  — every module's future HTTP layer gets this for free, not just Documents').
- `apps/api/routes/channels.php` — new file; the one Reverb channel this task defines.
- `apps/api/routes/api.php` — the three new routes under `v1/subjects/{subject}`.
- `apps/api/deptrac.php` — added the `Documents` layer, `accesses($support, $subjects, $audit)`.
- `apps/api/tests/Pest.php` — added `RefreshDatabase` for `Feature/Domain/Documents` (same reason
  handoff 016 added it for Fx/TaxKnowledge/Subjects: fixed rows would collide on Postgres).

**Factories**
- `apps/api/database/factories/DocumentFactory.php` — no two-phase-create trick needed (unlike
  `TaxSubject`/`User`) — see `Document`'s own docblock for why.

**Tests** — 73 tests, all real, all executed (see "How to verify"):
- `tests/Unit/Domain/Documents/{MagicByteSnifferTest,FilenameSanitizerTest,FileSecurityValidatorTest,BroadcastPayloadContainsNoPiiTest,ClamdVirusScannerUnavailableTest}.php`
- `tests/Feature/Domain/Documents/{ScanQuarantinedDocumentJobTest,ProcessScannedDocumentJobTest,UploadDocumentControllerTest,ShowDocumentControllerTest,DocumentAiConsentControllerTest,CrossTenantIsolationTest,RawStoragePlaintextTest,DocumentExtractionAutoConfirmTest,RequestExternalDocumentExtractionTest,ClamdVirusScannerLiveTest}.php`
- `tests/Fixtures/Documents/FileFixtures.php` — real, `getimagesize()`-parseable PDF/PNG/JPEG
  fixtures with genuine dimensions (verified directly, not assumed from the format spec — see "Key
  decisions" #5) and the literal EICAR test string.

## Key decisions

1. **`documents.tax_subject_id` uses `cascadeOnDelete()`, not `restrictOnDelete()`.**
   `docs/handoff/011-identity-tenancy.md` chose `restrictOnDelete()` for `audit_events`/
   `audit_chain_heads` specifically because that table carries an append-only trigger that treats
   `SET NULL` as a forbidden `UPDATE`. `documents` has no such trigger, and `BLUEPRINT.md` §25
   describes account deletion as removing a subject's documents alongside crypto-shredding its
   DEK — cascading matches that intent rather than blocking it.

2. **`document_ai_consents` is a new table, not one of `BLUEPRINT.md` §6.6's literal ones.** The
   brief's item 6 requires "a subject may only be sent externally when the subject has consented,"
   and no table anywhere in the blueprint's schema carries that flag. Modeled exactly like
   `pf_profiles` (subject id as both PK and FK, one current-state row per subject) rather than an
   event log, because there is exactly one current consent state, not a history the pipeline needs
   to reason about. `Kern\Documents\Consent\DocumentAiConsentGate` is its only reader/writer.

3. **`ClamdVirusScanner` is a hand-written ~140-line INSTREAM client, not a Composer dependency.**
   `docs/DEPENDENCY-POLICY.md` §4 orders "standard library" above "a well-maintained third-party
   package" — every published PHP clamd client found is either unmaintained or wraps this same
   small, decades-stable protocol in a much larger surface. The wire format (`zINSTREAM`, 4-byte
   big-endian length-prefixed chunks, a zero-length terminator, `stream: OK`/`stream: <sig> FOUND`
   response) was verified directly against a real `clamd` 1.5.4 daemon before being encoded — see
   "How to verify" for the raw-protocol proof. **Never reports `CLEAN` except on that exact
   response**; every other outcome (refused connection, DNS failure, timeout, malformed response)
   throws `VirusScannerUnavailableException`, which `ScanQuarantinedDocument` treats identically to
   "block, don't advance."

4. **This is the first Reverb/broadcasting wiring in the codebase.** No `routes/channels.php`, no
   `Broadcast::routes()`/`withBroadcasting()` call, and no broadcast event existed anywhere before
   this task. `bootstrap/app.php`'s `withRouting(channels: ...)` is Laravel 13's own mechanism for
   both (confirmed by reading `Illuminate\Foundation\Configuration\ApplicationBuilder::withRouting()`
   directly — passing `channels` internally calls `withBroadcasting($channels)`, which registers
   `Broadcast::routes()` and requires the file). Every future module broadcasting on its own
   private channel adds its `Broadcast::channel(...)` call to the same `routes/channels.php` —
   there is no reason to introduce a second channels file.

5. **Image test fixtures are genuinely `getimagesize()`-parseable, not assumed.** A minimal PNG
   (8-byte signature + one IHDR chunk, no IDAT/IEND) and a minimal baseline JPEG (SOI + SOF0 + EOI)
   were each verified directly against PHP's `getimagesize()` before being committed as fixtures
   (`docs/AGENTS.md` §2 "do not invent a file format you have not observed" applies to test
   fixtures too) — confirmed both return the exact width/height encoded, not a lucky coincidence of
   a generic "any bytes will do" assumption. This is what lets the pixel-limit tests exercise the
   real `getimagesize()` call path `FileSecurityValidator` uses, not a mock.

6. **The quarantine/real-store promotion re-reads and re-writes plaintext, rather than copying the
   already-sealed container across disks.** `EncryptedObjectStore::get()` then `->put()` costs one
   extra decrypt/encrypt round trip per document, but avoids `ScanQuarantinedDocument` depending on
   the object-store's internal container format staying byte-identical across two independently-
   configured disks — a correctness property the object-store module never promised and this task
   should not assume.

7. **`ExternalDocumentAiProvider` is bound to a concrete `NullDocumentAiProvider`, not left
   unbound.** An unbound interface fails with a generic Laravel `BindingResolutionException` naming
   a constructor argument — a worse error for whoever wires up the real provider later than this
   class's explicit, documented `ExternalDocumentAiProviderNotConfiguredException` naming
   `docs/STATUS.md` blocked item #3 directly. This is a Null Object for a feature that exists and
   is called every time a document needs external extraction — not speculative scaffolding for an
   unbuilt feature (`docs/CODE-QUALITY.md` §2's prohibition does not apply to the one sanctioned
   extension point a task was explicitly asked to prepare).

8. **The audit event for an external send is written only after the provider call succeeds, never
   before.** ADR 0005 requires "an audit event *per external send*" — read literally, an event for
   a send that never happened (blocked by consent, or by no provider being configured) would be a
   false record. `RequestExternalDocumentExtraction`'s three-step ordering (gate → call → audit) is
   the literal implementation of that reading; both failure paths are tested to confirm zero audit
   rows.

9. **`DocumentExtraction`'s subject id for encryption is a plain, non-persisted PHP property, set
   by a named constructor (`propose()`), not derived from a `document_id` relationship lookup at
   cast time.** The alternative — loading the parent `Document` inside `encryptionSubjectId()` —
   would need `TenantContext` bound to satisfy `Document`'s own global scope, which queue jobs
   legitimately have no reason to bind (see decision below on job-level tenancy). See the model's
   own docblock.

10. **Jobs resolve `Document` via `withoutGlobalScope(TaxSubjectScope::class)`, never by binding
    `TenantContext`.** A queued job acts as the system on an already-known, already-authorized
    document id — not as a specific user's authenticated request — which is exactly the
    "genuinely cross-tenant system operation" `ScopedToTaxSubject`'s own docblock names as the
    sanctioned use of that escape hatch (the same category `RegisterPfSubject`'s duplicate-CNP
    check already uses it for).

## How to verify

All commands actually run, against the real `docker/php/Dockerfile` `test` stage
(`docker build -f docker/php/Dockerfile --target test -t kern-php-test:local .`), following
`docs/handoff/002-laravel-scaffold.md`'s host-PHP-mismatch precedent (host is 8.3; the image is
8.5).

**Raw INSTREAM protocol, verified before writing the client** (a genuine ClamAV 1.5.4 daemon,
not assumed from documentation):

```text
$ php clamd_test.php   # a throwaway script sending the exact wire bytes ClamdVirusScanner sends
Clean: stream: OK
EICAR: stream: Eicar-Test-Signature FOUND
Unreachable: CONN_FAIL: Connection refused
```

**Note on this session's ClamAV environment**: `compose.yaml`'s `clamav` service
(`cap_drop: [ALL]`) crash-loops on first boot in this sandbox —
`chown: cannot read directory '/var/lib/clamav': Permission denied` — because the image's own
`/init` script needs `CAP_CHOWN`/`CAP_DAC_OVERRIDE`/`CAP_SETUID`/`CAP_SETGID` to take ownership of
its data volume on first run, and `cap_drop: ALL` removes all four. **This is a pre-existing
infra defect, not introduced by this task** — recorded here per `docs/AGENTS.md` §10 since
`docs/STATUS.md` was out of this task's scope to edit; whoever owns `compose.yaml` should add
those four capabilities to `cap_add` for the `clamav` service specifically (or scope `cap_drop` to
something narrower than `ALL`), then the volume only needs `chown` once — a health-checked init
container ahead of `clamav` (matching the existing `minio-init-perms`/`openbao-init` pattern) would
avoid needing any capability on the long-running daemon at all. **For this task's own
verification**, a standalone container with those four capabilities added, attached to the same
`anaf_processing_net` under the `clamav` network alias, was used instead of the compose service —
this was for verification only, not a code change, and is not something this repository's
`compose.yaml` should adopt as written (adding capabilities to a long-running daemon rather than a
one-shot init step is the less-secure option; see the recommendation above).

**Pint, whole project:**
```text
$ docker run --rm kern-php-test:local vendor/bin/pint --test
  PASS .......................................................... 553 files
```

**PHPStan level 9, no baseline, whole project:**
```text
$ docker run --rm -e APP_KEY=... kern-php-test:local vendor/bin/phpstan analyse --memory-limit=1G
 ------ ---------------------------------------------------------------------- 
  Line   tests/Fixtures/TaxEngine/Purity/ImpureEnvironmentRule.php
 ------ ----------------------------------------------------------------------
  15     Called 'env' outside of the config directory...
 ------ ----------------------------------------------------------------------
 [ERROR] Found 1 error
```
That one error is pre-existing, in `App\Domain\TaxEngine`'s own test fixture — not touched by this
task, not in `packages/documents/src/**`. **Zero errors anywhere in this task's own files.**

**Deptrac, whole project:**
```text
$ docker run --rm -e APP_KEY=... kern-php-test:local vendor/bin/deptrac analyse --config-file=deptrac.php
 Violations 0, Skipped violations 0, Uncovered 446, Allowed 227, Warnings 0, Errors 0
```

**`composer validate`/`composer audit`** (no dependency changed — nothing to re-lock):
```text
$ docker run --rm kern-php-test:local composer validate --no-check-all
./composer.json is valid
$ docker run --rm kern-php-test:local composer audit
No security vulnerability advisories found.
```

**Whole suite, sqlite:**
```text
$ docker run --rm -e APP_KEY=... kern-php-test:local vendor/bin/pest
Tests: 301 warnings, 264 passed (1454 assertions)
```
(The "warning" on nearly every test — `file_get_contents(/var/www/html/.env): Failed to open
stream` — is a pre-existing artifact of this sandbox's test image carrying no `.env` file; it
affects tests across every module including ones untouched by this task, e.g.
`Tests\Feature\HealthEndpointTest`, and every test still has its real assertion count. Not
investigated further — outside this task's scope and does not affect correctness.)

**Whole suite, real Postgres, run twice** (`docker compose up -d postgres redis` first):
```text
$ docker run --rm --network anaf_data_net \
    -e APP_KEY=... -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_PORT=5432 \
    -e DB_DATABASE=kern -e DB_USERNAME=kern -e DB_PASSWORD=... \
    kern-php-test:local vendor/bin/pest
Run 1: Tests: 301 warnings, 264 passed (1502 assertions)
Run 2: Tests: 301 warnings, 264 passed (1502 assertions)   — identical, no state leak
```

**Documents module alone, real Postgres + real ClamAV** (the mandatory tests, run for real —
`--network anaf_data_net --network anaf_processing_net`, `CLAMAV_TEST_HOST=clamav
CLAMAV_TEST_PORT=3310` pointed at the capability-patched container described above):
```text
Run 1: Tests: 42 warnings, 31 passed (223 assertions)
Run 2: Tests: 42 warnings, 31 passed (223 assertions)   — identical
```
This includes `ClamdVirusScannerLiveTest`'s two tests and `ScanQuarantinedDocumentJobTest`'s
EICAR-through-the-real-job test **actually executing against a genuine ClamAV daemon** (not
skipped) — confirmed by their assertion counts being non-zero, vs. `0 assertions` when
`CLAMAV_TEST_HOST`/`CLAMAV_TEST_PORT` are unset and they skip cleanly.

**MinIO live round-trip**: not re-run for Documents specifically — `App\Support\ObjectStore\
EncryptedObjectStore`/`QuarantineObjectStore`'s raw-bucket guarantee was already proven live against
MinIO in `docs/handoff/012-encrypted-object-store.md`; this task's own raw-bucket assertions
(`RawStoragePlaintextTest`) reuse that exact class against `Storage::fake()`, the same pattern
`docs/handoff/012`'s own `EncryptedObjectStoreTest` uses for its non-live coverage.

## Interfaces exposed to other modules

- `Kern\Documents\Models\Document` — read-only reference point for a future Wallet/TaxEngine
  linkage (e.g. "this rental payment came from this bank-statement document") — no such linkage
  exists yet; not asked for.
- `Kern\Documents\Consent\DocumentAiConsentGate::isConsented(string $subjectId): bool` — any
  future module gating its own external-AI use on the same consent record should call this rather
  than duplicating consent storage.
- `Kern\Documents\ExternalAi\ExternalDocumentAiProvider` — the interface whoever resolves
  `docs/STATUS.md` blocked item #3 implements and binds in place of `NullDocumentAiProvider`.
- `Kern\Documents\Extraction\TextExtractionGateway` — the interface whoever builds the
  Python-side `/v1/documents/extract-text` endpoint should confirm this Laravel client's assumed
  request/response shape against (`HttpTextExtractionGateway`'s own docblock states the assumed
  contract in full).

## What is NOT done

- **No Python `/v1/documents/extract-text` HTTP endpoint.** `docs/handoff/019-pdf-text-
  extraction.md` built `processing.documents.extract_document()` as a plain Python function and
  explicitly left the HTTP endpoint as an open question. `HttpTextExtractionGateway` defines and
  documents an assumed contract so this task's pipeline had something concrete to build against —
  it is **unverified against a real running endpoint**. Whoever builds that endpoint must confirm
  or adjust: multipart `file` field, JSON response shape
  `{page_count, needs_ocr, quality_score, pages: [{page_number, text}]}`.
- **No external AI/OCR provider integration.** `NullDocumentAiProvider` always throws — this is
  the explicitly-requested "unimplemented seam," not an oversight. No document has ever been sent
  externally by this task's code; no network call to any AI provider exists anywhere in this
  codebase.
- **No document-download/content-serving endpoint.** Only status metadata is ever returned over
  HTTP. Serving original bytes (or a derivative, or a review UI for `PROPOSED` extractions) is
  future work — see the download-endpoint caveat under "What the PWA frontend will call."
  `Kern\Documents\Models\DocumentExtraction::review()` exists as the one sanctioned
  status-transition method for that future reviewer UI, but nothing calls it yet.
- **No re-trigger-on-consent-granted flow.** A document that reached `READY` without external
  extraction (no consent yet) is not automatically retried once consent is later granted — a human
  or a future scheduled job would need to re-invoke `RequestExternalDocumentExtraction` explicitly.
  Not asked for; flagged so it is not assumed to exist.
- **No encrypted-PDF ephemeral-password flow.** `docs/handoff/019`'s own "What is NOT done" #5
  still applies — this task did not add one either (the Python extraction call, once built, is
  expected to reject an encrypted PDF outright per that handoff; this task's `ProcessScannedDocument`
  would then see that as `TextExtractionUnavailableException`-shaped failure and leave the document
  at `PROCESSING`, retried forever — a real gap `docs/handoff/019`'s open question already named,
  not newly introduced here).
- **No bucket auto-provisioning for `kern-quarantine`.** Created once, out of band, for this
  task's own live verification (`mc mb --ignore-existing local/kern-quarantine`) — same situation
  `docs/handoff/012-encrypted-object-store.md` decision #7 already flagged for `kern-documents`,
  now doubled. Whoever builds the provisioning step should do both buckets together.
- **The `clamav` compose service capability defect** (see "How to verify" above) is a real,
  reproduced infra bug, not fixed here (`compose.yaml`/`docker/**` is out of this task's path
  scope) — flagged prominently so it is not silently rediscovered.
- **PHP-level internal auth between Laravel and `python-api` is unspecified.** `HttpTextExtractionGateway`
  sends no authentication header at all — network-level isolation (`processing_net`) is the only
  control today. `BLUEPRINT.md` §13.4 specifies mTLS/signed-JWT only for the `spv-browser` contract,
  not this one; inventing a scheme here would itself violate `docs/AGENTS.md` rule 1.
- **`docs/STATUS.md` was not updated** — explicitly out of this task's scope per the brief. M7's
  row should move from "partial — local extraction + OCR decision built [019]" to add this
  handoff, and the `clamav` capability defect should be added to "Known issues."

## Open questions

1. Whoever builds the Python `/v1/documents/extract-text` endpoint should treat
   `HttpTextExtractionGateway`'s docblock as a proposal, not a contract already agreed with the
   Python side — it was written by this task alone.
2. Should `document_ai_consents` eventually move under `App\Domain\Subjects` instead of
   `Kern\Documents`? It is conceptually "a subject-level setting," but its only reader/writer
   is this module, and `docs/AGENTS.md` §10 says extend an existing pattern rather than pre-emptively
   relocate something with one current consumer. Left here; revisit if a second module needs it.
3. The `clamav` compose capability fix (add `CAP_CHOWN`/`CAP_DAC_OVERRIDE`/`CAP_SETUID`/
   `CAP_SETGID`, or better, a one-shot init container ahead of it) needs a `docker/**`-scoped agent.
4. Whether `ProcessScannedDocument` should have its own `$tries`/backoff tuning distinct from
   Laravel's defaults, once real Python-service latency/outage patterns are observed — not decided
   here; no evidence yet that the default is wrong.

## Sources consulted

- `BLUEPRINT.md` §6.6 (`documents`/`document_derivatives`/`document_extractions` schema), §14 in full
  (upload/quarantine/OCR/AI-extraction/human-review), §16 (broadcast payload discipline), §4.6/§4.9
  (object encryption/integrity, reused verbatim), §3.1 (the `clamav` service).
- `docs/AGENTS.md` full read — rule 13 (no implicit external send), rule 15 (no polling where
  Reverb exists), §8 (broadcast discipline, cross-tenant tests), §21.4 (mandatory security tests).
- `docs/CODE-QUALITY.md`, `docs/DEPENDENCY-POLICY.md` — full read; no new Composer dependency added
  (justification for the hand-written ClamAV client is decision #3 above).
- `docs/handoff/012-encrypted-object-store.md` — read in full before writing any code; reused
  `EncryptedObjectStore`/`ObjectKey`/`DocumentObjectKind` verbatim, followed its live-MinIO test
  precedent for this task's own `CLAMAV_TEST_*` env-var naming.
- `docs/handoff/019-pdf-text-extraction.md` — read in full; reused its documented OCR-decision
  shape and prompt-injection framing (`UntrustedDocumentText`'s Laravel-side analogue is this
  task's own discipline of never putting extracted text in a broadcast/log), and its own explicit
  "no HTTP endpoint" gap is what `HttpTextExtractionGateway` had to work around.
- `docs/adr/0005-external-document-ai.md` — the consent/audit/DPA gate this task implements the
  Laravel half of.
- `docs/handoff/011-identity-tenancy.md` — read in full; every tenancy decision in this task
  (`ScopedToTaxSubject`, `TenantContext`, the `withoutGlobalScope` job pattern) follows its "How
  another module scopes a query to a subject correctly" section directly.
- `docs/handoff/016-postgres-suite-stabilisation.md`, `015-m1-integration-repairs.md` — the
  Postgres-vs-sqlite verification discipline and `RefreshDatabase`-per-directory precedent this
  task followed for `tests/Pest.php`.
- ClamAV's own INSTREAM protocol — verified directly against a real `clamav/clamav:1.5-debian13-slim`
  daemon (the exact image `compose.yaml` already pins), not assumed from third-party documentation
  alone; the EICAR test string is the industry-standard antivirus test file
  (`https://www.eicar.org/` — used here only as inert test data, no external fetch performed).
- Laravel 13 framework source read directly:
  `Illuminate\Foundation\Configuration\ApplicationBuilder::withRouting()`/`withBroadcasting()`
  (confirmed `channels` parameter wiring), `Illuminate\Config\Repository` (`string()`/`integer()`/
  `float()` typed accessors), `Illuminate\Http\Testing\FileFactory::createWithContent()`.
