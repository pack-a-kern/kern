# ADR 0008 — `pypdf` (BSD-3-Clause) replaces PyMuPDF (AGPL-3.0-or-later) for native PDF text extraction

**Status:** accepted, 2026-09-08

## Context

`apps/processing`'s `pyproject.toml` carried `pymupdf==1.28.2` as the native-PDF-text-extraction
dependency required by §14.3 ("OCR-first is forbidden": detect a native text layer and extract it
before ever invoking OCR) and used as the local, zero-external-call default path in ADR 0005.

`pymupdf` on PyPI is **AGPL-3.0-or-later**, dual-licensed with a paid commercial option from
Artifex (the project's maintainer). This was flagged as an open question in
`docs/handoff/004-python-browser-services.md` at the time it was added, and is confirmed here
against the package's own PyPI classifiers, not from memory.

Two things make this untenable as shipped:

1. **The AGPL's §13 network-use clause.** TaxOS România is a SaaS product: users interact with it
   exclusively over a network, never by receiving a distributed copy of the software. AGPL §13
   specifically closes the "SaaS loophole" the ordinary GPL leaves open — it obliges anyone who
   runs a modified AGPL work and lets users interact with it remotely to offer those users the
   complete corresponding source of the *entire combined work*, not just the AGPL component. For a
   proprietary commercial product built on a private, security-hardened codebase (`BLUEPRINT.md`
   §4, the whole envelope-encryption/key-separation threat model), that obligation is
   incompatible with the business as designed.
2. **`BLUEPRINT.md` §1.2 names PyMuPDF explicitly, but `BLUEPRINT.md` §29 (`docs/DEPENDENCY-POLICY.md`
   §4) requires every dependency to be "active, maintained, permissive-license."** AGPL is not a
   permissive license by any standard definition (MIT/BSD/Apache-2.0 are permissive; AGPL is the
   strongest copyleft license in common use). §1.2 and §29 directly contradict each other for this
   one dependency. Per `docs/AGENTS.md`'s own escalation rule (§11: "two blueprint sections
   conflict"), this is exactly the kind of conflict that gets resolved by a product-owner decision
   and recorded here, not silently picked by whichever section an agent happened to read first.

The product owner resolved the conflict on 2026-09-08: §29's permissive-license requirement wins.
PyMuPDF is removed before any pipeline is built on top of it (Milestone 3/7 had not started —
`docs/handoff/004` explicitly scoped PyMuPDF as import-smoke-tested only, no extraction logic
written), so this is a dependency swap, not a migration of working code.

## Decision

1. **Remove `pymupdf` from `apps/processing` entirely.** Replace it with **`pypdf`
   6.18.0 (BSD-3-Clause)**.
2. **License confirmed from the package's own PyPI metadata**, not from memory or prior
   familiarity: `pypdf` 6.18.0's wheel `METADATA` (`pypdf-6.18.0.dist-info/METADATA`) declares
   `License-Expression: BSD-3-Clause` and ships `licenses/LICENSE` in the wheel; the PyPI JSON API
   confirms the same via `info.license_expression`. This is an SPDX-recognized permissive license
   — no copyleft, no network-use clause, no dual-licensing complication.
3. **`pypdf` over `pdfplumber` (MIT, also evaluated — see Alternatives) because the actual
   requirement is narrower than either library's full feature set.** §14.3 requires exactly two
   things: detect whether a page has a usable text layer, and extract that text when it does —
   OCR is reserved for pages that fail that check. `pypdf.PdfReader(...).pages[i].extract_text()`
   is precisely that operation — a string, empty when there is no text layer, non-empty text when
   there is. `pdfplumber` sits on top of `pdfminer.six` and layers on per-character positions,
   table detection, and layout analysis, none of which §14.3's binary
   has-a-text-layer-or-not check needs. Per `docs/CODE-QUALITY.md` §2, a dependency is not added
   for capability the task does not require, so only one library is adopted.
4. **`pypdf` is also the lighter dependency.** It ships as a pure-Python wheel with **zero required
   runtime dependencies on Python ≥3.11** (`typing_extensions` is required only below 3.11), and
   its PyPI classifiers list `Programming Language :: Python :: 3.14` explicitly — confirmed by
   installing it into the project's pinned 3.14.7 interpreter and running the updated smoke test
   (see `docs/handoff/007-pdf-licence-swap.md`). `pdfplumber` 0.11.10 pulls in `pdfminer.six`,
   `Pillow`, and `pypdfium2` as required dependencies — three additional packages, each with its
   own license, CVE surface, and monthly-review burden under `docs/DEPENDENCY-POLICY.md`, for
   capability this task does not use.
5. **`opencv-python-headless` is unaffected and stays.** It is Apache-2.0, unrelated to the AGPL
   problem, and was never the subject of this decision — it remains scoped to future (M3/M7)
   image preprocessing per ADR 0005/`BLUEPRINT.md` §1.2.
6. **`BLUEPRINT.md` §1.2's literal mention of PyMuPDF is superseded by this ADR**, in the same manner
   ADR 0005 already supersedes §1.2's PaddleOCR mandate — the blueprint names an implementation
   detail that a verified, binding constraint (here: license policy; there: GPU availability and
   wheel support) makes impractical. The §14.3 *requirement* (native-text-first, OCR only where
   needed) is completely unchanged; only the library satisfying it changes.
7. **ADR 0005's reasoning is otherwise untouched.** The local-text-layer-extraction-first decision,
   the external-provider gating for scans, the consent/audit/DPA requirements — none of that
   depended on which library performed the local extraction. ADR 0005 is updated only to point its
   PyMuPDF references at `pypdf` and cross-reference this ADR.

## Consequences

**Good:**
- Removes a copyleft license from a proprietary commercial SaaS product's dependency tree before
  any pipeline code was built against it — the cheapest possible time to make this change.
- Resolves the `BLUEPRINT.md` §1.2 vs §29 contradiction explicitly, in writing, rather than leaving
  it as a standing discrepancy between the blueprint and the dependency policy for the next agent
  to trip over.
- `pypdf` is actively maintained (6.18.0 released 2026-09-07, one day before this ADR — checked
  against PyPI, not assumed) and has a materially smaller dependency and license-review surface
  than the rejected alternative.
- No functional capability is lost relative to what had actually been built: PyMuPDF was
  import-smoke-tested only in this codebase, with zero extraction logic written against it
  (`docs/handoff/004`), so nothing downstream needs to change beyond the dependency itself and its
  smoke test.

**Bad — the honest tradeoff, not a footnote:**
- **`pypdf`'s text extraction is measurably lower quality than PyMuPDF's on complex layouts** —
  multi-column PDFs, unusual font encodings, and PDFs with non-standard glyph-to-Unicode mappings
  are documented weak points for `pypdf` (and for pure-Python extractors generally) relative to
  PyMuPDF's MuPDF-based C rendering engine. Some text that PyMuPDF would extract correctly from a
  page with a genuine (if awkwardly encoded) text layer may come back garbled or empty from
  `pypdf`, which would misroute that page into the OCR path under §14.3's has-a-text-layer check.
- **This is mitigated, not eliminated, by ADR 0005's existing architecture**: any page `pypdf`
  fails to extract cleanly from is treated exactly like a genuine scan and routed to the external
  document-AI provider ADR 0005 already gates behind per-subject consent, EU-region/no-training
  DPA, and an audit event. The cost of `pypdf`'s weaker complex-layout handling is therefore a
  **higher OCR/external-provider volume than PyMuPDF would have produced**, not a correctness gap
  — no page silently produces wrong text as "extracted," because the human-review gate in
  §14.4–§14.6 sits downstream of both paths regardless of which one a page took. It is a real cost
  (more documents touching the external provider, which ADR 0005 already treats as the
  higher-sensitivity path) and should be watched once real document volume exists, not assumed
  away.
- No dependency swap of this kind has been load-tested against real documents yet — this ADR
  records a license and API-surface decision made before Milestone 3/7 extraction logic exists.
  The extraction-quality-vs-OCR-volume tradeoff above is a documented expectation from each
  library's known characteristics, not yet a measured result from this project's own documents.

## Alternatives considered

- **Buy Artifex's commercial PyMuPDF license.** Rejected. It would resolve the AGPL obligation
  contractually, but it does not resolve the `BLUEPRINT.md` §29 "permissive-license" policy
  violation — §29 is a licensing-model preference for the dependency tree generally (avoid
  copyleft, avoid vendor lock-in, avoid a recurring paid dependency for a capability an
  unencumbered library already provides), not merely an AGPL-compliance workaround. It also
  introduces an ongoing commercial contract, a renewal/pricing risk, and a single-vendor
  dependency for a widely-solved problem (native PDF text extraction) where multiple
  actively-maintained permissive alternatives exist. `docs/DEPENDENCY-POLICY.md` §4 asks that the
  standard library, an existing dependency, or a well-maintained permissive third-party package be
  preferred, in that order — a paid proprietary SDK is not on that list, and nothing about this
  requirement needs it.
- **`pdfplumber` 0.11.10 (MIT).** Considered seriously — genuinely permissive, actively
  maintained (0.11.10 released 2026-06-15). Rejected in favor of `pypdf` specifically because
  §14.3's requirement is narrower than what `pdfplumber` is built for (per-character positions,
  tables, layout reconstruction), and adopting it would mean three extra required dependencies
  (`pdfminer.six`, `Pillow`, `pypdfium2`) for capability unused by this task —
  `docs/CODE-QUALITY.md` §2's "no speculative scaffolding" and "if one library covers it, do not
  add a second" apply equally to over-provisioned single-library choices. If a future milestone
  needs table/layout extraction that `pypdf` genuinely cannot provide, that is a new, scoped
  decision with its own ADR — not a reason to carry the extra dependency surface now.
