# 024 — `POST /v1/documents/extract-text` HTTP endpoint

**Milestone:** M7   **Status:** complete
**Date:** 2026-09-08

## What was built

The HTTP surface `docs/handoff/022-document-pipeline.md`'s `HttpTextExtractionGateway` calls but
that never existed on the Python side (`docs/handoff/019-pdf-text-extraction.md` deliberately left
`extract_document()` as a plain function with no route). This task closes that gap: an
authenticated FastAPI endpoint in `apps/processing` that wraps `extract_document()` with HTTP
concerns only — auth, multipart handling, response serialization, and mapping every
`DocumentExtractionError` subclass to a specific, structured error — without duplicating any
security control or quality threshold from `processing.documents`.

**This task also found that handoff 022's assumed contract does not match what
`processing.documents` actually produces, and does not match this task's own auth/error
requirements.** Per the task brief, those mismatches are implemented correctly here and stated
explicitly below, not silently patched to look like the original assumption. **The Laravel side
(`apps/api/app/Domain/Documents/Extraction/HttpTextExtractionGateway.php`) will need changes to
match — see "Differences from `docs/handoff/022`'s assumed contract."**

Entry point: `POST /v1/documents/extract-text` (`src/processing/documents/http.py`).

## Files created / changed

- `src/processing/documents/http.py` — the route: reads the uploaded file, calls
  `extract_document()` in a threadpool (it is a blocking, subprocess-spawning call — never awaited
  directly in an `async def` body), maps each `DocumentExtractionError` subclass to a specific
  `(status_code, error_code)` pair, logs the decision and page count only.
- `src/processing/documents/http_schemas.py` — `ExtractTextResponse`, `PagePayload`,
  `QualitySignalsPayload`, `UntrustedTextPayload`, `ErrorResponse`: the wire (Pydantic) shapes,
  kept separate from `processing.documents.schemas`'s domain models so the domain package has no
  HTTP/serialization concern.
- `src/processing/internal_auth.py` — `InternalServiceAuthSettings`
  (`INTERNAL_SERVICE_AUTH_TOKEN`), `require_internal_auth` — a shared-secret bearer-token FastAPI
  dependency. Not endpoint-specific: any future internal-only route in this service can reuse it.
- `src/processing/error_handling.py` — `register_error_handlers`: makes every `HTTPException`
  raised with a dict `detail` (which is what `require_internal_auth` raises) render as that dict
  directly, so auth errors and extraction errors share one flat `{"error", "message"}` shape
  instead of auth errors alone being wrapped in FastAPI's default `{"detail": {...}}`.
- `src/processing/main.py` — registers the error handlers and includes the new router.
- `src/processing/logging_config.py` — added `page_count`, `overall_decision`, `error_code` to
  `_ALLOWED_EXTRA_FIELDS`. This allowlist is the actual no-PII-in-logs mechanism (unlisted fields
  are dropped, not serialized) — extending it was necessary and deliberate, not a loosening: no
  field capable of carrying document text or PII was added.
- `pyproject.toml`, `uv.lock` — added `python-multipart` (see "Dependencies added").
- `tests/documents/test_http.py` — 12 new endpoint tests (see "How to verify").

No file outside `apps/processing/**` and this handoff note was touched, per the task's scope.

## Key decisions

### 1. Authentication: a shared-secret bearer token, not mTLS/JWT — and it is a new requirement, not something `docs/handoff/022` already sends

`docs/handoff/022-document-pipeline.md`'s `HttpTextExtractionGateway` explicitly sends **no**
authentication today — its own docblock says the internal-auth mechanism "has not been decided
anywhere in this codebase yet." `BLUEPRINT.md` §13.4 specifies mTLS/signed-JWT only for the
`spv-browser` contract, not this one. This task's brief nonetheless requires "must not be callable
unauthenticated," and building actual mTLS or a JWT issuer/audience is infrastructure this task has
no authority to invent (`docs/AGENTS.md` rule 1 — inventing an unverified scheme is exactly the
kind of fabrication that rule targets, even for an internal contract).

