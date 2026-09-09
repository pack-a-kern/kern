# 019 — PDF native text-layer extraction and the OCR routing decision

**Milestone:** M3/M7 (document text-extraction service, built ahead of the milestone as
requested)   **Status:** complete
**Date:** 2026-09-08

## What was built

`apps/processing/src/processing/documents/` — a self-contained package implementing ADR 0005's
local-extraction-first step and `BLUEPRINT.md` §14.2/§14.3: given raw PDF bytes, it enforces file
security controls, extracts each page's native text inside an isolated, timeout-bounded
subprocess, scores extraction quality against four measurable signals, and returns a structured
per-page and overall decision on whether OCR is needed. **It never calls OCR or any external
provider** — that call is still gated behind consent/DPA/audit per ADR 0005 and is Milestone 7's
job. This is the seam that decision plugs into, not the call itself.

Entry point: `processing.documents.extract_document(pdf_bytes, *, declared_filename=None,
settings=None) -> DocumentExtractionResult`.

No document database tables, no upload endpoint, and no OCR/LLM call were added — all explicitly
out of scope per the task brief.

## Files created / changed

- `apps/processing/src/processing/documents/__init__.py` — public re-exports.
- `apps/processing/src/processing/documents/config.py` — `DocumentExtractionSettings`
  (`DOC_EXTRACTION_*` env vars), every threshold documented inline with its default and reasoning.
- `apps/processing/src/processing/documents/errors.py` — one exception per rejection reason
  (`FileTooLargeError`, `InvalidMagicBytesError`, `EncryptedPdfRejectedError`, `TooManyPagesError`,
  `EmptyDocumentError`, `MalformedPdfError`, `ExtractionTimeoutError`), all subclassing
  `DocumentExtractionError`.
- `apps/processing/src/processing/documents/schemas.py` — `UntrustedDocumentText` (the
  prompt-injection boundary, see below), `PageQualitySignals`, `PageExtractionResult`,
  `OcrDecision` (a `StrEnum`), `DocumentExtractionResult`.
- `apps/processing/src/processing/documents/security.py` — `validate_file_size`,
  `validate_magic_bytes`: both run on raw bytes before any PDF parsing, neither trusts a
  caller-declared filename or MIME type.
- `apps/processing/src/processing/documents/_worker.py` — `extract_worker`: the function that
  runs inside the isolated subprocess. Applies `resource.RLIMIT_AS`, opens the PDF, checks
  encryption and page count, extracts every page's text, and reports exactly one
  `(status, payload)` message back over a pipe. Never raises out of the process — every failure
  path is a message, because nothing is listening once the process exits.
- `apps/processing/src/processing/documents/timeout.py` — `parse_and_extract_pages`: spawns
  `extract_worker` in a fresh `multiprocessing` subprocess (`spawn` context), waits up to
  `parse_timeout_seconds`, and kills the process (`terminate()` → `kill()`) if it does not
  respond in time. Converts every worker outcome, including a process that dies without sending
  anything (`EOFError`), into a typed `DocumentExtractionError`.
- `apps/processing/src/processing/documents/quality.py` — `compute_quality_signals`,
  `compute_quality_score`, `decide_needs_ocr`: pure functions, no I/O.
- `apps/processing/src/processing/documents/extraction.py` — `extract_document`: the orchestrator
  wiring the above together.
- `apps/processing/tests/documents/pdf_builders.py` — hand-built PDF fixtures (not test files
  themselves): clean text, blank/no-text-layer, three-page mixed, RC4-encrypted, a decompression
  bomb, garbled/control-character text, sparse-but-real text, numeric-only text.
- `apps/processing/tests/documents/test_security.py`,`test_quality.py`, `test_timeout.py`,
  `test_extraction.py`, `test_untrusted_text.py`, `test_extraction_config.py` — 53 new tests (74
  total in the project, up from 21).
- `apps/processing/pyproject.toml` — added `S107` (hardcoded-password-default) to the existing
  `tests/*` per-file-ignore list, alongside the pre-existing `S101`/`S105`/`S106`. Needed because
  `build_encrypted_pdf(password: str = "s3cret")` is a legitimate test fixture default, not a real
  credential — same category as the existing ignores. No new dependency was added anywhere in
  this task; only the standard library (`multiprocessing`, `resource`, `contextlib`,
  `unicodedata`, `re`, `io`) and the already-present `pypdf` are used.

