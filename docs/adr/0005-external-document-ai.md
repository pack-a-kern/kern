# ADR 0005 — Local text-layer extraction first; external AI only for scans, gated

**Status:** accepted, 2026-09-07 — **this overrules `BLUEPRINT.md` §1.2's PaddleOCR mandate.**

## Context

`BLUEPRINT.md` §1.2 names PaddleOCR as the primary self-hosted OCR engine, Tesseract as fallback.
Two verified facts (`docs/reference/verified-versions.md`) make that mandate impractical as
written:

1. **No GPU is available** in this deployment target. Self-hosted PaddleOCR without a GPU is both
   slow and materially less accurate than a hosted OCR/document-AI service — exactly the kind of
   tradeoff that would degrade the product's core "structured, reviewable extraction" promise
   (§14.4) rather than protect it.
2. **`paddlepaddle` ships no `cp314` wheels in any 3.x release** (3.1.1–3.3.1 cover cp39–cp313
   only, verified against PyPI on 2026-09-07). Including it would force the Python runtime off the
   project's target version (3.14) and onto an older, shorter-support one, contaminating a
   language-version decision with a single library's packaging lag.

At the same time, §14.3 already forbids an OCR-first pipeline: native PDF text must be extracted
before any OCR is attempted, and OCR should run only on pages that actually need it. That
requirement, taken seriously, changes the shape of the problem: most PF documents relevant to this
product (bank statements, many contracts, digitally-generated invoices) carry a native PDF text
layer and need no OCR at all. The genuinely hard case — a phone photo of a paper receipt or a
scanned rental contract with no text layer — is a minority of volume, not the default path.

## Decision

1. **`pypdf` (BSD-3-Clause) extracts native PDF text locally, for free, as the first and default
   path** (already required by §14.3). This handles the majority of documents with zero OCR and
   zero external calls, and needs no GPU. `pypdf` replaced PyMuPDF (AGPL-3.0-or-later) on
   2026-09-08 for licensing reasons unrelated to this ADR's local-extraction-first reasoning —
   see ADR 0008 for the full rationale and license verification. The choice of library changed;
   the decision to extract locally before ever considering OCR did not.
2. **Only pages/documents with no usable text layer** — genuine scans and photos — are sent to an
   **external document-AI/OCR provider**. This is a narrower scope than "AI extraction" in
   general: text-layer PDFs never leave the local pipeline; only image-only content does.