- **Do nothing; keep PyMuPDF and accept the AGPL obligation.** Rejected outright — incompatible
  with operating TaxOS România as a proprietary commercial SaaS product; this is the premise the
  task started from, not a live option.
- **Tesseract or another OCR-only tool as a full PyMuPDF replacement.** Not applicable — Tesseract
  is an OCR engine (image → text), not a PDF-native-text-layer reader, and ADR 0005 already
  rejected Tesseract as even a fallback OCR path. This ADR is about the *local, no-OCR* extraction
  step that runs before OCR is ever considered.

## References

- `BLUEPRINT.md` §1.2 (names PyMuPDF — superseded here for this one dependency, per the same pattern
  ADR 0005 already used for PaddleOCR), §4 (threat model — proprietary-codebase premise that makes
  AGPL §13 untenable), §14.3 (native-text-before-OCR requirement, unchanged by this ADR), §19.2
  and §29 (permissive-license dependency policy — the requirement this ADR satisfies).
- `docs/AGENTS.md` §11 (escalate when two blueprint sections conflict — §1.2 vs §29 is exactly
  this case).
- `docs/DEPENDENCY-POLICY.md` §1 (explicit lower+upper version bounds), §2 (current, CVE-free,
  license and maintenance recorded in `docs/reference/verified-versions.md`), §4 (justification
  order: stdlib → existing dependency → first-party package → well-maintained third-party
  package — a paid commercial SDK is not on this list).
- `docs/CODE-QUALITY.md` §2 (no speculative scaffolding; do not add a second library "just in
  case" when one covers the actual requirement).
- ADR 0005 (external document AI) — its local-extraction-first decision and external-provider
  gating are unaffected by this ADR and absorb the extraction-quality tradeoff noted above.
- `docs/handoff/004-python-browser-services.md` — original PyMuPDF addition and the AGPL open
  question raised at the time.
- `docs/handoff/007-pdf-licence-swap.md` — the verification commands and real output for this
  swap.
- PyPI JSON API and wheel metadata for `pypdf` 6.18.0 and `pdfplumber` 0.11.10, checked
  2026-09-08 — license expressions, dependency lists, release dates.