## Key decisions

### 1. Every PDF is parsed and extracted inside a fresh, disposable, `spawn`ed subprocess

This is the single mechanism behind file-security controls #3 ("a parser timeout") and #4
("decompression-bomb protection") in `BLUEPRINT.md` §14.2, and it is deliberately one mechanism, not
two. A pathological PDF can blow up in ways that are not simply "the file is huge": I built and
measured a real fixture (`build_decompression_bomb_pdf`) that is **~20 KB on disk** (a `/Contents`
stream compressed with `DecodedStreamObject.flate_encode()`) but decompresses into 400,000
`BT /F1 12 Tf (A) Tj ET` operators. `pypdf.PageObject.extract_text()` tokenizes that content
stream in pure Python; on this project's dev host that fixture measured:

| Repeats | Decompressed size | `extract_text()` wall time |
|---|---|---|
| 100,000 | ~2.3 MB | 2.4 s |
| 400,000 | ~9.2 MB | ~11.3 s |

A page-count or file-size cap alone does not catch this — both stay small. Only a wall-clock
budget with a hard process kill reliably bounds worst-case CPU/memory regardless of which internal
mechanism (nested compression, pathological operator counts, malformed xref tables forcing a
brute-force repair scan, ...) a hostile file exploits. **The entire PDF lifecycle — opening,
encryption check, page-count check, and every page's `extract_text()` — runs inside the guarded
subprocess**, not just the text-extraction call, specifically because a malformed xref table can
already be slow during `PdfReader()` construction itself; putting only `extract_text()` behind the
guard would have left that earlier phase unprotected.

Two independent layers, both real and both tested:

- **Wall-clock timeout** (`parse_timeout_seconds`, default 20 s): `parent_conn.poll(timeout)`
  then `process.terminate()` → `process.kill()` if still alive. Tested end-to-end against the real
  400,000-repeat bomb with a 0.5 s timeout — the call raises `ExtractionTimeoutError` and returns
  control to the caller in well under a second, never waiting for the ~11 s natural completion.
- **Memory ceiling** (`max_worker_memory_bytes`, default 512 MiB): `resource.setrlimit(RLIMIT_AS,
  ...)` applied at the top of `extract_worker`, before any parsing. Measured directly against the
  same bomb fixture: at a deliberately tight 15 MB ceiling, zlib's own decompression call fails
  fast (`MemoryError: Unable to allocate output buffer`) in ~0.27 s — faster than the timeout path,
  and a second, independent line of defence against the same class of attack. Verified this does
  **not** break normal operation: even a 5 MB ceiling was sufficient for the full pipeline
  (interpreter startup + `import pypdf` + parsing a real small PDF) in testing, because the
  interpreter/import baseline is committed to virtual memory before `extract_worker`'s
  `setrlimit` call runs — the ceiling only bounds *additional* growth from that point on, which
  is exactly what a legitimate small document needs and a bomb's decompression does not have.

`multiprocessing.get_context("spawn")`, not `fork`: this process is an ASGI service that may hold
open network connections/threads, which must never be duplicated into a child (the classic
`fork()`-after-threads hazard). Confirmed empirically that `spawn`'s bootstrap inherits the
parent's `sys.path`, so this works correctly under pytest's rootless test-collection import mode
without special handling.

A process-per-document design also gives free isolation matching the philosophy `BLUEPRINT.md`
§3.4/§13.2 already applies to SPV browser sessions: nothing survives between documents, and a
crash or runaway allocation in the PDF parser cannot touch the calling process's memory or state.

**Honest cost**: `spawn` overhead measured at ~0.13 s per document for a real small PDF (interpreter
startup + `import pypdf`). This is a real latency cost per document. It is acceptable here because
this service is the `python-worker` queue consumer (Horizon-fed background processing per
`BLUEPRINT.md` §3.1/§3.2), not a synchronous request path — nothing in this codebase currently calls
`extract_document` from a request/response cycle.

A subtlety fixed during testing: if the worker process dies without ever sending a result (killed
by something outside this code's control — e.g. a container's cgroup OOM-killer under `BLUEPRINT.md`
§3.4's memory limits, independent of this module's own `RLIMIT_AS`), `Connection.recv()` raises a
raw `EOFError`. `_receive_within_timeout` now catches that and raises `MalformedPdfError` instead,
so no `DocumentExtractionError` caller ever sees a bare `EOFError`. Tested directly with a
test-only worker function that closes its pipe without sending.