Resolution: a shared secret, `INTERNAL_SERVICE_AUTH_TOKEN`, checked via `secrets.compare_digest`
against `Authorization: Bearer <token>`. Missing header, malformed header, wrong token, and the
setting being unset entirely (`ValidationError` on `InternalServiceAuthSettings()`) all return the
same `401 {"error": "unauthorized", "message": "..."}` shape — the setting being unset **fails
closed**, never falls back to accepting the request. This is the minimum real control that
satisfies the requirement without guessing at infrastructure. **The final internal-auth scheme for
every Laravel↔processing call is still an open decision** (`docs/handoff/004`'s open question #4)
— whoever makes that decision should replace this token check, not layer another mechanism next to
it.

### 2. The response is the actual shape `extract_document()` produces, not a copy of handoff 022's guess

See "Differences from `docs/handoff/022`'s assumed contract" below for the full diff. In short: the
real `DocumentExtractionResult` has **no single document-level `quality_score`** (quality is
computed per page only) and no single top-level `needs_ocr` field as the primary signal — the real
primary signal is `overall_decision` (`native_text_sufficient` / `ocr_required`) plus
`pages_needing_ocr`. This task's brief explicitly asked for "per-page quality signals" to be
carried over the wire, which handoff 022's flat guess did not include at all. Rather than force the
real result into the wrong shape, the response now carries both: `needs_ocr` (a derived
document-level boolean, kept because handoff 022's Laravel client already parses it and it is a
correct derivation of `overall_decision`) **and** `overall_decision`/`pages_needing_ocr`/
per-page `quality_score`/`quality_signals`, which are new fields Laravel's parser does not
recognise yet.

### 3. `UntrustedDocumentText` is marked structurally on the wire, not just documented

`docs/handoff/019-pdf-text-extraction.md` deliberately left `PageExtractionResult.text` without a
JSON encoding, "forcing whoever wires up that endpoint to make an explicit decision about
whether/how extracted text crosses that boundary." The decision made here: `text` is not a bare
JSON string. It is `{"kind": "untrusted_document_text", "content": "<text>"}` — a structural,
machine-checkable marker a receiving system cannot silently lose (unlike a comment or a naming
convention), matching the spirit of `UntrustedDocumentText.__repr__`'s redaction: the type's whole
point is that raw text should never look like an ordinary trusted string, and a bare `"text":
"..."` JSON field gives a receiving system no such signal. `.reveal()` is called exactly once, at
this serialization boundary, which is the explicit, grep-able, audited call site
`docs/handoff/019` asked for. **This is a shape change** from handoff 022's assumed flat
`"text": string` — see the diff below.

### 4. Every `DocumentExtractionError` subclass gets a specific, stable error code — handoff 022's Laravel client currently cannot use this distinction

`docs/handoff/022-document-pipeline.md`'s `HttpTextExtractionGateway::parse()` currently treats
**any** non-2xx response as `TextExtractionUnavailableException::unexpectedResponse()` — i.e., it
cannot yet tell "this document is bad" from "the service is down," even though its own error class
comment says a caller should be able to react differently. This task's brief explicitly requires
that distinction, so the endpoint returns a specific `(status_code, error_code)` pair per rejection
reason:

| `DocumentExtractionError` subclass | HTTP status | `error` code |
|---|---|---|
| `FileTooLargeError` | 413 | `file_too_large` |
| `InvalidMagicBytesError` | 422 | `invalid_magic_bytes` |
| `EncryptedPdfRejectedError` | 422 | `encrypted_pdf` |
| `TooManyPagesError` | 422 | `too_many_pages` |
| `EmptyDocumentError` | 422 | `empty_document` |
| `MalformedPdfError` | 422 | `malformed_pdf` |
| `ExtractionTimeoutError` | 422 | `extraction_timeout` |
| (any other, defensive) | 422 | `extraction_failed` |

**"Decompression bomb detected" is not a distinct code.** `processing.documents` has no separate
"bomb" exception — a bomb is caught by one of two existing, independent defences
(`docs/handoff/019`'s wall-clock timeout or its worker memory ceiling), and surfaces as whichever
one catches it first: `extraction_timeout` if the wall-clock budget expires, or `malformed_pdf` if
the worker's own `MemoryError` fires first (see `_worker.py`'s `except MemoryError` branch) or if
the worker is killed externally before sending a result (the `EOFError` → `MalformedPdfError` path
in `timeout.py`). This task's required bomb test exercises the wall-clock path, matching exactly
the fixture and timing `docs/handoff/019` measured (`build_decompression_bomb_pdf`, a tightened
`DOC_EXTRACTION_PARSE_TIMEOUT_SECONDS=0.5` for test speed) and asserts `extraction_timeout`.
Inventing a distinct `bomb_detected` code would misrepresent the underlying module's real behaviour
— it was not implemented, and this is a deliberate scope boundary, not an oversight.

A connection failure, a 5xx, or a response that fails to parse remain **entirely a Laravel-side
concern** (`TextExtractionUnavailableException`) — this endpoint has no way to distinguish "the
service failed" from itself, by construction.

### 5. `extract_document()` runs in FastAPI's threadpool, not inline in an `async def` body

`extract_document()` is a synchronous, blocking call that itself spawns and waits on a subprocess
(up to `parse_timeout_seconds`, default 20s). Calling it directly inside `async def extract_text`
would block the single event loop thread for the full duration, starving every other concurrent
request this service handles (including `/health`). `starlette.concurrency.run_in_threadpool` runs
it on a worker thread instead — the same mechanism FastAPI uses automatically for a `def` (sync)
path operation function, made explicit here because the route itself does other `async` work
(`await file.read()`) around it.

### 6. New dependency: `python-multipart`

`docs/handoff/022`'s assumed contract is a multipart body (Laravel's `Http::attach()` produces
`multipart/form-data`), and FastAPI/Starlette require `python-multipart` installed to parse
`UploadFile`/`File(...)` parameters — it is not bundled by `fastapi` itself. Checked 2026-09-08 via
PyPI: latest `0.0.32`, released 2026-06-04, Apache-2.0, maintained by the same Kludex/Starlette
maintainer group already trusted for `httpx2`/`starlette` in this project (`docs/handoff/004`).
Pinned `>=0.0.32,<0.0.33` (the package has never left the `0.0.x` line, so a tight pin is the
correct reading of `docs/DEPENDENCY-POLICY.md`'s "tight constraint on a verified minor" for this
versioning scheme). No alternative avoids this dependency without abandoning multipart parsing
(and thus the contract handoff 022 already committed to on the Laravel side).

