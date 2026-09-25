# ADR 0009 — TaxEngine reads TaxKnowledge's ruleset/parameter models directly

**Status:** superseded, 2026-09-08 — by
[ADR 0010](0010-tax-engine-ports-and-adapters.md). The dependency this ADR accepted as a
documented exception has been inverted: `App\Domain\TaxEngine` now defines
`Contracts\{TaxRuleVersionProvider,FiscalParameterProvider}` ports expressed purely in its own
DTOs/exceptions/enums, and `App\Domain\FiscalParameters\Providers\{EloquentTaxRuleVersionProvider,
EloquentFiscalParameterProvider}` implement them. `deptrac.php` no longer grants
`TaxEngine -> TaxKnowledge` at all; it grants `TaxKnowledge -> TaxEngine` instead. This document is
kept for the historical record of why the exception was originally accepted and what alternative
was already identified as the better long-term shape (see "Alternatives considered" below, which
ADR 0010 carries out).

## Context

`docs/AGENTS.md` §6 states a hard rule: "a module under `app/Domain/<Module>` never imports
another module's Eloquent models directly. Cross-module traffic goes through DTOs, contracts/
interfaces, application services, or domain events." `deptrac.php` enforces this at the layer
level for every module pair declared so far (`Fx` → `TaxKnowledge` crosses only through
`SourceIngestionService`/`OfficialSourceRepository`/`SourceDocumentVersionRepository`; `Wallet`/
`Documents` → `Subjects`/`Fx` cross only through `ScopedToTaxSubject`/`TenantContext`/
`FxRateQuery`).

Milestone 5 (`App\Domain\TaxEngine`) needs to resolve, for a given rule/parameter code and date,
the `tax_rule_versions`/`fiscal_parameter_versions` row in force — `docs/AGENTS.md` §9's
`CANDIDATE`-vs-`APPROVED` gate depends on reading that row's real `status`. Those tables belong to
`Kern\Legislation` (built in Milestone 3, `docs/handoff/018-legislation-ingestion.md`).
`TaxKnowledge` exposes a temporal-query application service for legal-text sections
(`Kern\Legislation\Services\LegislationTemporalQuery`) but **no equivalent service exists
yet for `tax_rule_versions`/`fiscal_parameter_versions`** — those models' own docblocks, written by
the Milestone 3 task, say outright "no `TaxRule` reads it yet (Milestone 5)", naming this module as
their first real reader without anticipating what shape that reading would take.

Milestone 5's task scope was restricted to `apps/api/app/Domain/TaxEngine/**` (plus three named
migrations, tests, factories, and `deptrac.php` grants) — it could not add the missing service to
`TaxKnowledge` without exceeding that scope.

## Decision

`App\Domain\TaxEngine\Repositories\TaxRuleTemporalReader` and `FiscalParameterTemporalReader` read
`Kern\Legislation\Models\{TaxRule,TaxRuleVersion,TaxRuleSource,FiscalParameter,
FiscalParameterVersion}` directly, and are the **only** two classes in the codebase permitted to
do so. `deptrac.php` grants `TaxEngine → TaxKnowledge` access at the layer level (deptrac does not
sub-divide a layer by class, so this grant is broader than the two classes that actually use it —
enforced by code review and this ADR, not by tooling, until a narrower deptrac mechanism exists).
Every other class in `App\Domain\TaxEngine` only ever sees the DTOs these two readers return
(`ResolvedTaxRuleVersion`, `ResolvedFiscalParameter`) — the cross-module leak is confined to
exactly these two files, not smeared across the module.

This mirrors `LegislationTemporalQuery`'s contract precisely (exactly one matching version for a
date, or a named exception — never a heuristic; `REJECTED`/`SUPERSEDED` excluded from the
candidate set entirely) so that migrating this logic into `TaxKnowledge` itself later (see
Consequences) is a mechanical move, not a redesign.

## Consequences

**Good:**
- Milestone 5 ships without inventing a second, narrower ruleset-storage mechanism duplicate to
  `TaxKnowledge`'s own tables, and without waiting on an out-of-scope change to a module this
  task does not own.
- The violation is small and named: two files, both already structured as the "read models,
  return DTOs" pattern every other cross-module boundary in this codebase uses internally to its
  *own* module (e.g. `TaxKnowledge\Services\SourceDocumentVersionRepository` does exactly this
  shape for its own models before handing a DTO to `Fx`).

**Bad:**
- `deptrac.php`'s `TaxEngine → TaxKnowledge` grant is layer-wide, not class-wide — it does not
  itself stop a *third* `TaxEngine` class from importing a `TaxKnowledge` model tomorrow. This ADR
  and `docs/handoff/020-tax-engine.md` are the only things naming the two-file confinement; a
  future agent must read both, not just run `deptrac analyse`, to know the actual boundary.
- Two similar-but-not-identical temporal-resolution implementations now exist
  (`LegislationTemporalQuery` in `TaxKnowledge`, `{TaxRule,FiscalParameter}TemporalReader` in
  `TaxEngine`) rather than one shared implementation — acceptable stable-domain-knowledge
  duplication for now (`docs/CODE-QUALITY.md` §1: the "resolve one version in force on a date"
  shape is genuinely the same pattern, but the two are read by different modules with different
  DTO output needs, so extracting a shared base felt premature ahead of a second real consumer).

## Alternatives considered

- **Add a `TaxRuleTemporalQuery`/`FiscalParameterTemporalQuery` service to `TaxKnowledge` in this
  same task, mirroring `LegislationTemporalQuery`.** Rejected only because it was outside this
  task's stated path scope (`apps/api/app/Domain/TaxEngine/**`), not because it is architecturally
  worse — it is very likely the *better* long-term shape, and is recorded as an open question in
  `docs/handoff/020-tax-engine.md` for whoever next touches `TaxKnowledge`. This ADR should be
  superseded (not merely left inconsistent) once that service exists and `TaxEngine`'s two readers
  are migrated to depend on it instead of the raw models.
- **Duplicate the ruleset data into a `TaxEngine`-owned table.** Rejected outright — this would
  violate `docs/AGENTS.md` rule 17 in spirit (a second, divergent copy of the one authoritative
  ruleset store) and reintroduce exactly the "duplication of stable domain knowledge"
  `docs/CODE-QUALITY.md` §1 forbids, for no benefit over reading the real table.

## References

- `docs/AGENTS.md` §6 (the rule this ADR documents an exception to), §9 (the approval gate this
  exception exists to serve).
- `docs/handoff/018-legislation-ingestion.md`, `docs/handoff/020-tax-engine.md` ("Key decisions"
  #3, "Open questions").
- `Kern\Legislation\Services\LegislationTemporalQuery` — the contract mirrored.