3. That external send is never implicit. Every external send requires, per `docs/AGENTS.md` rule
   13:
   - **per-subject consent**, recorded, not assumed from a global setting;
   - an **EU-region processor** under a **no-training** DPA (which specific provider, and the
     signed DPA itself, is `docs/STATUS.md` blocked item #3 — not decided by this ADR);
   - an **audit event per external send**, written to the append-only `audit_events` chain
     (§4.8), naming the document, the provider, and the subject's consent record.
4. Output from the external call is treated exactly like any other AI extraction under §14.4–§14.6:
   strict JSON-schema validation, `PROPOSED` status only, human review before a high-impact
   `TaxFact` is confirmed. This ADR changes *where OCR/extraction happens*, not the trust level
   assigned to what it produces.
5. Tesseract as a local fallback is **not adopted**. If the external provider is unavailable, the
   document waits in a retry state rather than falling back to a lower-quality local OCR path that
   would need its own review/quality tooling to be trustworthy.
6. This decision is scoped to **user-submitted documents** (receipts, invoices, contracts, bank
   statements) — the ones that can carry CNP, IBAN, and other PII per §4.3. It does not extend to
   the RAG legislation corpus; see ADR 0006 and the asymmetry note below.

## Consequences

**Good:**
- Removes a hard PaddleOCR/cp314 blocker on the Python 3.14 target without downgrading the whole
  runtime for one dependency.
- Native-text-first means the *majority* of documents never touch an external provider at all —
  the exposure this ADR accepts is already narrowed to the minority case by §14.3's existing rule,
  not newly created by this decision.
- A hosted, actively-maintained document-AI provider is very likely more accurate on real-world
  phone photos than a GPU-less self-hosted PaddleOCR would have been, which matters directly for
  §14.6's human-review workload (fewer wrong extractions to catch).
- The per-send audit event and per-subject consent make external sends visible and attributable,
  rather than an invisible default — satisfying the letter and intent of `docs/AGENTS.md` rule 13
  ("not implicitly," not "never").

**Bad — and this is the real tradeoff, not a footnote:**
- **User documents that reach the external path can carry the most sensitive fields the project
  defines**: CNP, IBAN, contract counterparties. This is fundamentally different from ADR 0006's
  RAG corpus, which is public legislation with no PII — sending *that* externally is low-risk;
  sending a photographed ID-bearing contract is not. That asymmetry is the reason this ADR exists
  as a separate, more carefully gated decision from ADR 0006, and it must not be flattened into "we
  already send stuff externally for RAG, so this is fine too."
- A per-subject consent gate means a user who declines consent has degraded functionality for
  scanned documents (queued/blocked, not silently OCR'd anyway) — a real product limitation, not
  just a compliance checkbox.
- Dependence on a third-party processor for a core capability (document intake) introduces a new
  external outage/rate-limit/pricing-change risk that a fully self-hosted pipeline would not have
  had, regardless of accuracy tradeoffs.
- No self-hosted fallback (Tesseract rejected) means an external provider outage stalls
  scan-processing entirely rather than degrading to a worse-but-available local path. This was
  chosen deliberately — a silently worse local OCR result reaching `PROPOSED` state is a bigger
  long-run risk to trust in extraction than a visible delay — but it is a real availability cost.
- Provider/DPA/region selection is not yet made (`docs/STATUS.md` blocked item #3); until it is,
  the external-send path in this ADR is a documented target, not an operating capability.

## Alternatives considered

- **Self-hosted PaddleOCR as originally specified in `BLUEPRINT.md` §1.2.** Rejected — no GPU
  available makes it slow and inaccurate, and it has no `cp314` wheel, which would force Python off
  the verified 3.14 target for the whole project because of one dependency.
- **Self-hosted Tesseract as the OCR engine.** Rejected as the primary path — lower accuracy than a
  hosted document-AI provider on real-world phone photos, which directly increases the human
  review burden §14.6 already treats as the bottleneck. Also rejected as a *fallback* for external
  outages, per Consequences above: a worse-quality silent fallback undermines trust in what
  `PROPOSED` extractions mean.
- **Send every document (including native-text PDFs) to the external provider for simplicity.**
  Rejected — this would violate §14.3's OCR-first prohibition in spirit even if not in literal
  wording, and would needlessly widen the set of documents leaving the local environment when most
  need no OCR at all.
- **Wait for a GPU before implementing any scan/OCR handling.** Rejected — blocks Milestone 7
  (PWA document capture) indefinitely on infrastructure procurement with no committed timeline, for
  a capability (scanned documents) that is a minority of expected volume.

## References

- `BLUEPRINT.md` §1.2 (original PaddleOCR mandate — overruled here), §4.1/§4.3 (threat model,
  encrypted-field list including OCR-extracted data), §14.3 (native-text-before-OCR, already
  binding independent of this ADR), §14.4–§14.6 (AI extraction contracts, prompt injection,
  human confirmation).
- `docs/AGENTS.md` rule 13 (no implicit external send — consent + audit event required),
  rule 14 (LLM output is never truth without schema validation).
- `docs/reference/verified-versions.md` — `pypdf` 6.18.0 (pure-Python wheel, explicit `cp314`
  classifier) is the current native-PDF-text-extraction dependency, per ADR 0008; `paddlepaddle`
  has no `cp314` wheel in any 3.x release; the latter is the fact that makes Python 3.14 viable at
  all, independent of which PDF-text library is used.
- ADR 0008 (permissive PDF extraction) — the license reasoning, verification, and alternatives
  behind the PyMuPDF → `pypdf` swap. This ADR's local-extraction-first decision is unaffected by
  it.
- `docs/STATUS.md` blocked item #3 — external AI provider, region, and DPA not yet selected.

## Amendment, 2026-09-11 — `python-worker` and `processing.ai` removed

Two things were built for the OCR pipeline this ADR cancelled and were never reachable:

- **`python-worker`** — `BLUEPRINT.md` §3.1 gave it "document/AI workers". With OCR dropped and
  extraction running in the Laravel queue, it never got an entrypoint: its command printed a notice
  and slept. Removed from `compose.yaml` and `compose.override.yaml`, and §3.1 amended.
- **`apps/processing/src/processing/ai/`** — a complete LLM client, config, schemas and
  structured-output helper, imported by no route and no other module. The provider that actually
  runs is `App\Domain\Documents\ExternalAi\OpenAiCompatibleDocumentAiProvider`, in PHP. Two
  implementations of one job, one of them wired to nothing. Removed, with its tests.

Dropping them also dropped `httpx2` (used only by `processing.ai`) and `opencv-python-headless`,
which had no caller anywhere — a leftover from the PaddleOCR plan sitting in an image whose own
comments call it "slim, hardened".

`python-api` keeps both live endpoints: `POST /v1/documents/extract-text` and `POST /v1/embeddings`.

### The "external" in this ADR's title is deployment-dependent

Written when the only option for reading a photograph was a hosted service. The current deployment
points `AI_BASE_URL` at a self-hosted vision model on the operator's own network, so no document
leaves the building — the consent gate, the audit event and the schema validation all still apply
and are still correct, but the EU-region/no-training DPA requirement in point 3 is about a third
party and does not bind a self-hosted endpoint. A reader should not conclude from this ADR alone
that receipts are being sent to an outside processor.
