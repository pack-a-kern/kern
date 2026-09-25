# 027 — Contract reconciliation: Laravel `HttpTextExtractionGateway` vs. the real `python-api` endpoint

**Milestone:** M7   **Status:** complete
**Date:** 2026-09-08

## What was built

`docs/handoff/024-extraction-endpoint.md` built the real `POST /v1/documents/extract-text`
endpoint on the Python side and found it differs from `docs/handoff/022-document-pipeline.md`'s
assumed contract in five specific ways. This task closes that gap on the Laravel side —
`apps/api/packages/documents/src/Extraction/HttpTextExtractionGateway.php` and everything downstream
of it — so the two services actually agree, and proves it with a real call against a live
`python-api` container, not just a `Http::fake()`.

All five mismatches are closed:

1. **Auth.** `HttpTextExtractionGateway` now sends `Authorization: Bearer <token>` via Laravel's
   `withToken()`. The token comes from `config('documents.text_extraction.auth_token')`, backed by
   a new `INTERNAL_SERVICE_AUTH_TOKEN` env var wired identically into both the `app`/`queue`/
   `scheduler`/`reverb` containers (`compose.yaml`'s `&php-environment` anchor) and `python-api`'s
   own environment block — the same underlying secret, same variable name, on both sides. Laravel
   fails closed exactly like the Python side does: an empty token throws
   `TextExtractionUnavailableException::authenticationNotConfigured()` **before any request is
   sent** (verified — see "How to verify"), and a `401` response (wrong/mismatched secret) throws
   `TextExtractionUnavailableException::authenticationRejected()`. Neither exception message, nor
   any log line, ever contains the token value (`BLUEPRINT.md` §4.10).
2. **`quality_score` is per-page.** `HttpTextExtractionGateway::parse()` no longer looks for a
   top-level `quality_score` (which doesn't exist). It reads `quality_score` from each page object
   into a new `PageExtractionResult` DTO, one per page, keyed by page number in
   `NativeTextExtractionResult::$pages` (replacing the old flat `$pageTexts` array + single
   top-level `$qualityScoreDecimal` field).
3. **`text` is `{"kind": "untrusted_document_text", "content": ...}`.** `parse()` validates the
   `kind` discriminator and unwraps `content` into a new `UntrustedDocumentText` value object
   (`Kern\Documents\Extraction\DataTransferObjects\UntrustedDocumentText`) — a Laravel-side
   mirror of the Python type from `docs/handoff/019-pdf-text-extraction.md`: not `Stringable`-
   transparent (`(string) $wrapped` renders a redacted marker, never the content), content only
   reachable via the explicit `reveal()` call. This is threaded all the way through
   `NativeTextExtractionResult::joinedText()` (now returns `UntrustedDocumentText`, not `string`),
   `ProcessScannedDocument` (reveals it exactly twice: once to write raw bytes to the encrypted
   derivative, once left un-revealed and passed straight through to the external-AI request), and
   `Kern\Documents\ExternalAi\DataTransferObjects\DocumentAiRequest::$extractedText`
   (retyped from `?string` to `?UntrustedDocumentText`) and
   `RequestExternalDocumentExtraction::__invoke()`'s second parameter. Nothing downstream holds a
   bare `string` of document content any earlier than an explicit, grep-able `reveal()` call.
4. **Specific error codes are now mapped, not collapsed.** A new
   `Kern\Documents\Extraction\Exceptions\DocumentRejectedByExtractionException` (carrying a
   closed `RejectionReason`) is thrown for the service's documented "this document is bad" codes —
   `file_too_large` (413), `invalid_magic_bytes`, `encrypted_pdf`, `too_many_pages`,
   `empty_document`, `malformed_pdf`, `extraction_timeout`, `extraction_failed` (all 422) — via a
   lookup table in `HttpTextExtractionGateway::REJECTION_REASONS_BY_ERROR_CODE`.
   `TextExtractionUnavailableException` is now reserved for genuinely operational failures: `401`,
   an unrecognised error code, any other non-2xx status, a connection failure, or an unparseable
   response. `ProcessScannedDocument` treats these two differently: a `DocumentRejectedByExtractionException`
   moves the document straight to `DocumentStatus::REJECTED` with the matching `RejectionReason`
   (no retry — the same document will fail the same way every time) and broadcasts a new
   `DocumentTextExtractionRejected` event; a `TextExtractionUnavailableException` still propagates,
   the job retries, and the document stays at `PROCESSING` (unchanged from before).
5. **No invented "decompression bomb" code.** `REJECTION_REASONS_BY_ERROR_CODE` only contains the
   eight codes the service actually documents. `extraction_timeout` and `malformed_pdf` are mapped
   as ordinary documented codes; no `BOMB`/`decompression_bomb` `RejectionReason` case was added,
   and `RejectionReason`'s new cases carry an explicit comment saying why not, so a future agent
   doesn't reintroduce one.

`RejectionReason` gained six new cases (`ENCRYPTED_PDF`, `TOO_MANY_PAGES`, `EMPTY_DOCUMENT`,
`MALFORMED_PDF`, `EXTRACTION_TIMEOUT`, `EXTRACTION_FAILED`); it reuses the existing `FILE_TOO_LARGE`
and `INVALID_MAGIC_BYTES` cases rather than duplicating them, since the Python service's file-size
and magic-byte rejections are the same concept as the upload-time ones, discovered later in the
pipeline. `DocumentStatus`'s docblock now documents the new `PROCESSING -> REJECTED` transition.

## Files created / changed

- `apps/api/packages/documents/src/Extraction/DataTransferObjects/UntrustedDocumentText.php` — new.
  The Laravel-side untrusted-text marker (mismatch #3).
- `apps/api/packages/documents/src/Extraction/DataTransferObjects/PageExtractionResult.php` — new.
  Per-page `pageNumber`/`qualityScoreDecimal`/`text` (mismatch #2 + #3).
- `apps/api/packages/documents/src/Extraction/DataTransferObjects/NativeTextExtractionResult.php` —
  `$pageTexts`/`$qualityScoreDecimal` replaced by `$pages` (`array<int, PageExtractionResult>`);
  `joinedText()` now returns `UntrustedDocumentText`.
- `apps/api/packages/documents/src/Extraction/HttpTextExtractionGateway.php` — rewritten: sends the
  bearer token (fails closed if empty), maps `401` and the eight documented error codes distinctly,
  parses the real per-page `quality_score`/structural `text` shape. Docblock now documents the real
  contract instead of the old assumed one.
- `apps/api/packages/documents/src/Extraction/TextExtractionGateway.php` — `@throws` updated to name
  both exception types and when each applies.
- `apps/api/packages/documents/src/Extraction/Exceptions/DocumentRejectedByExtractionException.php`
  — new. Carries a `RejectionReason`; never retried.
- `apps/api/packages/documents/src/Extraction/Exceptions/TextExtractionUnavailableException.php` —
  added `authenticationNotConfigured()`/`authenticationRejected()`; docblock now distinguishes this
  class from the new rejection exception.
- `apps/api/packages/documents/src/Events/DocumentTextExtractionRejected.php` — new broadcast event,
  mirroring `DocumentScanned`'s contract (status/reason/progress only, never document content).
- `apps/api/packages/documents/src/Jobs/ProcessScannedDocument.php` — catches
  `DocumentRejectedByExtractionException` and rejects the document instead of retrying; reveals
  `UntrustedDocumentText` only at the two boundaries that need raw bytes/an already-documented data
  field.
- `apps/api/packages/documents/src/ExternalAi/DataTransferObjects/DocumentAiRequest.php` —
  `$extractedText` retyped `?string` -> `?UntrustedDocumentText`.
- `apps/api/packages/documents/src/ExternalAi/RequestExternalDocumentExtraction.php` — `__invoke()`'s
  second parameter retyped to match.
- `apps/api/packages/documents/src/DocumentsServiceProvider.php` — passes the configured auth token
  into `HttpTextExtractionGateway`'s constructor.
- `apps/api/packages/documents/src/Enums/RejectionReason.php` — six new cases (see above).
- `apps/api/packages/documents/src/Enums/DocumentStatus.php` — docblock documents `PROCESSING ->
  REJECTED`.
- `apps/api/config/documents.php` — new `text_extraction.auth_token` key, `env('INTERNAL_SERVICE_AUTH_TOKEN', '')`.
- `compose.yaml` — `INTERNAL_SERVICE_AUTH_TOKEN` added to `&php-environment` (shared by
  app/queue-default/scheduler/reverb) and to `python-api`'s own `environment:` block, both
  `${INTERNAL_SERVICE_AUTH_TOKEN:?set INTERNAL_SERVICE_AUTH_TOKEN — see .env.example}`.
- `.env.example` — documents the new `INTERNAL_SERVICE_AUTH_TOKEN` variable.
- `apps/api/tests/Feature/Domain/Documents/ProcessScannedDocumentJobTest.php` — response fixtures
  rewritten to the real shape; new tests for the auth header being sent, auth-not-configured,
  401-rejected, each of the eight documented rejection codes (data provider), and an unrecognised
  error code falling back to "unavailable."
- `apps/api/tests/Feature/Domain/Documents/HttpTextExtractionGatewayTest.php` — new. Exercises
  `HttpTextExtractionGateway` directly: real per-page `quality_score`/structural-text parsing, three
  distinct malformed-shape rejections (bare-string `text`, wrong `kind`, old top-level
  `quality_score`), the bearer token being sent, and fail-closed-with-no-request behaviour.
- `apps/api/tests/Unit/Domain/Documents/UntrustedDocumentTextTest.php` — new. Redaction, `reveal()`,
  "is not a string," `join()`, `equals()`.
- `apps/api/tests/Unit/Domain/Documents/BroadcastPayloadContainsNoPiiTest.php` — added a case for
  `DocumentTextExtractionRejected` (status/reason/progress only, never document content).
- `apps/api/tests/Feature/Domain/Documents/RequestExternalDocumentExtractionTest.php` — updated to
  construct `UntrustedDocumentText::wrap(...)` instead of a bare string, matching the new signature.

No file under `apps/processing/**` was touched. No file in `app/Domain/{TaxEngine,TaxKnowledge,
Rental}/**` was touched. `docs/STATUS.md` was not touched, per this task's scope.

## Key decisions

**Reused existing `RejectionReason` cases where the concept is identical.** `file_too_large` (413)
and `invalid_magic_bytes` (422) map to the pre-existing `RejectionReason::FILE_TOO_LARGE`/
`INVALID_MAGIC_BYTES` cases rather than new ones — the Python service is rejecting the same kind of
defect the upload-time `FileSecurityValidator` already names, just discovered later in the
pipeline. Adding a duplicate case for the same concept would be exactly the kind of stable-domain-
knowledge duplication `docs/CODE-QUALITY.md` §1 forbids.

**A single `DocumentRejectedByExtractionException` parameterised by `RejectionReason`, not eight
subclasses.** `FileSecurity/Exceptions/FileSecurityException`'s subclass-per-reason pattern exists
because each of those exceptions also carries reason-specific constructor data (e.g.
`FileTooLargeException::exceeds(int $maxBytes)`). Every extraction-rejection code here is a plain
passthrough of the service's own `error`/`message`, so one class with a `forReason()` factory is the
simpler, equally-typed answer — `docs/CODE-QUALITY.md` §4 warns against building unnecessary
abstraction/subclass machinery to avoid a small amount of repetition.

**A new `DocumentTextExtractionRejected` broadcast event, not reuse of `DocumentScanned`.**
`DocumentScanned`'s own docblock scopes it explicitly to "the ClamAV scan finished" — reusing it for
a different pipeline stage would have made its own contract a lie. `DocumentStatus`'s docblock
already anticipated this exact scenario ("`SCANNED -> REJECTED` ... reserved for a future rejection
reason discovered after a clean AV scan, e.g. an encrypted PDF") — updated to name the real
transition (`PROCESSING -> REJECTED`) now that it is implemented.

**`UntrustedDocumentText` is revealed at exactly two call sites in `ProcessScannedDocument`**: once
to write raw bytes into the encrypted `document_derivatives` object (an internal storage operation,
not a decision-influencing consumer of the content), and — deliberately **not** revealed — when
handed to `RequestExternalDocumentExtraction`, whose `DocumentAiRequest` DTO now carries
`?UntrustedDocumentText` all the way to the (currently unbound, `NullDocumentAiProvider`) external
provider boundary. This is closer to `docs/handoff/019`'s "reveal exactly once, at the actual
serialization/external boundary" intent than revealing immediately after extraction and holding a
bare string for the rest of the job.

**PHPStan level 9 forced two small implementation choices**, both purely mechanical, noted here so
a future reader isn't puzzled by them: (1) `HttpTextExtractionGateway::throwForFailedResponse()`
decodes the failed-response body with `json_decode($response->body(), true)` rather than
`Response::json()`, because Laravel's `Response::$decoded` property is typed `@var array`, making
PHPStan treat a subsequent `is_array()` guard as statically redundant even though a non-2xx body
(e.g. a plain-text `503`) is not always valid JSON at all. (2) The "is not a string" unit test for
`UntrustedDocumentText` routes the value through a `mixed`-typed identity helper before calling
`is_string()`, for the same reason — otherwise PHPStan proves the check's result at analysis time
and flags it as redundant, defeating the point of a regression guard against the design being
weakened later.

## How to verify

All commands below were actually run; output is real, not asserted.

**Build the test image** (`docker/php/Dockerfile --target test`, per `docs/handoff/008-ci-pipeline.md`):
```
$ docker build -f docker/php/Dockerfile --target test -t kern-php-test:local .
... (builds clean)
```

**`composer validate` / `composer audit`** (no dependency changed):
```
$ docker run --rm kern-php-test:local composer validate --no-check-all
./composer.json is valid
$ docker run --rm kern-php-test:local composer audit
No security vulnerability advisories found.
```

**Pint:**
```
$ docker run --rm kern-php-test:local vendor/bin/pint --test
PASS ... 619 files
```

**PHPStan, level 9, no baseline:**
```
$ docker run --rm -e APP_KEY=base64:... kern-php-test:local vendor/bin/phpstan analyse --memory-limit=1G
[OK] No errors
```

**Deptrac** (Documents module only touched; 0 violations, unchanged from before this task):
```
$ docker run --rm kern-php-test:local vendor/bin/deptrac analyse --config-file=deptrac.php
Violations 0, Skipped violations 0, Uncovered 508, Allowed 254, Warnings 0, Errors 0
```

**Whole suite, sqlite:**
```
$ docker run --rm -e APP_KEY=base64:... kern-php-test:local vendor/bin/pest
Tests: 336 warnings, 286 passed (1670 assertions)
```
(The per-test "warning" is the pre-existing missing-`.env`-in-test-image artifact noted in
`docs/handoff/022`, unrelated to this task, present across untouched modules too.)

**Whole suite, real Postgres, run twice** (`docker compose up -d postgres redis` first;
`docker run --rm --network anaf_data_net --user root -e DB_CONNECTION=pgsql -e DB_HOST=postgres
-e DB_PORT=5432 -e DB_DATABASE=kern -e DB_USERNAME=kern -e DB_PASSWORD=... kern-php-test:local
vendor/bin/pest`, per `docs/handoff/015`/`016`):
```
Run 1: Tests: 336 warnings, 286 passed (1718 assertions)
Run 2: Tests: 336 warnings, 286 passed (1718 assertions)   — identical, no state leak
```
**Honesty note:** an initial Postgres attempt (before these two logged runs) hit one
`SQLSTATE[40P01]: Deadlock detected` inside `migrate:fresh`'s `DROP TABLE ... CASCADE`, surfaced
through `Tests\Feature\Domain\Documents\ClamdVirusScannerLiveTest` (a test unrelated to this task's
changes — it requires a live `clamav` daemon that was not running in that attempt). Retried
immediately with no code changes and it passed clean twice in a row (1718 assertions both times,
identical). Recorded here rather than silently discarded, per `docs/AGENTS.md` §2's "never claim a
test passes without running it" — this reads as transient lock contention from a persistent,
long-running dev Postgres instance under `migrate:fresh`'s `DROP ... CASCADE`, not a defect in this
task's code (none of the touched files are in the Postgres/migration path at all), but it is
recorded honestly rather than omitted.

**Real end-to-end call against a live `python-api`** — genuinely executed, not simulated:
```
$ docker compose up -d --build python-api
Container anaf-python-api-1 Started ... healthy

# Real, valid PDF fixtures built with apps/processing's own pypdf-based test builders
# (tests/documents/pdf_builders.py: build_text_pdf/build_blank_pdf/build_encrypted_pdf) —
# read-only use of an existing test helper, no apps/processing file was modified.

$ docker run --rm --network anaf_processing_net \
    -e APP_KEY=base64:... -e DOCUMENTS_TEXT_EXTRACTION_URL=http://python-api:8000 \
    -e INTERNAL_SERVICE_AUTH_TOKEN=<the real shared secret from .env> \
    -v <scratch>:/verify:ro \
    kern-php-test:local php /verify/verify_gateway.php /verify/pdf_fixtures/text.pdf

=== clean text-layer PDF ===
OK: page_count=1 needs_ocr=false
  page 1: quality_score=1.0000 text_preview="Factura nr. 123 din data de 07.09.2026. Suma totala de plata"

$ ... /verify/pdf_fixtures/blank.pdf
=== blank/no-text-layer PDF (needs OCR) ===
OK: page_count=1 needs_ocr=true
  page 1: quality_score=0.0000 text_preview=""

$ ... /verify/pdf_fixtures/encrypted.pdf
=== encrypted PDF ===
REJECTED: reason=ENCRYPTED_PDF message="PDF is encrypted; rejected without attempting a password"

$ ... with INTERNAL_SERVICE_AUTH_TOKEN="" (missing)
UNAVAILABLE: The document text-extraction service auth token is not configured.
# (Http::fake-equivalent check confirmed no request was sent — Laravel fails closed itself.)

$ ... with INTERNAL_SERVICE_AUTH_TOKEN=<wrong value>
UNAVAILABLE: The document text-extraction service rejected this request as unauthenticated.
```
This exercised `Kern\Documents\Extraction\HttpTextExtractionGateway` — the real class, via a
Laravel-bootstrapped script resolving `TextExtractionGateway` from the container exactly as
`ProcessScannedDocument` does — against the real, running `python-api` container over the real
`processing_net`-equivalent Docker network, using real PDF bytes. Confirmed via
`docker compose logs python-api` that neither the token nor any document text (`grep -i
"token\|factura\|bearer"`) appeared in the service's logs. `python-api` was stopped after
verification (`docker compose stop python-api`); `postgres`/`redis` were already running from prior
session state and were left as they were.

## Interfaces exposed to other modules

- `Kern\Documents\Extraction\DataTransferObjects\UntrustedDocumentText` — `wrap()`, `join()`,
  `reveal()`, `equals()`. Any future consumer of extracted document text (a human-review UI, a
  future real `ExternalDocumentAiProvider`) should accept this type, not a bare `string`.
- `Kern\Documents\Extraction\DataTransferObjects\PageExtractionResult` — `pageNumber`,
  `qualityScoreDecimal`, `text`. Available on `NativeTextExtractionResult::$pages` for a future
  human-review UI that wants per-page quality data (`docs/handoff/024`'s open question #3) — not
  consumed by any code path in this task beyond being parsed and held.
- `Kern\Documents\Extraction\Exceptions\DocumentRejectedByExtractionException` — `reason():
  RejectionReason`. Never retry on this; it means the same document will fail the same way again.
- `Kern\Documents\Events\DocumentTextExtractionRejected` — broadcasts `document_id`, `status`
  (always `"REJECTED"`), `rejection_reason`, `progress` (100) on the same private
  `tax-subjects.{id}.documents` channel as every other Documents lifecycle event.
- `INTERNAL_SERVICE_AUTH_TOKEN` — the env var name is now a cross-service contract. Both
  `compose.yaml` services and `.env.example` must keep using this exact name; renaming it on either
  side without the other breaks authentication silently (both sides fail closed, so the failure
  mode is loud — `TextExtractionUnavailableException`/401 on the Laravel side, `503`-adjacent
  startup validation error on the Python side — not silent data loss, but still worth flagging).

## What is NOT done

- **Per-page `quality_signals` (the character-count/density/word-ratio breakdown) and
  `overall_decision`/`pages_needing_ocr` are still not consumed.** Only `page_count`, top-level
  `needs_ocr`, and each page's `page_number`/`quality_score`/`text` are mapped into DTOs. This
  matches the task's five named mismatches exactly (mismatch #2 is specifically about
  `quality_score`) and avoids adding fields nothing in this codebase yet reads
  (`docs/CODE-QUALITY.md` §2's "no speculative parameters ... added in case we need it later").
  `docs/handoff/024`'s open question #3 (whether a future human-review UI wants the fuller
  per-page breakdown) is still open.
- **The final Laravel<->processing internal-auth scheme is still a shared static secret**, per
  `docs/handoff/024`'s own "What is NOT done" — no mTLS, no signed JWT, no rotation mechanism. This
  task wired the existing stopgap through correctly on both sides; it did not change the scheme.
- **No rate limiting was added to the Laravel-side call itself** — unchanged from before this task.
- **The `DOCUMENTS_TEXT_EXTRACTION_TIMEOUT_SECONDS` (30s) vs. Python's `parse_timeout_seconds`
  (20s) reconciliation** noted as unverified-under-real-network-latency in `docs/handoff/024` was
  not re-verified here either — out of this task's five named mismatches.

## Open questions

1. Whoever eventually designs the permanent Laravel<->processing auth scheme
   (`docs/handoff/004`'s open question #4, restated in `docs/handoff/024`) should replace
   `documents.text_extraction.auth_token`/`INTERNAL_SERVICE_AUTH_TOKEN`, not layer a second
   mechanism next to it.
2. Whether a future document-review UI wants `PageExtractionResult`'s per-page data surfaced
   through an API endpoint, or the fuller `quality_signals` breakdown added on top of it — deferred,
   per `docs/handoff/024`'s open question #3.

## Sources consulted

- `docs/AGENTS.md`, `docs/CODE-QUALITY.md`, `docs/DEPENDENCY-POLICY.md` — full read per task brief;
  no new dependency was added anywhere in this task, so §4's justification requirement has nothing
  new to record.
- `docs/handoff/024-extraction-endpoint.md` (full read) — the authoritative, real contract this
  task reconciles Laravel against, including its own "Differences from handoff 022's assumed
  contract" section, which this task's five-point structure follows directly.
- `docs/handoff/022-document-pipeline.md` (full read) — the Laravel pipeline this task fixes,
  especially `HttpTextExtractionGateway`'s original (now-replaced) docblock and
  `ProcessScannedDocument`'s existing retry-on-unreachable / accept-no-consent-as-terminal
  behaviour, both of which this task extended rather than replaced.
- `docs/handoff/019-pdf-text-extraction.md` (full read) — `UntrustedDocumentText`'s Python-side
  contract and the "reveal exactly once, at an explicit boundary" rule this task's Laravel-side
  mirror follows.
- `docs/handoff/016-postgres-suite-stabilisation.md`, `docs/handoff/015-m1-integration-repairs.md`
  — the real-Postgres verification commands and the "a green sqlite run is not evidence" lesson
  this task's own Postgres runs (and the honestly-recorded transient deadlock) follow.
- `docs/handoff/008-ci-pipeline.md` — the `kern-php-test:local` test-image build convention used
  for every quality-gate command in this task.
- Source files read directly (not just their handoffs): `apps/api/packages/documents/src/FileSecurity/
  Exceptions/{FileSecurityException,FileTooLargeException}.php` and `FileSecurityValidator.php` (the
  existing rejection-reason pattern this task's `DocumentRejectedByExtractionException` follows),
  `apps/api/packages/documents/src/Enums/{DocumentStatus,RejectionReason}.php`,
  `apps/api/packages/documents/src/Events/{DocumentScanned,DocumentTextExtracted}.php` and their
  shared `Concerns/BroadcastsOnDocumentsChannel` trait, `apps/api/packages/documents/src/Models/
  Document.php`, `apps/api/packages/documents/src/ExternalAi/**`,
  `apps/processing/tests/documents/pdf_builders.py` (read-only use of `build_text_pdf`/
  `build_blank_pdf`/`build_encrypted_pdf` to generate real verification fixtures — no
  `apps/processing` file was modified), `apps/processing/src/processing/internal_auth.py` (to
  confirm the exact env var name/prefix `INTERNAL_SERVICE_AUTH_TOKEN` the Python side actually
  reads).
- Live, real command output — `docker build`, `docker compose up`, `docker run ... vendor/bin/
  {pint,phpstan,pest,deptrac}`, and the real end-to-end gateway calls against a live `python-api`
  — all pasted verbatim above, none asserted without running.