### 2. Encrypted PDFs are rejected by checking `reader.is_encrypted`, never by calling `.decrypt()`

`PdfReader.is_encrypted` is a cheap check on the document's encryption dictionary; accessing
`.pages` on an encrypted, non-decrypted reader raises `FileNotDecryptedError` — confirmed by
direct testing. The worker checks `is_encrypted` and returns immediately, never attempting a
password (not even the empty string). `BLUEPRINT.md` §14.2's "unless user provides explicit password
via ephemeral flow" clause is **not implemented** — that ephemeral flow does not exist yet
(Milestone 7). Every encrypted PDF is rejected outright today; this is a scope decision, not an
oversight — see "What is NOT done."

### 3. Magic-byte validation is a bounded scan for `%PDF-`, not an offset-0 check

ISO 32000-2 §7.5.2 permits the header to follow leading bytes some generators prepend; real PDF
readers conventionally scan a bounded window rather than requiring the signature at byte 0.
`magic_byte_scan_window` (default 1024 bytes) makes this an explicit, configurable window rather
than a hard-coded offset. `declared_filename` is accepted by `extract_document` purely for
logging/error-message context — tested directly (`test_declared_filename_never_influences_acceptance`,
`test_rejects_content_whose_real_type_is_a_png_despite_a_pdf_filename`) to prove it never
influences the accept/reject decision.

### 4. The four extraction-quality signals, and why these specific thresholds

`BLUEPRINT.md` §14.3 requires deciding, per page, whether native text is "usable" — the whole point,
since this decision is what determines whether a document carrying a user's CNP/IBAN ever leaves
the machine. Four independent, measurable signals, each with a `DocumentExtractionSettings` field
(never a hard-coded constant per `docs/CODE-QUALITY.md` §4):

| Signal | Field | Default | Why this value |
|---|---|---|---|
| Absolute non-whitespace character floor | `min_non_whitespace_chars` | 20 | Below this, a page almost certainly has no usable text layer, independent of page size. Also guards the density signal against an unusually small page making a handful of characters look artificially dense. |
| Character density per sq. inch | `target_char_density_per_sq_inch` | 0.3 | The density score saturates to 1.0 at this value. Chosen deliberately close to the *implied* density of the absolute floor on a Letter/A4 page (20 chars / ~93.5 sq in ≈ 0.21/sq in), with modest headroom — **not** set to normal body-text density (~12-36 chars/sq in), because that would misroute a legitimately sparse page (a one-line invoice, a short cover page) into OCR. Verified numerically: a 25-character single line on a Letter page scores 0.749 overall (comfortably above the 0.5 gate) with this default; it scored 0.089 (well below gate) with an earlier, higher draft default of 3.0 — that earlier value was corrected specifically because it broke this exact case. |
| Replacement/control character ratio | `max_bad_char_ratio` | 0.05 | `docs/adr/0008-permissive-pdf-extraction.md` documents `pypdf`'s known weak spot on non-standard glyph-to-Unicode mappings honestly. A well-encoded PDF should be near 0% bad characters; ligature substitutions might contribute a handful. 5% is set as the point past which the cleanliness score is fully zeroed (the score scales linearly between 0% and this value). |
| Recognizable-word ratio | `min_recognizable_word_ratio` | 0.35 | Set deliberately low: fiscal documents (invoices, statements, IBANs, dates, amounts) are expected to carry a heavy proportion of numeric/code tokens that a "looks like a word" regex will never match. A realistic invoice-shaped text sample (words + amounts + dates) measured at a 0.654 word ratio in testing — comfortably above this floor. |
| Combined gate | `min_quality_score` | 0.5 | The three non-trivial component scores (density, cleanliness, word-ratio) are **multiplied**, not averaged, so a page failing badly on any one dimension cannot be masked by the other two scoring perfectly (e.g. 1.0 × 1.0 × 0.1 = 0.1, correctly still flagged). |

All five values are engineering defaults calibrated against hand-built fixtures — **not** values
from an official source, and none need a `source_section_id` (this is extraction-quality
tuning, not fiscal logic). Recalibrate once real document volume exists.

**Documented, accepted limitations** (found and confirmed by testing, not assumed):

