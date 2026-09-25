# 003 — ADRs 0001–0007, ARCHITECTURE.md, GLOSSARY.md

**Milestone:** M0   **Status:** complete
**Date:** 2026-09-07

## What was built

Seven ADRs, the architecture map, and a glossary — the documentation layer every later agent reads
before touching code, per `docs/AGENTS.md` §4 and this task's own instruction that this work is
load-bearing.

- `docs/adr/0001-modular-monolith.md` — modules under `apps/api/packages/tax-romania/src/<Module>/`; no
  cross-module Eloquent imports, enforced by `deptrac` (not convention); Python is a separate
  deployable for threat/runtime-profile reasons, not scale.
- `docs/adr/0002-envelope-encryption.md` — per-`TaxSubject` DEK wrapped by a KEK (OpenBao), plus
  one distinct **global, versioned blind-index key** for email/CNP/IBAN exact-match lookups.
  States explicitly that the global index key permits a key-holder to correlate rows cross-tenant
  by equality on indexed fields — documented as an accepted tradeoff, not an oversight. Adds a
  security-first posture statement per the coordinator's mid-task instruction: when a control and
  developer convenience conflict, the control wins, and the tradeoff is written down rather than
  worked around.
- `docs/adr/0003-playwright-for-spv-pf.md` — official API first; Playwright only for portal-only
  PF flows; ephemeral browser contexts, no persisted passwords, OTP relayed via Reverb.
- `docs/adr/0004-tax-engine-deterministic.md` — all fiscal logic confined to `TaxEngine`; rules as
  pure functions; every result carries a `CalculationTrace`; AI never originates a filed figure.
- `docs/adr/0005-external-document-ai.md` — **overrules `BLUEPRINT.md` §1.2's PaddleOCR mandate.**
  PyMuPDF extracts native PDF text locally first (already required by §14.3); only scans/photos
  with no text layer go to an external provider, gated on per-subject consent, an EU-region
  no-training-DPA processor, and an audit event per send. Reasons: no GPU (self-hosted PaddleOCR
  would be slow/inaccurate) and the verified fact that `paddlepaddle` has no `cp314` wheels in any
  3.x release, which would have forced Python off 3.14. States the asymmetry explicitly: the RAG
  corpus (ADR 0006) is public legislation with no PII, low risk to send externally; user documents
  carry CNP/IBAN and are the sensitive case — the two ADRs must not be conflated.
- `docs/adr/0006-pgvector-over-qdrant.md` — **overrules `BLUEPRINT.md` §1.1's Qdrant mandate.**
  Corpus is ~10k–100k chunks, well below where a dedicated vector engine's advantages pay for
  themselves; §15.2's real requirement is temporal/tier filtering (a SQL join against
  `source_sections`) plus Postgres lexical search in the same pipeline — one query instead of a
  cross-store merge. Requires `unaccent`, `pg_trgm`, and the `romanian` text-search config.
  Vector index remains a rebuildable derivative per §15.1, so the decision is reversible.
- `docs/adr/0007-provider-agnostic-ai.md` — abstraction is the OpenAI-compatible wire protocol
  (`AI_PROVIDER`/`AI_BASE_URL`/`AI_MODEL`/`AI_API_KEY`), not a class hierarchy; a thin per-provider
  adapter handles only structured-output enforcement, everything else (schema validation) is
  provider-independent. Per the coordinator's mid-task instruction, the Consequences section now
  states explicitly that this is a **dependency-surface** decision as much as a portability one:
  one HTTP client instead of one pinned/audited SDK per vendor under `docs/DEPENDENCY-POLICY.md`.
  Notes honestly that local Llama is a supported config, not a viable production path, given no
  GPU (same constraint as ADR 0005).
- `docs/ARCHITECTURE.md` — three-plane diagram (Laravel/Python/browser) and why Python never
  writes Laravel business tables directly; the target `Domain/<Module>` map with an explicit note
  that **no modules exist yet** (verified: `apps/api/app/` currently has only
  `Providers/Http/Models`, a stock Laravel skeleton); the financial-event→declaration data flow
  from §2.4; where AI is and is not allowed to sit; network segmentation summary. References
  `docs/DEPENDENCY-POLICY.md` and `docs/CODE-QUALITY.md` in its reading-path header, per the
  coordinator's mid-task instruction, so the reading order blueprint → AGENTS → ARCHITECTURE →
  ADRs → these two policies is complete from any entry point.