## Differences from `docs/handoff/022`'s assumed contract — Laravel-side reconciliation needed

`docs/handoff/022-document-pipeline.md`'s `HttpTextExtractionGateway` assumed, unverified:

```
POST {base_url}/v1/documents/extract-text, multipart body, field `file`.
200: {"page_count": int, "needs_ocr": bool, "quality_score": "<decimal string>",
      "pages": [{"page_number": int, "text": string}, ...]}
```

The actual, now-real contract:

```
POST {base_url}/v1/documents/extract-text
Authorization: Bearer <INTERNAL_SERVICE_AUTH_TOKEN>   <-- NEW, was absent
multipart body, field `file` (unchanged)

200:
{
  "page_count": int,
  "needs_ocr": bool,                          <-- unchanged meaning: overall_decision == ocr_required
  "overall_decision": "native_text_sufficient" | "ocr_required",   <-- NEW
  "pages_needing_ocr": [int, ...],            <-- NEW
  "pages": [
    {
      "page_number": int,
      "needs_ocr": bool,                      <-- NEW (per page; handoff 022 had no per-page field)
      "quality_score": "<decimal string, 4dp>",   <-- MOVED: per-page now, not document-level
      "quality_signals": {                    <-- NEW
        "non_whitespace_char_count": int, "total_char_count": int,
        "page_area_sq_inches": float, "char_density_per_sq_inch": float,
        "bad_char_ratio": float, "recognizable_word_ratio": float,
        "is_whitespace_only": bool
      },
      "text": {"kind": "untrusted_document_text", "content": string}   <-- CHANGED SHAPE (was bare string)
    }
  ]
}

Errors (NEW — handoff 022's client currently cannot use these, see decision #4):
401 {"error": "unauthorized", "message": "..."}                    (missing/invalid/unconfigured auth)
413 {"error": "file_too_large", "message": "..."}
422 {"error": "invalid_magic_bytes" | "encrypted_pdf" | "too_many_pages" | "empty_document"
             | "malformed_pdf" | "extraction_timeout" | "extraction_failed", "message": "..."}
```

**What Laravel's `HttpTextExtractionGateway`/`NativeTextExtractionResult` needs to change to
match:**

1. Send `Authorization: Bearer <token>` — the token must come from Laravel config (e.g. a new
   `documents.text_extraction.auth_token` key backed by an env var), sharing the same secret value
   as this service's `INTERNAL_SERVICE_AUTH_TOKEN`. No such config key exists yet on the Laravel
   side (confirmed by reading `apps/api/config/documents.php`).
2. Read `page['text']['content']` instead of `page['text']` directly (`parse()`'s current
   `is_string($page['text'])` check would reject every real response from this endpoint as
   malformed).
3. Decide whether to also read `document_extraction_result`'s new `overall_decision`,
   `pages_needing_ocr`, and per-page `quality_signals`/`needs_ocr` fields, or continue relying only
   on the top-level `needs_ocr` boolean (still present and correct) and per-page `quality_score`
   (now nested under each page rather than a single top-level value — `parse()`'s current
   `isset($payload['quality_score'])` check on the top level will fail against this endpoint's
   real responses, since there is no such top-level field).