- A page containing only numbers/amounts (a pure totals table, for instance) has a
  recognizable-word ratio of exactly 0.0, drives the combined score to 0.0, and is routed to OCR
  even though the extraction may be entirely correct. Verified with `build_numeric_only_pdf` — 971
  characters extracted correctly, still routed to OCR. This is the honest cost of a purely
  text-shape heuristic; there is no way to distinguish "correct numeric extraction" from
  "gibberish that happens to be short numeric-looking tokens" without external context.
- An image-heavy page carrying a small, genuine text caption is **not** caught by the density
  signal (the caption's own text is captured correctly and, being real text, is not obviously
  distinguishable from "a legitimately sparse page" using text-layer signals alone). Catching this
  would require visual/rendering analysis, which is out of scope for a text-only signal.
- Both limitations bias toward *not* sending extra documents to OCR rather than the reverse. Given
  ADR 0005's framing — OCR is the consent-gated, higher-sensitivity, human-reviewed path — the
  asymmetric cost of "sent something to OCR that didn't strictly need it" is much lower than "a
  page with real content got silently treated as text-sufficient when it needed OCR." The
  numeric-only-page limitation trades in the *opposite*, safer direction (over-routing to OCR); the
  image-caption limitation is the one genuine under-routing gap, and it is bounded in severity
  (the caption text itself is still captured correctly — only the image's own content is missed,
  the same as it would be with no text-layer analysis at all).

### 5. `UntrustedDocumentText` — the prompt-injection contract for Milestone 7 (`BLUEPRINT.md` §14.5)

Document text is untrusted DATA. No model is called by this codebase yet, so this task built the
*structure* that keeps that true once one is: `UntrustedDocumentText` (in `schemas.py`) wraps
every extracted string. It is **deliberately not a `str` subclass** and has no `__str__`/
`__format__` passthrough — `f"{wrapped}"` and `str(wrapped)` both render the redacted `repr()`
(`UntrustedDocumentText(<N chars, redacted>)`), never the document content. Getting the raw text
requires calling `.reveal()` explicitly — a single, grep-able name so a future reviewer can find
every place raw document content leaves this type. Tested directly (`test_untrusted_text.py`):
`repr()`/`str()`/f-string redaction, `.reveal()` correctness, equality/hash semantics (equal only
to another `UntrustedDocumentText` with the same content, never to a bare `str`), and an explicit
`isinstance(wrapped, str) is False` guard against the design being silently weakened later.

**The rule Milestone 7 must follow**, written into the type's own docstring so it travels with the
code: any prompt-construction code that calls `.reveal()` must (1) pass the revealed text only as
clearly-delimited DATA — a separate user-turn/tool-input field — never concatenated into a system
or instruction message; (2) never let an instruction-shaped sentence found inside the document
change which instructions the model follows; (3) remember the extraction model this feeds is
never given tools (`processing.ai.schemas.ChatMessage.role` already excludes `tool`/`function`,
per `docs/handoff/004`) — document content is reportable data, never a command to obey.

### 6. `pypdf` API notes for whoever extends this next

- `PageObject.replace_contents(stream)` is the correct **public** API for attaching a content
  stream to a page (used throughout `pdf_builders.py`) — no need to reach for `PdfWriter._add_object`
  or any other underscored/private method.
- `DecodedStreamObject.flate_encode()` (also public) is what makes a content stream small on disk
  while decompressing to something much larger — this is both how the decompression-bomb fixture
  was built and, more importantly, ordinary behaviour for any real compressed PDF; it is not
  something exotic to guard against only in adversarial inputs.
- `PdfWriter.encrypt(user_password=..., algorithm="RC4-128")` needs no extra dependency (unlike
  `algorithm="AES-256"`, which requires the `cryptography` package pypdf does not bundle) — used
  for the encrypted-PDF fixture so no new dependency was needed for testing.

## How to verify

All commands run from `apps/processing/`, `uv` on `PATH`, against the project's pinned Python
3.14.7 interpreter.

```
$ uv lock --check
Resolved 58 packages in 1ms

$ uv sync --frozen
Checked 56 packages in 0.72ms

$ uv run ruff format --check .
32 files already formatted

$ uv run ruff check .
All checks passed!

$ uv run pyright
0 errors, 0 warnings, 0 informations

$ uv run pytest -q
........................................................................ [ 97%]
..                                                                       [100%]
74 passed in 6.28s

$ uv run pip-audit
No known vulnerabilities found
Name       Skip Reason
---------- -------------------------------------------------------------------------
processing Dependency not found on PyPI and could not be audited: processing (0.1.0)
```

