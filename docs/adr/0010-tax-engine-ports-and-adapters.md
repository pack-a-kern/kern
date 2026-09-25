# ADR 0010 — TaxEngine defines ports; TaxKnowledge implements them

**Status:** accepted, 2026-09-08
**Supersedes:** [ADR 0009](0009-tax-engine-reads-tax-knowledge-models-directly.md)

## Context

ADR 0009 accepted a documented, narrowly-scoped exception: `Kern\TaxRomania\TaxEngine\Repositories\
{TaxRuleTemporalReader,FiscalParameterTemporalReader}` read `Kern\Legislation\Models\
{TaxRule,TaxRuleVersion,TaxRuleSource,FiscalParameter,FiscalParameterVersion}` Eloquent models
directly, and `deptrac.php` granted `TaxEngine -> TaxKnowledge` at the layer level to allow it.

`BLUEPRINT.md` §2.1 is explicit: "Modulele nu trebuie să importe direct modele Eloquent din alte
module" — cross-module traffic goes through DTOs, contracts/interfaces, application services, or
domain events. ADR 0009 itself named the two weaknesses of the exception it accepted:

- `deptrac.php`'s grant is layer-wide, not class-wide — it does not stop a *third* `TaxEngine`
  class from importing a `TaxKnowledge` model tomorrow. Only the ADR and
  `docs/handoff/020-tax-engine.md` named the two-file confinement; nothing enforced it but review.
- The better long-term shape was already identified and explicitly deferred, only because adding
  it was outside that task's path scope (`apps/api/packages/tax-romania/src/TaxEngine/**` only): "Add a
  `TaxRuleTemporalQuery`/`FiscalParameterTemporalQuery` service to `TaxKnowledge`... mirroring
  `LegislationTemporalQuery`."

This task's scope explicitly includes both `apps/api/packages/tax-romania/src/TaxEngine/**` and
`apps/api/packages/legislation/src/**` (additive), so the reason ADR 0009 gave for not doing this
the first time no longer applies. A cleaner option is available and is not blocked by scope: invert
the dependency.

## Decision

`Kern\TaxRomania\TaxEngine` now defines two port interfaces, expressed **purely in its own DTOs,
exceptions, and enums** — never a TaxKnowledge type:

- `Kern\TaxRomania\TaxEngine\Contracts\TaxRuleVersionProvider::versionInForceOn(string $ruleCode,
  DateTimeImmutable $date): ResolvedTaxRuleVersion`
- `Kern\TaxRomania\TaxEngine\Contracts\FiscalParameterProvider::versionInForceOn(string $parameterCode,
  DateTimeImmutable $date): ResolvedFiscalParameter`

Both `ResolvedTaxRuleVersion::$status` and `ResolvedFiscalParameter::$status`/`$valueType` are now
typed to **new, TaxEngine-owned enums** — `Kern\TaxRomania\TaxEngine\Enums\{ApprovalStatus,
FiscalParameterValueType}` — rather than `Kern\Legislation\Enums\{ApprovalStatus,
FiscalParameterValueType}`. This is deliberate, not an oversight: a port whose DTO still names a
TaxKnowledge enum in its public signature would leak the boundary this ADR exists to close, just
through an enum instead of a model. The two enum pairs are identical in case names and backing
string values by design (a stable domain concept, `docs/CODE-QUALITY.md` §1) — they are two
independent declarations of it, not a shared reuse, because a TaxEngine port must not import
anything from TaxKnowledge.

`Kern\TaxRomania\FiscalParameters\Providers\{EloquentTaxRuleVersionProvider,EloquentFiscalParameterProvider}`
implement these ports. They are behaviourally identical to the two classes ADR 0009 named — same
"exactly one matching version for a date, or a named exception, never a heuristic" contract, same
exclusion of `REJECTED`/`SUPERSEDED` from the candidate set — but now live inside `TaxKnowledge`,
read its own Eloquent models (legitimate: a module reading its own models is not the violation
`BLUEPRINT.md` §2.1 names), map its enums to TaxEngine's at the boundary
(`EngineApprovalStatus::from($tkStatus->value)`), and throw TaxEngine's own exceptions.

`Kern\Legislation\TaxKnowledgeServiceProvider` binds both ports to these adapters. This is
the one place the inversion is wired: `TaxKnowledgeServiceProvider` imports `Kern\TaxRomania\TaxEngine`
contracts, so **`TaxKnowledge` now depends on `TaxEngine`, not the other way around.**

`deptrac.php` reflects this exactly:

```php
Ruleset::forLayer($taxKnowledge)->accesses($support, $taxEngine),   // new
Ruleset::forLayer($taxEngine)->accesses($support, $subjects, $fx),  // TaxKnowledge grant removed
```