- `docs/GLOSSARY.md` — CNP, CASS, CAS, D212, SPV, ANAF, BNR, PF/PFA/II/IF/SRL, declarație
  rectificativă, cedarea folosinței bunurilor, venit net, plafon (legal/official terms) and
  TaxSubject, TaxFact, ruleset, blind index, DEK/KEK, envelope encryption, CalculationTrace,
  subject_memberships (project-internal terms). Legal terms whose precise definition I could not
  verify from the blueprint (e.g. CNP's exact issuance encoding, the precise legal scope of
  "cedarea folosinței bunurilor", current deduction percentages for "venit net") are explicitly
  marked as project-usage descriptions, not asserted as legal fact — per this task's instruction
  not to invent legal definitions.

## Files created / changed

- `docs/adr/0001-modular-monolith.md` — new
- `docs/adr/0002-envelope-encryption.md` — new (includes explicit security-first-over-convenience
  statement, added after the coordinator's mid-task message)
- `docs/adr/0003-playwright-for-spv-pf.md` — new
- `docs/adr/0004-tax-engine-deterministic.md` — new
- `docs/adr/0005-external-document-ai.md` — new
- `docs/adr/0006-pgvector-over-qdrant.md` — new
- `docs/adr/0007-provider-agnostic-ai.md` — new (includes explicit dependency-surface argument in
  Consequences, added after the coordinator's mid-task message)
- `docs/ARCHITECTURE.md` — new
- `docs/GLOSSARY.md` — new
- `docs/handoff/003-adrs-architecture.md` — this note

No other files were touched. `docs/STATUS.md` was read but deliberately left alone — the task
scope and the coordinator's message both say the coordinator owns it during parallel waves.

## Key decisions

- ADR 0005 and ADR 0006 each **overrule** an explicit `BLUEPRINT.md` mandate (§1.2 PaddleOCR, §1.1
  Qdrant). Both say so in their Status line and Context section, and both cite the verified facts
  in `docs/reference/verified-versions.md` that force the change (no `cp314` PaddleOCR wheel; the
  actual scale/requirement mismatch for Qdrant). A future agent should not "restore" PaddleOCR or
  Qdrant without a new ADR that overturns these with equally concrete evidence.
- ADR 0002's global blind-index key is a deliberately accepted cross-tenant correlation risk, not
  a bug to fix quietly later. Anyone "fixing" this by switching to per-subject index keys would
  break login-by-email and duplicate-CNP detection, which are the reasons the key is global in the
  first place — re-solving those two features has to happen alongside any change here.
- ADR 0007 draws a hard line at "one wire protocol, not one SDK per vendor." Adding a
  vendor-specific SDK to reach a provider that already speaks the OpenAI-compatible protocol
  requires a documented reason the protocol can't satisfy — it is not permitted as a convenience.
- Read both new coordinator-supplied policy docs (`docs/DEPENDENCY-POLICY.md`,
  `docs/CODE-QUALITY.md`) mid-task and incorporated their requirements into ADR 0002, ADR 0007,
  and `ARCHITECTURE.md`'s reading-path header, as instructed, rather than treating them as
  out of scope because they weren't in the original file list.
- `ARCHITECTURE.md`'s module table is stated as a **target**, explicitly flagged as unbuilt —
  verified against the actual repo state (`apps/api/app/` has no `Domain/` directory yet) so the
  next agent doesn't mistake the map for a status report. Actual status lives in `docs/STATUS.md`,
  which this task did not touch.

## How to verify

- All nine files exist and are non-empty:
  ```
  ls -la docs/adr/000{1..7}-*.md docs/ARCHITECTURE.md docs/GLOSSARY.md docs/handoff/003-adrs-architecture.md
  ```
- Cross-references resolve: every ADR is linked from `docs/ARCHITECTURE.md` §7; `ARCHITECTURE.md`
  is linked from `docs/README.md`'s existing reading order (unchanged, not edited by this task).
- No fabricated fiscal values, rates, or ANAF endpoints appear in any file — every concrete
  external fact cited (Laravel/PHP/Python versions, Postgres/pgvector version, `paddlepaddle`
  wheel availability, container image tags) is drawn verbatim from
  `docs/reference/verified-versions.md`, which was read in full before writing.
- This is a documentation task with no code and no test suite; "verify" here means re-reading the
  ADRs against `docs/AGENTS.md`'s hard prohibitions (§1) to confirm none of the seven decisions
  authorizes rule-breaking. Spot check: ADR 0003 does not authorize permanent SPV password
  storage (rule 5); ADR 0005 does not authorize an implicit external send (rule 13); ADR 0004 does
  not authorize AI as a source of a filed figure (the one rule everything else serves, AGENTS.md
  §0). All three hold on re-read.

## Interfaces exposed to other modules

None — this is documentation, not code. What other agents get from this work:
- A stable set of ADR file paths (`docs/adr/0001`–`0007`) to cite instead of re-deciding these
  seven questions.
- `docs/ARCHITECTURE.md`'s module table as the target namespace layout for any agent starting
  Laravel `Domain/` work.
- `docs/GLOSSARY.md` as a shared vocabulary reference, explicitly marked where a term is
  project-internal versus where its legal definition still needs verification from
  `TaxKnowledge` sources.

## What is NOT done

- No code was written or changed. `apps/api/packages/tax-romania/src/` still does not exist — creating it is
  Milestone 1 work (Identity/Subjects/security foundation per `docs/STATUS.md`), not this task.
- `docs/STATUS.md` was **not** updated, per explicit instruction that the coordinator owns it
  during this parallel wave. Whoever merges this work should update the M0 row and, if useful,
  note that ADRs 0001–0007 now exist.
- ADR 0005's actual external AI provider, region, and DPA are still undecided
  (`docs/STATUS.md` blocked item #3, unchanged by this task) — the ADR documents the *gating
  mechanism* (consent + audit event + EU no-training DPA requirement), not a specific vendor
  choice.
- No decision was made about whether/when to revisit ADR 0006 if the RAG corpus outgrows the
  10k–100k chunk estimate — the ADR names this as a future trigger, not a plan.

## Open questions

- Which specific external document-AI provider satisfies ADR 0005's EU-region/no-training-DPA
  requirement — needs a human/compliance decision, already tracked as `docs/STATUS.md` blocked
  item #3.
- Whether OpenBao (the KMS/secrets backend named in ADR 0002 and verified in
  `docs/reference/verified-versions.md`) is the final production KMS choice or a dev-stage
  stand-in for a cloud KMS/HSM per `BLUEPRINT.md` §4.2's stated preference — this task assumed
  OpenBao based on the verified-versions container list, but did not see an ADR settling that
  choice explicitly. A future ADR may be needed if that assumption is wrong.

## Sources consulted

- `BLUEPRINT.md` — full blueprint, with §1–§5, §11, §14, §15, §19, §20, §28, §30,
  §38 read closely per this task's instruction.
- `docs/AGENTS.md` — binding rules, read in full before writing.
- `docs/reference/verified-versions.md` — every version/tooling fact cited in
  ADRs 0002, 0005, 0006 is sourced from here (checked 2026-09-07).
- `docs/DEPENDENCY-POLICY.md` and `docs/CODE-QUALITY.md` — read
  mid-task per the coordinator's message; both are now referenced from `docs/ARCHITECTURE.md`'s
  reading-path header and their relevant sections cited in ADR 0002 and ADR 0007.
- `docs/STATUS.md` — read for context (blocked items, milestone state) but not
  modified.
- Repo inspection: `find apps/api/app -maxdepth 4 -type d` (confirmed no `Domain/` module exists
  yet) and `apps/api/composer.json` (confirmed fresh Laravel skeleton, `php ^8.3` — not yet pinned
  to the verified 8.5 target, noted for awareness but out of this task's scope to fix).