(`processing` itself is skipped by `pip-audit` because it's the local package, not a PyPI
dependency — same expected skip recorded in `docs/handoff/004` and `docs/handoff/007`.)

Test count: 74, up from the 21 recorded in `docs/handoff/007-pdf-licence-swap.md` — 53 new tests,
all in `apps/processing/tests/documents/`. No dependency changed: `uv.lock` is untouched by this
task (confirmed by `uv lock --check` above resolving the same 58 packages as before).

## Interfaces exposed to other modules

```python
from processing.documents import (
    extract_document,              # the entry point
    DocumentExtractionSettings,    # DOC_EXTRACTION_* env-configurable thresholds
    DocumentExtractionResult,      # page_count, pages, overall_decision, pages_needing_ocr
    PageExtractionResult,          # page_number, text, quality_signals, quality_score, needs_ocr
    PageQualitySignals,            # the four measurable signals, see table above
    OcrDecision,                   # StrEnum: NATIVE_TEXT_SUFFICIENT | OCR_REQUIRED
    UntrustedDocumentText,         # .reveal() only; see prompt-injection contract above
    DocumentExtractionError,       # base class
    FileTooLargeError, InvalidMagicBytesError, EncryptedPdfRejectedError,
    TooManyPagesError, EmptyDocumentError, MalformedPdfError, ExtractionTimeoutError,
)

result = extract_document(pdf_bytes, declared_filename="invoice.pdf")  # settings optional
# result.overall_decision is OcrDecision.OCR_REQUIRED or NATIVE_TEXT_SUFFICIENT
# result.pages_needing_ocr is a list of 1-indexed page numbers
# result.pages[i].text.reveal() is the extracted text for page i+1 (never over Reverb — §16)
```

Every rejection raises a typed `DocumentExtractionError` subclass — callers should catch the base
class if they only need "this document was rejected," or a specific subclass to react differently
(e.g. `EncryptedPdfRejectedError` might prompt the ephemeral-password flow once Milestone 7 builds
it; `ExtractionTimeoutError`/`MalformedPdfError` are candidates for a retry-with-alert queue state).

**Contract for whoever builds the Laravel-facing HTTP endpoint (Milestone 7)**: this task
deliberately did not build that endpoint (out of scope — "no upload endpoints" in the task brief).
The natural shape, matching `apps/processing`'s existing `/health` pattern
(`docs/handoff/004-python-browser-services.md`), would be an internal POST endpoint accepting an
object-storage reference (never document bytes over Reverb, per §16) and returning
`DocumentExtractionResult` serialized as JSON — `page_count`/`overall_decision`/
`pages_needing_ocr`/`quality_signals`/`quality_score` are all safe to serialize directly;
`PageExtractionResult.text` (`UntrustedDocumentText`) is **not** JSON-serializable as written
(no `__get_pydantic_core_schema__`) — this is deliberate, forcing whoever wires up that endpoint
to make an explicit decision about whether/how extracted text crosses that boundary, rather than
inheriting an accidental default serialization.

## What is NOT done