4. Add specific handling for `401`/`413` and the closed set of `422` `error` codes if Laravel wants
   to surface "this document is bad" (e.g. `RejectionReason::INFECTED`-style user-facing reasons)
   rather than treating every non-2xx as `TextExtractionUnavailableException`.

This task did not make these Laravel-side changes — `apps/api/**` is explicitly out of scope for
this task, and another agent owns it per the task brief.

## How to verify

All commands run from `apps/processing/`, `uv` on `PATH`, Python 3.14.7.

```
$ uv lock --check
Resolved 59 packages in 0.99ms

$ uv sync --frozen
Checked 57 packages in 0.52ms

$ uv run ruff format --check .
37 files already formatted

$ uv run ruff check .
All checks passed!

$ uv run pyright
0 errors, 0 warnings, 0 informations

$ uv run pytest -q
........................................................................ [ 83%]
..............                                                           [100%]
86 passed in 7.56s

$ uv run pip-audit
No known vulnerabilities found
Name       Skip Reason
---------- -------------------------------------------------------------------------
processing Dependency not found on PyPI and could not be audited: processing (0.1.0)
```

Ran `pytest -q` twice in direct succession (7.45s, then 7.56s) — identical `86 passed`, no state
leak between the auth-token env-var manipulation in `test_http.py` and other test files (each test
that mutates `INTERNAL_SERVICE_AUTH_TOKEN`/`DOC_EXTRACTION_*` uses `pytest`'s `monkeypatch`, which
reverts automatically after the test).

Test count: 86, up from the 74 recorded in `docs/handoff/019` — 12 new tests, all in
`apps/processing/tests/documents/test_http.py`, covering: a clean text-layer PDF (asserts the full
response shape field-by-field against the contract above), an image-only PDF needing OCR, an
encrypted PDF, an oversized file, a magic-byte mismatch, a caller-declared content-type that
disagrees with the real bytes, a decompression bomb, missing/malformed/wrong/unconfigured
authentication (four separate cases), and a malformed request body (no `file` part).

Manually confirmed no PII/document text reaches logs: ran a real request against a text-bearing PDF
containing the string "Factura" and grepped stderr output for the document's actual sentences —
only `page_count` and `overall_decision` appear in the `document_extraction_completed` log line,
never the extracted text.

## Interfaces exposed to other modules

- `POST /v1/documents/extract-text` — see the exact contract above. `src/processing/documents/http.py`.
- `processing.internal_auth.{InternalServiceAuthSettings, require_internal_auth}` — reusable by
  any future internal-only route in this service.
- `processing.error_handling.register_error_handlers` — call once on the `FastAPI` app; already
  wired in `main.py`.
- `processing.documents.http_schemas.{ExtractTextResponse, PagePayload, QualitySignalsPayload,
  UntrustedTextPayload, ErrorResponse}` — the wire DTOs, re-usable if another endpoint ever needs
  the same shapes.

## What is NOT done

- **No changes to `apps/api/**`.** Out of scope per the task brief; see "Differences from
  `docs/handoff/022`'s assumed contract" for exactly what the Laravel-owning agent needs to change.
