# 007 — PyMuPDF (AGPL) → pypdf (BSD-3-Clause) licence swap in `apps/processing`

**Milestone:** M0 (dependency correction, no pipeline logic)   **Status:** complete
**Date:** 2026-09-08

## What was built

Nothing functional — this is a dependency swap and its documentation, not new pipeline code
(explicitly out of scope; native PDF extraction logic is Milestone 3/7 work).

`pymupdf` (AGPL-3.0-or-later, flagged as an open question in
`docs/handoff/004-python-browser-services.md` when it was first added) is removed from
`apps/processing` entirely and replaced with `pypdf` 6.18.0 (BSD-3-Clause). The product owner
resolved the `BLUEPRINT.md` §1.2 (names PyMuPDF) vs §29 (requires "active, maintained,
permissive-license") contradiction on 2026-09-08 in favour of §29. Full reasoning, the license
verification, and the alternatives considered (including buying Artifex's commercial PyMuPDF
licence, and `pdfplumber`) are in the new ADR 0008.

## Files created / changed

- `apps/processing/pyproject.toml` — `pymupdf>=1.28.2,<1.29.0` replaced with
  `pypdf>=6.18.0,<6.19.0` (explicit lower+upper bound, per `docs/DEPENDENCY-POLICY.md` §1).
- `apps/processing/uv.lock` — regenerated via `uv lock`; `pymupdf` removed, `pypdf` added.
  Committed.
- `apps/processing/tests/test_pipeline_dependencies.py` — the PyMuPDF import-smoke test replaced
  with `test_pypdf_can_read_a_pdf_and_detect_its_text_layer`, which now actually exercises the
  API surface §14.3 needs (create a blank PDF with `PdfWriter`, read it back with `PdfReader`,
  call `extract_text()`) rather than only importing and reading `__version__`. This is still a
  dependency smoke test, not the M3/M7 pipeline — a blank page's `extract_text() == ""` is the
  correct assertion, not a placeholder. The `opencv-python-headless` smoke test is untouched
  (unrelated dependency, unaffected by this change).
- `docs/adr/0008-permissive-pdf-extraction.md` (new) — Status: accepted, 2026-09-08. Context
  (AGPL §13 network clause; the §1.2/§29 blueprint contradiction), Decision (`pypdf` over
  `pdfplumber`, with the reasoning tied to the actual §14.3 requirement — detect a text layer and
  extract it, nothing more), Consequences (including the honest downside: `pypdf`'s text
  extraction is weaker than PyMuPDF's on complex layouts, mitigated because any page it fails to
  extract cleanly is routed to ADR 0005's already-gated external document-AI path rather than
  producing silently-wrong text), Alternatives (`pdfplumber`, buying the Artifex commercial
  licence and why that doesn't satisfy §29 either, doing nothing), References.
- `docs/adr/0005-external-document-ai.md` — its two PyMuPDF references (the local-extraction
  decision point, and the References list) updated to name `pypdf` and cross-reference ADR 0008.
  Its local-text-layer-extraction-first reasoning, the external-provider consent/audit/DPA
  gating, and every other decision in it are unchanged — only the library specifics changed.
- `docs/reference/verified-versions.md` — Python table: the PyMuPDF row replaced with a `pypdf`
  6.18.0 row recording its licence (BSD-3-Clause, confirmed from the wheel's own
  `dist-info/METADATA` `License-Expression` field and the PyPI JSON API's `info.license_expression`
  — not from memory) and the `cp314` classifier check, plus a note explaining the `pdfplumber`
  evaluation and why it was not adopted. Checked 2026-09-08 (the file's top banner date,
  2026-09-07, covers unrelated facts checked the day before; this entry is separately dated).

## Key decisions

1. **`pypdf`, not `pdfplumber`, despite both being permissive (BSD-3-Clause vs MIT) and both
   verified as supporting Python 3.14.** The real requirement (§14.3: detect a usable text layer,
   extract it, nothing else) is exactly `pypdf.PdfReader(...).pages[i].extract_text()`.
   `pdfplumber` sits on `pdfminer.six` and adds per-character positions, table detection, and
   layout reconstruction — none of it needed here, and it pulls in three required dependencies
   (`pdfminer.six`, `Pillow`, `pypdfium2`) that `pypdf` doesn't need at all on Python ≥3.11
   (`pypdf` has *zero* required runtime dependencies above that). Per `docs/CODE-QUALITY.md` §2,
   one library was adopted, not both, and it was chosen for the actual requirement, not for
   maximum capability.
2. **Buying the Artifex commercial PyMuPDF licence was considered and rejected** — see ADR 0008's
   Alternatives section. It would resolve the AGPL §13 obligation but not the §29 policy
   preference against copyleft/vendor-locked dependencies generally, and it introduces an ongoing
   paid contract for a widely-solved, freely-available capability.
3. **`opencv-python-headless` was left untouched.** It is Apache-2.0, was never part of the AGPL
   problem, and is scoped to future (M3/M7) image preprocessing per ADR 0005 / `BLUEPRINT.md` §1.2 —
   out of scope for this task and not touched.
4. **No extraction/detection logic was written.** The updated smoke test exercises the library's
   real API (so a future agent inherits a test that actually proves the dependency does what
   §14.3 will need) but does not implement text-layer detection thresholds, page routing, or any
   pipeline decision — that is explicitly Milestone 3/7 work per the task brief.

## How to verify

All commands run from `apps/processing/`, with `uv` on `PATH` (`~/.local/bin`), against the
project's pinned Python 3.14.7 interpreter (already installed on this host via
`uv python install 3.14`, per `docs/handoff/004`).

```
$ uv lock --check
Resolved 58 packages in 1ms

$ uv sync --frozen
Checked 56 packages in 0.64ms

$ uv run ruff format --check .
16 files already formatted

$ uv run ruff check .
All checks passed!

$ uv run pyright
0 errors, 0 warnings, 0 informations

$ uv run pytest -q
.....................                                                    [100%]
21 passed in 0.16s

$ uv run pip-audit
No known vulnerabilities found
Name       Skip Reason
---------- -------------------------------------------------------------------------
processing Dependency not found on PyPI and could not be audited: processing (0.1.0)
```

(`processing` itself is the local package, not a PyPI dependency — expected `pip-audit` skip,
same as recorded in `docs/handoff/004`.)

Test count is unchanged at 21 (one test renamed/rewritten in place, none added or removed) —
matches the count in `docs/handoff/004-python-browser-services.md` before this change.

Confirmed no import of the removed package remains anywhere in scope:

```
$ grep -rn "fitz\|pymupdf\|PyMuPDF" apps/processing/
apps/processing/tests/test_pipeline_dependencies.py:8:`pypdf` replaced `PyMuPDF` per ADR 0008 (AGPL-3.0-or-later removed; BSD-3-Clause adopted).
```

That one hit is a docstring sentence recording the history of the swap, not a live import —
confirmed by inspection of the file.

## Interfaces exposed to other modules

None. `pypdf` is a dependency only — no wrapper, adapter, or public function was added around it.
Milestone 3/7 is expected to build the actual text-layer-detection/extraction module against
`pypdf.PdfReader`/`extract_text()` directly, the same way `docs/handoff/004` left PyMuPDF as an
unwrapped dependency for the same future milestone.

## What is NOT done

- No text-layer-detection threshold or heuristic (e.g., "page counts as having a usable text layer
  if `extract_text()` returns more than N non-whitespace characters") — that is Milestone 3/7
  pipeline logic, explicitly out of scope for this task.
- No routing logic connecting a failed/weak extraction to ADR 0005's external OCR path — same
  reason.
- `apps/api`, `docs/STATUS.md`, and any file outside this task's stated scope were not touched, by
  instruction — three other agents were working in parallel elsewhere in the repo. `docs/STATUS.md`
  should be updated by whoever owns it next to reflect that the PyMuPDF open question from
  `docs/handoff/004` is now resolved (ADR 0008).
- No load-testing of `pypdf`'s extraction quality against real, complex-layout PDFs — ADR 0008's
  Consequences section states this honestly as an expectation from the library's known
  characteristics, not a measured result, because no real document corpus exists yet at this
  stage of the project.

## Open questions

- `docs/STATUS.md` (out of scope for this task) still names PyMuPDF per
  `docs/handoff/004-python-browser-services.md`'s original open question; whoever owns that file
  should close/update that item, pointing at ADR 0008.
- If a future milestone genuinely needs table or layout extraction beyond a text-layer yes/no
  check, that is a new, separately-scoped dependency decision (a new ADR), not a reason to
  reconsider this one — flagging so it isn't silently re-litigated by adding `pdfplumber` on top
  later without a documented reason.

## Sources consulted

- `docs/AGENTS.md` (full read, per §4 — rule 9 dependency-justification, rule 1 hard prohibitions,
  §11 escalation-on-conflict, which is the basis for this swap being an ADR rather than a silent
  patch).
- `docs/DEPENDENCY-POLICY.md`, `docs/CODE-QUALITY.md` (full read, per task brief).
- `docs/handoff/004-python-browser-services.md` — origin of the PyMuPDF dependency and its
  flagged AGPL open question; `apps/processing`'s existing structure, verification commands, and
  test count baseline (21 tests).
- `docs/adr/0005-external-document-ai.md` — read in full before editing; only the two PyMuPDF
  mentions were changed, its reasoning was not rewritten, per the task brief's explicit
  instruction.
- `docs/reference/verified-versions.md` — read in full before editing.
- PyPI JSON API (`pypi.org/pypi/pypdf/json`, `pypi.org/pypi/pdfplumber/json`,
  `pypi.org/pypi/pdfminer.six/json`), checked 2026-09-08 — versions, `license_expression`,
  `requires_dist`, latest-release timestamps.
- `pypdf-6.18.0-py3-none-any.whl`'s own `pypdf-6.18.0.dist-info/METADATA` (downloaded and
  inspected directly with `pip download` + `unzip -p`, not taken from a secondary source) —
  confirms `License-Expression: BSD-3-Clause`, `License-File: LICENSE`, and the
  `Programming Language :: Python :: 3.14` classifier.
- `uv lock` output confirming the resolver's own record of the swap ("Removed pymupdf v1.28.2 /
  Added pypdf v6.18.0").
