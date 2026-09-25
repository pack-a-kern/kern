# 031 — PWA backend endpoints: subjects, extraction review, document content

**Milestone:** M7 (closes real gaps flagged by `docs/handoff/030-pwa-frontend.md`)
**Status:** complete
**Date:** 2026-09-08

## What was built

Three HTTP surfaces `docs/handoff/030-pwa-frontend.md`'s "Key decisions" #1/#2 and
`docs/handoff/022-document-pipeline.md`'s "What is NOT done" documented as real, missing backend
gaps — not UI polish. Nothing under `app/Domain/Spv/**` or `resources/js/**` was touched (both
explicitly out of this task's scope; another agent owns `Spv`).

1. **Tax subjects for the signed-in user** — a real listing driven by `subject_memberships`
   (`docs/handoff/011-identity-tenancy.md`) and PF registration wired to the existing
   `App\Domain\Subjects\Actions\RegisterPfSubject` action, which previously had no controller at
   all.
2. **Extraction review** — reading a document's proposed `document_extractions` rows, accepting
   one exactly as proposed, or correcting it. `BLUEPRINT.md` §14.6's "nothing auto-confirms; a human
   accepts or corrects" is enforced by `Kern\Documents\Models\DocumentExtraction::review()`
   (already existed, never called); correcting **never overwrites** the original row — it creates
   a new one and marks the old `REJECTED`, so both survive.
3. **Document preview/content** — decrypts and streams a document's original bytes back to its
   owner through the same tenant-scoped, session-authenticated path every other endpoint in this
   module uses; no separate signed-URL scheme, because none is possible (see "Key decisions" #4).

Entry points:

- `GET /api/v1/subjects` → `App\Http\Controllers\Api\V1\Subjects\ListSubjectsController`.
- `POST /api/v1/subjects/pf` → `App\Http\Controllers\Api\V1\Subjects\RegisterPfSubjectController`.
- `GET /api/v1/subjects/{subject}/documents/{document}/extractions` →
  `Kern\Documents\Http\Controllers\ListDocumentExtractionsController`.
- `POST .../extractions/{extraction}/accept` → `AcceptDocumentExtractionController`.
- `POST .../extractions/{extraction}/correct` → `CorrectDocumentExtractionController`.
- `GET /api/v1/subjects/{subject}/documents/{document}/content` → `ShowDocumentContentController`.

## Files created / changed

**Subjects** (`apps/api/app/Domain/Subjects/`)
- `Queries/SubjectSummary.php` — new. Readonly DTO: `id`, `type`, `displayName` (already
  decrypted), `status`, `role`.
- `Queries/ListSubjectsForUser.php` — new. Reads `SubjectMembership` (active only,
  `whereNull('revoked_at')`) joined in PHP to `TaxSubject`, cursor-paginated. Deliberately bypasses
  `TenantContext` — this query answers "which subjects could I activate," which must be answerable
  *before* any one of them is bound.

**Documents** (`apps/api/packages/documents/src/`)
- `Enums/ExtractorType.php` — added `HUMAN_CORRECTION` (the extractor type a correction row is
  tagged with).
- `Models/DocumentExtraction.php` — added `withEncryptionSubjectId(string $subjectId): static`
  (see "Key decisions" #1 — a real, load-bearing gap this task found, not a style choice).
- `Review/AcceptDocumentExtraction.php` — new. Requires `status === PROPOSED`; calls
  `DocumentExtraction::review($reviewerId, ACCEPTED)`; records a `DocumentExtractionAccepted`
  audit event.
- `Review/CorrectDocumentExtraction.php` — new. Requires `status === PROPOSED`; transitions the
  original to `REJECTED` (content untouched), creates a new `HUMAN_CORRECTION` row with the
  corrected fields, immediately `ACCEPTED` under the same reviewer; records a
  `DocumentExtractionCorrected` audit event naming both extraction ids.
- `Review/Exceptions/ExtractionAlreadyReviewedException.php` — new.

**HTTP** (`apps/api/app/Http/Controllers/Api/V1/`)
- `Subjects/ListSubjectsController.php`, `Subjects/RegisterPfSubjectController.php` — new.
- `Documents/ListDocumentExtractionsController.php`, `Documents/AcceptDocumentExtractionController.php`,
  `Documents/CorrectDocumentExtractionController.php`, `Documents/ShowDocumentContentController.php`
  — new.

**Routing**
- `apps/api/routes/api.php` — added an unscoped `Route::prefix('v1/subjects')->middleware('auth:web')`
  group (list/register — neither binds `{subject}`/`tax-subject`, since listing must work *before*
  a subject is chosen and registration creates one), and four new routes inside the existing
  `Route::prefix('v1/subjects/{subject}')->middleware(['auth:web', 'tax-subject'])` group.

**Tests** (`apps/api/tests/Feature/Domain/`)
- `Subjects/ListSubjectsControllerTest.php`, `Subjects/RegisterPfSubjectControllerTest.php`.
- `Documents/DocumentExtractionReviewControllerTest.php`, `Documents/ShowDocumentContentControllerTest.php`.

No migration was added (no schema change was needed — `extractor_type`/`status` are plain string
columns, not DB-level enums), no `deptrac.php` change was needed (`Documents` already has
`accesses($support, $subjects, $audit)`; `Subjects` already has `accesses($support, $audit)` — my
new `Queries`/`Review` namespaces stay inside those same two layers), and no dependency was added.

## Key decisions

1. **A real, load-bearing gap this task found: `DocumentExtraction::result_ciphertext` could not
   be decrypted on any row loaded back from the database — only on the exact PHP object
   `propose()` had just returned in the same process.** `encryptionSubjectId()`'s only source of
   truth is a plain, non-persisted PHP property (`encryptionSubjectIdOverride`) that `propose()`
   sets — by design, per that model's own docblock, to avoid needing `TenantContext` bound in
   queue-job contexts. Nothing before this task ever read `result_ciphertext` back after creation
   (the pipeline only ever wrote it), so this was never exercised. `ListDocumentExtractionsController`
   is the first code path that does, and it failed loudly in a real Postgres test run
   (`RuntimeException: DocumentExtraction::propose() must be used; the subject id was never set.`)
   before this was fixed — not discovered by inspection. Fix: `DocumentExtraction::
   withEncryptionSubjectId(string $subjectId): static`, an explicit setter for exactly this case —
   a caller reading extractions back always already has the owning, tenant-scoped `Document` (and
   therefore its `tax_subject_id`) in hand, exactly the same information `propose()` itself uses.
   This is additive and preserves the original design's reasoning (no automatic relation lookup
   inside `encryptionSubjectId()`, so no implicit `TenantContext` requirement is reintroduced) —
   it only adds an explicit, named way to supply what `propose()` already supplies implicitly.

2. **Correcting an extraction creates a second row; it never overwrites `result_ciphertext` in
   place.** `Kern\Documents\Review\CorrectDocumentExtraction` transitions the original to
   `ExtractionStatus::REJECTED` (a real, sanctioned `DocumentExtraction::review()` call — its
   content is never touched by that call) and writes the correction through the same
   `DocumentExtraction::propose()` used everywhere else, tagged `ExtractorType::HUMAN_CORRECTION`
   and immediately `review()`-ed to `ACCEPTED`. Both rows share `document_id`, so "what did the
   model say, and what did the human decide instead" is reconstructable from the table alone —
   no new column was needed (and none was added, since migrations are outside this task's file
   scope). A human directly supplying a value *is* the review decision for that new row — there is
   no separate "propose, then review" step the way a machine proposal needs one.

3. **The correction request body is `{"fields": {...}}` with no per-field schema validation
   beyond "a non-empty JSON object."** `BLUEPRINT.md` §14.4 describes a JSON schema per document
   type, but none exists anywhere in this codebase yet — `Kern\Documents\ExternalAi\
   DataTransferObjects\DocumentAiResponse::$result` is already a bare `array<array-key, mixed>`
   with the same honesty note. Inventing a schema here would be exactly the fabrication
   `docs/AGENTS.md` §2 forbids. Flagged under "What is NOT done" below, not silently assumed away.

4. **There is no signed/temporary-URL scheme for document content, by design, not by omission.**
   The object is encrypted per-subject (`BLUEPRINT.md` §4.6); a raw storage URL — even a presigned
   S3/MinIO one — would only ever hand back ciphertext, since decryption requires
   `App\Support\KeyManagement\SubjectDekService`, reachable only from inside this authenticated
   application. So `GET .../documents/{document}/content` *is* the only possible access path, and
   it is authorized exactly like every other endpoint here: the caller's session cookie plus an
   active, non-revoked `SubjectMembership`, re-checked on **every single request** by
   `App\Http\Middleware\BindTaxSubjectContext` (nothing is cached across requests). There is no
   independent, shareable token that could leak and keep working after the session/membership that
   produced it is revoked — unlike a long-lived presigned URL, this "link" is only ever as valid as
   the caller's own current, revocable session. This directly answers the task brief's "how a
   preview URL is authorised and how long it stays valid."
5. **Content is available from `SCANNED` onward, not only `READY`.** `docs/handoff/022`'s own
   docblock said so explicitly ("for the original file, any non-`QUARANTINE`/`REJECTED` status") —
   the original bytes are promoted out of quarantine into the real object store the moment ClamAV
   clears them (`ScanQuarantinedDocument`), before native-text extraction even runs. `QUARANTINE`
   (not yet scanned) and `REJECTED` (deleted from quarantine, never promoted) both return a
   structured `409 {"reason": "CONTENT_NOT_AVAILABLE"}`, never a blank/broken response.
6. **`response()->streamDownload(..., 'inline')` (Symfony's `HeaderUtils::makeDisposition`), not a
   hand-rolled `Content-Disposition` header.** The stored filename is already sanitized at upload
   time (`Kern\Documents\FileSecurity\FilenameSanitizer`), but building the header via
   Symfony's own RFC 6266 encoder is still safer than string-formatting it by hand, and is what
   `EncryptedObjectStore::get()`'s already-bounded-memory stream (chunked, spills to disk past a
   small in-memory threshold — `docs/handoff/012-encrypted-object-store.md`) is piped straight
   into via `fpassthru()` — the decrypted plaintext is never buffered whole in PHP memory.
   `Content-Length` is deliberately **not** set: nothing in this codebase enforces that
   `documents.size_bytes` is byte-identical to the object actually stored (a factory/test row can
   set it to an arbitrary value), and a wrong `Content-Length` on a streamed response causes a
   truncated download or a hung connection — a worse failure mode than the extra chunked-encoding
   overhead of leaving it unset.
7. **`ListSubjectsForUser` reads `SubjectMembership`/`TaxSubject` directly, not through
   `TenantContext`.** This query answers "which subjects could I activate" — a question that must
   be answerable *before* any one of them is bound — not "act as an already-chosen one," so
   `TenantContext::authorizeAndActivate()` (which requires a `{subject}` already known) does not
   fit here. Both models live in the same `Subjects` module, so this is an ordinary intra-module
   read, not a crossing `deptrac.php` needs to know about.
8. **CNP/birth-date/etc. validation in `RegisterPfSubjectController` is shape-only** (a 13-digit
   pattern tolerant of spaces/dashes, `Y-m-d` date format) — never a fiscal or legal check
   (`BLUEPRINT.md` §2.3: no fiscal logic in controllers). The actual duplicate-CNP business rule
   still lives entirely in `App\Domain\Subjects\Actions\RegisterPfSubject`, reused verbatim.

## How to verify

Created a dedicated database per `docs/AGENTS.md` §7b (another agent was active in
`app/Domain/Spv/**` at the time): `docker exec anaf-postgres-1 psql -U kern -c 'CREATE DATABASE
kern_pwaapi;'`. All commands below run for real against the existing `kern-php-test:local` image
(`docs/handoff/008-ci-pipeline.md`'s test image), output pasted verbatim.

```
$ docker run --rm -v apps/api:/var/www/html -w /var/www/html kern-php-test:local composer validate --no-check-all
./composer.json is valid

$ docker run --rm -v apps/api:/var/www/html -w /var/www/html kern-php-test:local composer audit
No security vulnerability advisories found.

$ docker run --rm -v apps/api:/var/www/html -w /var/www/html kern-php-test:local vendor/bin/pint --test
  PASS .......................................................... 763 files

$ docker run --rm -v apps/api:/var/www/html -w /var/www/html -e APP_KEY=base64:... \
    kern-php-test:local php -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G
[ERROR] Found 8 errors
```
All 8 are in `app/Domain/Spv/**` (`ApplyPortalAuthResult.php`, `DestroySpvSession.php`,
`SubmitSpvChallengeCode.php`, `SyncSpvInbox.php`, `SpvInboxMessage.php`,
`tests/Feature/Domain/Spv/RawDatabasePlaintextTest.php`) — a module explicitly out of this task's
scope, owned by a concurrently active agent, pre-existing and unrelated to any file this task
touched. **Zero errors in every file this task created or changed.** Recorded here per
`docs/AGENTS.md` §10 (record a bug found outside your own scope, don't fix it silently) rather than
touched.

```
$ docker run --rm -v apps/api:/var/www/html -w /var/www/html kern-php-test:local vendor/bin/deptrac analyse --config-file=deptrac.php
 Violations 0, Skipped violations 0, Uncovered 638, Allowed 317, Warnings 0, Errors 0

$ docker run --rm --network anaf_data_net --user root \
    -e APP_KEY=base64:... -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_PORT=5432 \
    -e DB_DATABASE=kern_pwaapi -e DB_USERNAME=kern -e DB_PASSWORD=... \
    -v apps/api:/var/www/html -w /var/www/html kern-php-test:local vendor/bin/pest
Run 1: Tests: 3 warnings, 10 skipped, 700 passed (2002 assertions)
Run 2: Tests: 3 warnings, 10 skipped, 700 passed (2002 assertions)   — identical, no state leak
```
The 3 warnings are the pre-existing missing-`.env`-in-test-image artifact `docs/handoff/022`
already documented, affecting untouched modules too (e.g. `Tests\Feature\HealthEndpointTest`). The
10 skips are the live-OpenBao/live-MinIO tests, correctly self-skipping with no
`OPENBAO_TEST_*`/`OBJECTSTORE_TEST_*` env vars set in this run — not a gap in this task's own
coverage (its own new tests all use the same `InMemoryKeyManagementService`/`Storage::fake('s3')`
pattern every other Documents/Subjects test in this codebase already uses).

## Interfaces exposed to other modules / the frontend

### `GET /api/v1/subjects`
Cursor-paginated (`BLUEPRINT.md` §18), behind `auth:web` only (no `{subject}` to bind yet).
```json
{
  "data": [
    {"id": "01ARZ3...", "type": "PF", "display_name": "Ion Popescu", "status": "ACTIVE", "role": "OWNER"}
  ],
  "path": "http://.../api/v1/subjects",
  "per_page": 20,
  "next_cursor": "eyJ...",
  "next_page_url": "http://.../api/v1/subjects?cursor=eyJ...",
  "prev_cursor": null,
  "prev_page_url": null
}
```
Query params: `cursor` (opaque, from `next_cursor`/`prev_cursor`), `per_page` (1–100, default 20).
`401` if unauthenticated. Never lists a subject whose membership is revoked or belongs to another
user.

### `POST /api/v1/subjects/pf`
Body: `{"display_name", "first_name", "last_name", "cnp", "birth_date" (Y-m-d), "county_code"?,
"locality"?, "address"?, "anaf_tax_office_code"?}` (all but the four `?`-suffixed keys required).
- `201 {"id": "<new tax_subject_id>"}` — caller becomes `OWNER`.
- `422` standard Laravel validation envelope (`{"message", "errors": {field: [...]}}`) for shape
  errors.
- `422 {"message": "...", "reason": "DUPLICATE_CNP"}` if the CNP is already registered under a
  different subject.
- `401` if unauthenticated.

### `GET /api/v1/subjects/{subject}/documents/{document}/extractions`
Cursor-paginated. `404` if `{document}` does not belong to `{subject}` or does not exist. `403` if
the caller has no active membership on `{subject}`.
```json
{
  "data": [
    {
      "id": "...", "extractor_type": "EXTERNAL_DOCUMENT_AI", "schema_version": "1.0.0",
      "confidence": "0.9500", "status": "PROPOSED", "reviewed_by": null,
      "proposed_fields": {"invoice_number": "INV-001", "total_amount": "123.45"},
      "created_at": "2026-09-08T12:00:00+00:00", "updated_at": "2026-09-08T12:00:00+00:00"
    }
  ],
  "path": "...", "per_page": 20, "next_cursor": null, "prev_cursor": null
}
```

### `POST .../documents/{document}/extractions/{extraction}/accept`
No body. `200 {"id", "status": "ACCEPTED", "reviewed_by": "<user id>", "reviewed_at": "<ISO8601>"}`.
`409 {"message", "reason": "ALREADY_REVIEWED"}` if not `PROPOSED`. `404` if `{extraction}` does not
belong to `{document}`.

### `POST .../documents/{document}/extractions/{extraction}/correct`
Body: `{"fields": {...non-empty object...}}`.
`201 {"id": "<new extraction id>", "status": "ACCEPTED", "reviewed_by", "reviewed_at",
"supersedes_extraction_id": "<original id>", "proposed_fields": {...as submitted...}}`.
`422` if `fields` is missing/empty/not an object. `409 {"reason": "ALREADY_REVIEWED"}` if the
target is not `PROPOSED`. `404` under the same rules as accept.

### `GET /api/v1/subjects/{subject}/documents/{document}/content`
Streams the decrypted original bytes with `Content-Type: <document mime_type>` and an `inline`
`Content-Disposition` naming the stored (already-sanitized) filename. `409
{"message", "reason": "CONTENT_NOT_AVAILABLE"}` if `status` is `QUARANTINE` or `REJECTED`. `404` if
`{document}` does not belong to `{subject}`. `403` with no active membership.

## What the frontend must change to consume this (nothing under `resources/js/**` was touched)

- Replace `resources/js/lib/subjectStore.ts`'s `localStorage`-only "type your subject id" flow with
  a real call to `GET /api/v1/subjects`, and add a PF-registration form calling
  `POST /api/v1/subjects/pf` — exactly the gap `docs/handoff/030`'s Key decision #1 and "Interfaces
  exposed to other modules" section named.
- `documents/review.tsx`'s "Exemplu ilustrativ" fixed three-field placeholder
  (`docs/handoff/030` Key decision #2) should be replaced with a real fetch of
  `GET .../documents/{document}/extractions`, and its disabled Accept/Correct buttons wired to the
  two new `POST` endpoints above.
- The "Sursă" (source) panel that currently states no preview endpoint exists
  (`docs/handoff/030` Key decision #2) can now point an `<iframe>`/`<img>`/download link at
  `GET .../documents/{document}/content` directly — it is same-origin, cookie-authenticated, so no
  token handling is needed client-side; a `409` response should render the same "not ready yet"
  state the UI already shows today.
- `resources/js/types/documents.ts` needs new `parse*()` functions for the four new response
  shapes above, following the same hand-written runtime-validation pattern (`docs/handoff/030` Key
  decision #4) already used for every other endpoint.

## What is NOT done

- **No per-document-type JSON schema validation on `correct`'s `fields` body** — see "Key
  decisions" #3. Only "non-empty JSON object" is enforced.
- **No listing of *all* documents for a subject** — out of this task's three named gaps; the
  inbox's own "this device only" limitation (`docs/handoff/030` "What is NOT done") is unaffected.
- **No re-trigger of external extraction after a correction** — a `CorrectDocumentExtraction` call
  does not attempt to re-run `RequestExternalDocumentExtraction`; not asked for.
- **No rate limiting was added to any of the six new routes.** Every existing rate-limited route in
  this codebase protects either an unauthenticated endpoint or a heavy upload; all six new routes
  require an authenticated session and an active membership already, and adding a limiter for its
  own sake was judged out of this task's asked-for scope (`docs/CODE-QUALITY.md` §2 — build only
  what was asked). Worth reconsidering if `POST /v1/subjects/pf` (duplicate-CNP probing) or
  `.../content` (repeated decrypt-and-stream) show abuse in practice.
- **`docs/STATUS.md` was not updated** — explicitly excluded from this task's scope by the brief.
  Whoever next edits it should add this handoff under M7 and note the two pre-existing `app/Domain/
  Spv/**` PHPStan findings recorded above if they are not already tracked there.
- Did not touch `resources/js/**`, `app/Domain/Spv/**`, `database/migrations/**`, or
  `docker/**` — all explicitly out of this task's scope.

## Open questions

1. Should `ListSubjectsForUser`'s default page size (20) differ for this specific listing? A user
   realistically has very few subjects; cursor pagination was still applied for consistency with
   `BLUEPRINT.md` §18 and to avoid a second, inconsistent "small list, no pagination" convention
   appearing in the API.
2. Should `document_extractions` eventually gain an explicit `supersedes_extraction_id` column so
   the model-vs-human relationship is queryable in SQL, not just reconstructable from
   `document_id` + `status` + timestamps? Not added here — outside this task's migration-free file
   scope, and the response body already surfaces `supersedes_extraction_id` to callers who need it
   at correction time.
3. `Kern\Documents\Models\DocumentExtraction::withEncryptionSubjectId()` is a fairly sharp
   tool — calling it with the wrong subject id would not silently succeed (the AAD binding would
   make decryption fail loudly), but nothing stops a future caller from simply forgetting to call
   it at all, which throws a `RuntimeException` rather than failing at the type level. Left as is,
   consistent with `propose()`'s own equally-implicit contract, but worth a shared review if a
   third read-back call site is ever added.

## Sources consulted

- `docs/handoff/030-pwa-frontend.md` — read in full; its "Key decisions" #1/#2 are this task's
  literal work list, and its exact request/response shape expectations (`DocumentSummary`,
  `DocumentLifecycleEvent`) were cross-checked against the real controllers before adding new ones
  alongside them.
- `docs/handoff/011-identity-tenancy.md` — `TenantContext`/`ScopedToTaxSubject`/`SubjectMembership`
  read in full; `ListSubjectsForUser`'s "revoked membership denies identically to never existed"
  rule follows `TenantContext::authorizeAndActivate()`'s own rule exactly.
- `docs/handoff/022-document-pipeline.md`, `docs/handoff/027-contract-reconciliation.md` — read in
  full; `UntrustedDocumentText` and the document lifecycle states were cross-checked against the
  real `Document`/`DocumentExtraction` models and `ProcessScannedDocument`/`ScanQuarantinedDocument`
  jobs before deciding which statuses make content available (Key decision #5) and confirming this
  task's extraction-review endpoints never touch `UntrustedDocumentText` at all (they read
  `document_extractions.result_ciphertext`, a separate, already-structured JSON field — never the
  native-text derivative).
- `docs/handoff/012-encrypted-object-store.md` — `EncryptedObjectStore`/`ObjectKey`/
  `DocumentObjectKind` read in full and reused verbatim for the content endpoint.
- `BLUEPRINT.md` §14.6, §16, §18, §21.4 — the literal brief for this task's three requirements
  (human-in-the-loop review, no PII in broadcasts/logs, JSON envelope/cursor pagination,
  cross-tenant isolation tests).
- `docs/AGENTS.md` §6 (module boundaries), §7b (dedicated-database testing — followed exactly,
  `kern_pwaapi`), §10 (recording the pre-existing `app/Domain/Spv/**` PHPStan findings rather than
  fixing them).
- `docs/CODE-QUALITY.md` — §1/§2 applied to Key decisions #3 (no invented schema) and the "no new
  rate limiter" call in "What is NOT done."
- Source files read directly, not assumed: `apps/api/routes/api.php`, `routes/channels.php`,
  `bootstrap/app.php`, `app/Http/Middleware/BindTaxSubjectContext.php`,
  `packages/documents/src/Models/{Document,DocumentExtraction,DocumentDerivative}.php`,
  `packages/documents/src/Jobs/{ScanQuarantinedDocument,ProcessScannedDocument}.php`,
  `app/Domain/Subjects/{Actions/RegisterPfSubject,Models/*,Tenancy/*}.php`,
  `app/Support/ObjectStore/{EncryptedObjectStore,ObjectKey,DocumentObjectKind}.php`,
  `app/Domain/Audit/{Services/AuditChainService,DataTransferObjects/AuditEventInput}.php`, and the
  existing `tests/Feature/Domain/Documents/{ShowDocumentControllerTest,CrossTenantIsolationTest,
  DocumentExtractionAutoConfirmTest}.php` for established test conventions this task's own tests
  follow.
- Laravel/Symfony framework source read directly: `Illuminate\Pagination\{CursorPaginator,
  AbstractCursorPaginator}` (`toArray()`/`through()` semantics, confirmed rather than assumed),
  `Illuminate\Routing\ResponseFactory::stream()`/`streamDownload()` (return types, `HeaderUtils::
  makeDisposition` disposition-header encoding).