- **No OCR call, no LLM call, no external provider integration.** ADR 0005's consent/audit/DPA
  gate is unchanged and still blocks this (`docs/STATUS.md` blocked item #3) — this task only
  builds the decision of *whether* that path is needed, never the call itself.
- **No HTTP endpoint.** `extract_document` is a Python function, not a route. No FastAPI route
  was added to `main.py`.
- **No document database tables/models.** Nothing here touches Postgres; the `documents`/
  `document_derivatives`/`document_extractions` tables from `BLUEPRINT.md` §6.6 are not implemented.
- **No non-PDF document types.** Receipts/invoices as photos (JPEG/PNG etc.) are entirely OCR
  territory already, out of scope for a PDF-native-text-layer service by definition.
- **No ephemeral-password flow for encrypted PDFs.** Every encrypted PDF is rejected outright
  today; `BLUEPRINT.md` §14.2's "unless user provides explicit password via ephemeral flow" clause
  needs its own design (how the password reaches the isolated worker without ever touching a log
  or a queue payload in plaintext) — deliberately not improvised here.
- **No AV/ClamAV scan.** `BLUEPRINT.md` §14.2 lists this as a pipeline stage; it is a distinct
  concern (malware, not malformed-PDF-as-DoS) and was not in this task's scope.
- **No decompression bomb defence beyond timeout + memory ceiling.** These two are real,
  measured, and tested, but they are generic backstops, not an exhaustive enumeration of every
  possible PDF attack shape (e.g. a crafted, extremely deep object-reference graph causing stack
  exhaustion in `pypdf`'s recursive resolution — not tested here; the timeout would still bound
  it, but this was not specifically constructed and measured the way the content-stream bomb was).
- **No mTLS/signed-JWT auth** on any interface — there is no HTTP interface here yet to secure.
- **Extraction-quality thresholds are not calibrated against real documents** — no real document
  corpus exists yet at this stage of the project (same caveat `docs/adr/0008` already states about
  `pypdf`'s general extraction-quality tradeoff). The values in the table above are reasoned
  defaults verified against hand-built fixtures representing the specific failure modes they
  target, not measured against a real fiscal-document sample.

## Open questions

1. **The ephemeral-password flow for encrypted PDFs** (`BLUEPRINT.md` §14.2) needs its own design
   decision before Milestone 7: how does a user-supplied password reach the isolated worker
   process without appearing in a log, a queue payload, or a stack trace? This task deliberately
   left every encrypted PDF rejected rather than improvise that flow.
2. **Quality-threshold recalibration** once real documents exist. The defaults here are reasoned
   and tested against constructed fixtures for each specific failure mode, but `docs/AGENTS.md` §2
   is clear that this is not the same as validation against real fiscal documents — flagging so a
   future agent does not treat these five numbers as more authoritative than they are.
3. **Where Milestone 7's HTTP endpoint puts `PageExtractionResult.text`** — deliberately left
   unresolved (see "Interfaces exposed to other modules" above) rather than guessed.
4. `docs/STATUS.md` was explicitly out of scope for this task per the task brief (other agents
   working in `apps/api/**`) — whoever owns it next should add this handoff to the M3/M7 rows.

## Sources consulted

- `docs/AGENTS.md` (full read — rule 1 hard prohibitions, especially #13/#14/#15; §2 never
  fabricate; §9 fiscal-work rules, confirmed not applicable here since no fiscal value is
  produced; §11 escalation).
- `docs/CODE-QUALITY.md`, `docs/DEPENDENCY-POLICY.md` (full read per task brief; no new dependency
  was added, so §4's justification requirement has nothing new to record beyond the `S107`
  ruff-ignore addition, noted above).
- `docs/handoff/004-python-browser-services.md` — `apps/processing`'s existing structure, JSON
  logging/correlation-ID conventions (not touched by this task — no logging was added to a pure
  library module with no request context), the `processing.ai` prompt-injection precedent
  (`ChatMessage.role` excluding `tool`/`function`) that `UntrustedDocumentText`'s contract builds
  on directly.
- `docs/handoff/007-pdf-licence-swap.md`, `docs/adr/0008-permissive-pdf-extraction.md` — `pypdf`
  is the mandated library; PyMuPDF is banned (AGPL). Confirmed no PyMuPDF/`fitz` import exists
  anywhere in this task's new code (`grep -rn "fitz\|pymupdf\|PyMuPDF" apps/processing/` — only
  hit is the historical docstring sentence already present in `test_pipeline_dependencies.py`
  before this task started).
- `docs/adr/0005-external-document-ai.md` — the decision this task implements the local half of;
  confirmed no external call was added anywhere in this task's code.
- `BLUEPRINT.md` §14.2 ("OCR-first is forbidden", file security list), §14.3, §14.4, §14.5 (read in
  full, as instructed).
- `pypdf` 6.18.0's own runtime behaviour, verified by direct interactive testing rather than
  documentation alone: `PdfReader.is_encrypted`/`FileNotDecryptedError` on unread pages,
  `PageObject.mediabox.width/height` returning plain `float`, `PageObject.replace_contents()` and
  `DecodedStreamObject.flate_encode()` as the public (non-underscored) API for building content
  streams, `PdfWriter.encrypt(algorithm="RC4-128")` requiring no extra dependency unlike
  `"AES-256"`, and the exact wall-clock/memory behaviour of the decompression-bomb fixture at
  several sizes (all timings in this document are measured on this project's dev host, not
  assumed).
- ISO 32000-2 §7.5.2 (PDF header placement — cited from established, widely-documented PDF-spec
  knowledge for the magic-byte scan-window design; not fetched from a live source this session).
