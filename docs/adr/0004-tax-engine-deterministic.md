# ADR 0004 — Deterministic, pure tax engine as the sole source of fiscal figures

**Status:** accepted, 2026-09-07

## Context

The project's entire premise (`BLUEPRINT.md` §0, §39, `docs/AGENTS.md` §0) is that every number a
user sees traces to a versioned rule and an official source, and can be reproduced years later.
Fiscal logic scattered across controllers, jobs, form requests, or React components (§2.3 gives
the literal forbidden example: a raw `if ($year === 2026 && $income > 24300)` in a controller)
cannot satisfy that: it is not centrally versioned, not independently testable against official
examples, and not reproducible once the surrounding code has moved on. An LLM producing a tax
figure directly would fail the same requirement for a different reason — it is not deterministic
and cannot be pinned to a ruleset version at all.

## Decision

1. **All fiscal logic lives in `Domain/TaxEngine` and nowhere else.** Controllers, form requests,
   jobs, Blade/React views, and repositories must not contain a tax rate, threshold, or
   conditional branching on a fiscal year or income category (§2.3, `docs/AGENTS.md` §6).
2. **Tax rules are as close to pure functions as the language allows** (§11.2): no HTTP calls, no
   Eloquent queries, no `now()`/global clock, no `env()` reads, no randomness, no cache dependency.
   Every input — facts, the date being assessed "as of", the ruleset version — is passed in
   explicitly through `TaxContext`. This is what makes historical replay possible: the same
   `TaxContext` and the same rule version must always produce the same `TaxAssessmentResult`.
3. **Every assessment produces a `CalculationTrace`** naming the rule code, rule version, inputs,
   the steps taken, and the `source_section_id`(s) the rule was built from (§11.4). The trace
   excludes PII not needed to justify the result.
4. **Money is never a float.** `Money` value objects over integer minor units (or `bcmath`/
   `ext-decimal` where the source data genuinely requires it), with an explicit rounding rule per
   computation (§11.3, `docs/AGENTS.md` §1.6).
5. The chain in §30 is mandatory and must be displayable end-to-end: financial/user data →
   `TaxFact` → rule implementation → `RuleVersion` → approved source section(s) → immutable source
   document hash. An AI model may read, classify, extract, or explain a result already produced by
   this chain (§15.5: RAG explains "why," never computes "what"); it may never be the origin of a
   figure that reaches a user as a computed tax amount.
6. A `TaxFact` an AI extraction pipeline proposes is `PROPOSED`, never `CONFIRMED`, until a human
   reviews it for high-impact fields (§14.6). Only `CONFIRMED` facts feed an assessment.

## Consequences

**Good:**
- A single, testable location for every fiscal computation means official ANAF numeric examples
  become golden tests that either pass or fail unambiguously (§21.2, CODE-QUALITY §5), and a
  historical regression suite can assert that changing 2026's rules does not silently change 2021's
  results (§21.3).
- Purity (no HTTP/Eloquent/clock/env/randomness) makes rules trivially unit-testable without a
  database or a mocked clock, and makes "what would this have computed on a different date" a
  first-class, safe operation instead of a hack.
- The calculation trace turns "why is my CASS X" from a support ticket into a UI feature (§38-F):
  the answer is mechanically derivable from data already produced at assessment time, not
  reconstructed after the fact by someone reading old code.
- Keeping AI strictly out of the computation path means a prompt-injection attack via a malicious
  uploaded document (§4.1, §14.5) can at worst corrupt a `PROPOSED` fact awaiting human review — it
  cannot fabricate a filed tax figure.

**Bad:**
- Purity has a real cost: every fact a rule needs must be fetched and assembled into a
  `TaxContext` *before* the engine runs, which pushes complexity into an orchestration layer that
  has to know, per rule version, what inputs it needs. That orchestration layer is itself a place
  bugs can hide (wrong fact fed in, right rule).
- A fiscal change that legitimately depends on "today's date" (e.g., a filing-deadline check) has
  to be modeled as an explicit `as_of` input rather than reached for via `now()`, which is more
  verbose at every call site.
- Strict `PROPOSED`→human-review gating for AI-extracted facts is a real throughput cost: it means
  no fully automated pipeline from "photo of a document" to "filed tax fact" in v1, by design
  (§14.6 allows relaxing this later only "on the basis of metrics" — not yet).
- The rule-version/source-section chain (§30) requires disciplined bookkeeping on every rule
  change: a rule implemented without a linked, approved `source_section_id` is a CI failure
  (`docs/AGENTS.md` §1.3, §9), which will occasionally block a rule that is *correct* but whose
  paperwork (source ingestion, human approval) has not caught up yet — an intentional bottleneck,
  not an oversight.

## Alternatives considered

- **Let an LLM produce the final figure, with a human spot-checking a sample.** Rejected outright
  — this is precisely the failure mode `docs/AGENTS.md` §0 exists to prevent. An LLM's output is
  not reproducible, not versionable against a `RuleVersion`, and not falsifiable against an
  official ANAF example in the same way deterministic code is.
- **Fiscal logic embedded per-endpoint (e.g., a `D212Controller` that computes as it renders).**
  Rejected — this is the literal anti-pattern named in §2.3; it cannot support historical replay,
  cannot be unit-tested in isolation, and duplicates logic across every entry point that needs the
  same figure (declarations, dashboards, RAG explanations).
- **Allow tax rules limited impurity (e.g., a rule may read `now()` for "is this still in force")**
  Rejected — §11.2 draws the line at zero exceptions; effective-dating is handled by the caller
  supplying `as_of` into `TaxContext`, not by the rule reaching for the clock itself. A single
  exception here erodes reproducibility for every rule, not just the one that took the shortcut.

## References

- `BLUEPRINT.md` §2.3 (forbidden pattern), §2.4 (financial event → tax fact → assessment chain),
  §11 (Tax Engine API, purity, `Money`, trace), §14.6 (AI-extracted facts stay `PROPOSED`), §15.5
  (RAG explains, never computes), §21.2–§21.3 (golden tests, historical regression), §30
  (source-of-truth chain), §38-F (explainability success criterion).
- `docs/AGENTS.md` §0, §1.4/§1.6, §6, §9 (fiscal work requirements).
- `docs/CODE-QUALITY.md` §5 (tests as part of the code, golden tests, no weakening CI).