- **No mTLS or signed-JWT.** The shared-secret bearer token (decision #1) is a real, tested control
  that satisfies "must not be callable unauthenticated," but it is not the scheme `BLUEPRINT.md` §13.4
  describes for `spv-browser`. The final internal-auth design for every Laravel↔processing call is
  still open (`docs/handoff/004`'s open question #4).
- **No token rotation mechanism.** `INTERNAL_SERVICE_AUTH_TOKEN` is a single static secret read
  from the environment; rotating it requires a coordinated redeploy of both services. Acceptable
  for now, not a long-term answer.
- **No rate limiting on this endpoint.** `docs/AGENTS.md`/`BLUEPRINT.md` §18 call for rate limiting on
  business APIs; this is an internal-only endpoint behind network isolation
  (`processing_net`) plus the new auth token, and Laravel-side rate limiting on the upload path
  already exists (`apps/api/config/documents.php`'s `rate_limits.upload_per_subject`) — but nothing
  bounds concurrent/repeated calls to this endpoint itself if the shared secret leaked.
- **No streaming upload.** `await file.read()` loads the entire upload into memory (Starlette
  spools past 1MB to a temp file internally, but this code reads it all into a `bytes` object
  before validation) before `extract_document`'s own `FileTooLargeError` check runs. This matches
  the task's "reuse the existing extraction module, do not duplicate its thresholds in the HTTP
  layer" instruction — enforcing a separate, earlier size cap in the HTTP layer would itself be
  duplicating `max_file_size_bytes`. A very large but not htaccess-limited upload can still consume
  memory/disk before being rejected; not addressed here.
- **`DOCUMENTS_TEXT_EXTRACTION_TIMEOUT_SECONDS` (Laravel, default 30s) vs. `parse_timeout_seconds`
  (Python, default 20s) were not reconciled.** Both existed before this task (Laravel's in
  `apps/api/config/documents.php`, Python's in `docs/handoff/019`); this task did not change either
  default. 30s > 20s means Laravel's HTTP client timeout should not fire before Python's own
  worker-kill timeout does, which is the correct order, but this was not verified against real
  network latency — only noted as consistent on paper.

## Open questions

1. **The final Laravel↔processing internal-auth scheme** (mTLS vs. signed JWT vs. continuing with
   a shared secret) — `docs/handoff/004`'s open question #4, still unresolved. This task's shared
   bearer token is a working stopgap, not a recommendation for the permanent design.
2. **Where the shared secret value itself is provisioned/rotated** (a `.env` value today, matching
   this project's `docker-compose` dev conventions) needs a real secrets-manager story before
   production, per `BLUEPRINT.md` §4.10.
3. Whoever reconciles `apps/api`'s `HttpTextExtractionGateway` against this handoff's "Differences"
   section should also decide whether to keep sending `needs_ocr` as the only field Laravel reads,
   or start consuming `overall_decision`/`pages_needing_ocr`/per-page `quality_signals` — the
   `document_extractions`/`tax_facts` schema (`BLUEPRINT.md` §6.6) may want the richer per-page data
   for human review UI, not just the document-level boolean.
4. `docs/STATUS.md` was explicitly out of scope for this task per the task brief (scope restricted
   to `apps/processing/**` and this handoff file) — whoever owns it next should add this handoff to
   the M7 row and close out the "Python text-extraction endpoint" half of handoff 022's blocked
   items.

## Sources consulted

- `docs/AGENTS.md` (full read — rule 1, especially "never invent an endpoint/contract nobody has
  verified," applied here to the internal-auth decision; §2 never fabricate, applied to the
  "no distinct bomb error code" decision; §9 fiscal-work rules, confirmed not applicable — no
  fiscal value is produced by this endpoint).
- `docs/CODE-QUALITY.md`, `docs/DEPENDENCY-POLICY.md` (full read per task brief; one new dependency,
  `python-multipart`, justified above with version/license/maintenance per §4).
- `docs/handoff/022-document-pipeline.md` (full read) and its referenced source files,
  `apps/api/app/Domain/Documents/Extraction/{HttpTextExtractionGateway,TextExtractionGateway}.php`,
  `.../DataTransferObjects/NativeTextExtractionResult.php`,
  `.../Exceptions/TextExtractionUnavailableException.php`, `apps/api/config/documents.php` — read
  directly (not just the handoff's prose) to confirm the exact assumed request/response shape and
  that no internal-auth header is sent today.
- `docs/handoff/019-pdf-text-extraction.md` (full read) and the `processing.documents` source it
  describes (`schemas.py`, `errors.py`, `extraction.py`, `_worker.py`, `timeout.py`, `security.py`,
  `config.py`) — read directly to confirm the real `DocumentExtractionResult` shape, the exact
  exception hierarchy and which worker-failure paths map to which exception, and
  `UntrustedDocumentText`'s documented contract for crossing a serialization boundary.
- `docs/handoff/004-python-browser-services.md` (full read) — `apps/processing`'s existing
  conventions this task followed: JSON logging with a fixed field allowlist, correlation-ID
  middleware, `pydantic-settings`-based config with an `env_prefix`, no provider SDK dependencies.
- PyPI JSON API (`pypi.org/pypi/python-multipart/json`), checked 2026-09-08, for version, license,
  release history.
- `apps/processing/tests/documents/pdf_builders.py`, `test_extraction.py`, `test_timeout.py` — read
  directly to reuse the existing hand-built PDF fixtures (`build_text_pdf`, `build_blank_pdf`,
  `build_encrypted_pdf`, `build_decompression_bomb_pdf`) rather than constructing new ones, and to
  confirm the exact timing/settings (`DOC_EXTRACTION_PARSE_TIMEOUT_SECONDS=0.5` against
  `repeats=400_000`) already proven to deterministically trigger `ExtractionTimeoutError`.