`RuleResolver`/`ParameterResolver` now depend on the `TaxRuleVersionProvider`/
`FiscalParameterProvider` interfaces, never the concrete adapter. Every other class in
`Kern\TaxRomania\TaxEngine` is unchanged — the module's own public interface
(`TaxContext`/`TaxAssessor`/`TaxAssessmentRecorder`) has not moved.

## Consequences

**Good:**
- `TaxEngine -> TaxKnowledge` no longer exists at all, at any granularity — deptrac now enforces
  the full boundary, not just the two-file confinement a human had to remember to preserve.
- The temporal-resolution *semantics* (exactly one match or a named exception; `REJECTED`/
  `SUPERSEDED` excluded; `CANDIDATE`-vs-`FINAL` gate) are untouched — this is a pure structural
  move. Every existing test asserting those semantics still asserts them, unchanged, against the
  relocated/renamed class.
- `Kern\TaxRomania\TaxEngine` is now, in the literal sense `BLUEPRINT.md` §11.2 asks for, importable and
  testable with zero knowledge of `TaxKnowledge`'s existence: a test double implementing
  `TaxRuleVersionProvider` is enough to exercise `RuleResolver`/`TaxAssessor` without a database at
  all (not exploited by this task — the existing feature tests keep using the real adapter, to
  avoid changing what is under test — but the seam now exists for anyone who wants a pure unit
  test later).
- The general shape (a consuming module owns the port; a data-owning module implements it) is
  reusable for the next case where a module needs read access to another module's versioned store
  without importing its models.

**Bad:**
- Two structurally-identical enum pairs now exist (`TaxEngine`'s and `TaxKnowledge`'s
  `ApprovalStatus`; `TaxEngine`'s and `TaxKnowledge`'s `FiscalParameterValueType`), and the two
  adapters are the only code that must keep them in sync (`::from($other->value)` throws
  `ValueError` at the adapter boundary if a case is ever added to one enum and not the other —
  this is treated as an acceptable, self-detecting cost of the boundary, not silently risky).
- `TaxKnowledge` now has an outbound dependency it did not have before (`TaxEngine`). This is a
  straightforward trade against ADR 0009's problem, not a free lunch: whichever module defines a
  port becomes the one the other must not regress independently of. Here that is `TaxEngine`,
  which is the more natural anchor — `TaxKnowledge` is closer to raw data (`Fx` already depends on
  it the same way), and `TaxEngine`'s own contract (`BLUEPRINT.md` §11) already forbids it from
  reaching for a database directly, which a port-based design reinforces rather than fights.

## Alternatives considered

- **Leave ADR 0009's exception in place.** Rejected — this task exists specifically to close it,
  and the better shape ADR 0009 already named as the likely long-term answer was not blocked by
  anything but scope, which this task's scope does not share.
- **Add a `TaxRuleTemporalQuery`/`FiscalParameterTemporalQuery` *application service* to
  `TaxKnowledge` (mirroring `LegislationTemporalQuery`) and have `TaxEngine` call it directly,
  without an interface in `TaxEngine`.** This was ADR 0009's own suggested next step. Rejected in
  favour of an explicit port-in-TaxEngine because an application service alone does not, by
  itself, stop `TaxEngine` from also depending on `TaxKnowledge`'s enums/other services — the
  deptrac grant would still have to run `TaxEngine -> TaxKnowledge`, just narrower. A port defined
  in `TaxEngine`'s own namespace makes the direction structurally impossible to get backwards
  again by accident: nothing in `TaxKnowledge` can be imported by `TaxEngine` without deptrac
  failing, full stop, because no grant exists at all.
- **Keep `ResolvedTaxRuleVersion`/`ResolvedFiscalParameter` typed to `TaxKnowledge`'s enums, only
  move the two reader classes.** Rejected — this would still require a `TaxEngine -> TaxKnowledge`
  deptrac grant (for the enum imports alone) and would defeat the point: the DTOs are TaxEngine's
  own public contract, and a port is not really TaxEngine's own abstraction if its return type
  still names another module's type.
- **Duplicate the ruleset data into a TaxEngine-owned table.** Rejected outright, same reasoning
  ADR 0009 already gave: a second, divergent copy of the one authoritative ruleset store violates
  `docs/AGENTS.md` rule 17 in spirit and `docs/CODE-QUALITY.md` §1's duplication rule, for no
  benefit over reading the real table through a port.

## References

- `docs/adr/0009-tax-engine-reads-tax-knowledge-models-directly.md` — the exception this ADR
  closes, including its own "Alternatives considered" section, which named this exact direction.
- `BLUEPRINT.md` §2.1 (the rule restored to full compliance), §11.2 (rule purity — this ADR makes the
  isolation testable at the port level, not merely enforced by a source-text scanner for the rule
  classes themselves).
- `docs/AGENTS.md` §6 (module boundary rule, `deptrac` enforcement).
- `docs/handoff/020-tax-engine.md` (the original implementation this ADR restructures),
  `docs/handoff/023-taxengine-boundary-inversion.md` (this change's own handoff).
