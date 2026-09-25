# ADR 0001 — Modular monolith, not microservices

**Status:** accepted, 2026-09-07

## Context

`BLUEPRINT.md` §2.1 mandates a set of domain modules inside a single Laravel application
(`Identity`, `Subjects`, `Wallet`, `Ledger`, `Documents`, `TaxKnowledge`, `TaxEngine`,
`Declarations`, `Spv`, `Notifications`, `Audit`) rather than a constellation of Laravel services.
Python exists as a separate runtime (§1.2), but that split is motivated by threat profile and
workload shape (untrusted document parsing, OCR, ML inference), not by a general preference for
service decomposition.

The project's real risk is not "the monolith won't scale" — a PF-first product with a bounded set
of tenants will not outgrow a well-indexed Postgres and a modular Laravel app for years. The real
risk is **accidental coupling**: a `Wallet` controller reaching into `Documents`' Eloquent models,
a `TaxEngine` rule querying `Subjects` directly, a `Declarations` job importing `Spv` internals.
Once that happens repeatedly, the codebase is a distributed system's liabilities (hidden coupling,
unclear ownership) without a distributed system's benefits (independent scaling, isolated
failure domains). Splitting into services early would not prevent this — it would just make the
same coupling mistake happen over a network, with worse debuggability.

## Decision

1. Laravel stays one deployable application (`apps/api`). Modules live under
   `apps/api/packages/tax-romania/src/<Module>/`.
2. A module **never** imports another module's Eloquent models directly. Cross-module
   communication happens only through:
   - DTOs (readonly value objects) passed as method arguments/return values,
   - contracts/interfaces bound in a service provider,
   - application services that sit above the domain layer and are allowed to know about more
     than one module,
   - domain events (`Kern\TaxRomania\<Module>\Events\...`) for anything that should be decoupled in
     time, not just in namespace.
3. This boundary is enforced **mechanically**, not by code review discipline: `deptrac` (see
   `docs/reference/verified-versions.md` — `deptrac/deptrac` 4.7.1; the abandoned
   `qossmic/deptrac-shim` fork must never be used) runs in CI with layer rules that forbid
   cross-module Eloquent imports. A PR that violates the boundary fails CI, it does not fail code
   review.
4. A module directory is created only when it has real code behind it (`docs/AGENTS.md` §6). No
   empty `Domain/Ledger/` scaffolded in Milestone 0 "for later."
5. Python services (`python-api`, `python-worker`, `spv-browser`) are separate deployables, not
   because of scale, but because they have a genuinely different threat and runtime profile:
   they parse untrusted files, run OCR/ML inference, and drive a real browser against a third-party
   portal. Laravel is the only plane with business-table write access; Python communicates with it
   through an internal authenticated API, signed job payloads, and pre-signed storage URLs — never
   direct DB access (§1.2, and see `docs/ARCHITECTURE.md`).

## Consequences

**Good:**
- One deploy pipeline, one migration history, one place to reason about a request's lifecycle for
  everything that isn't document/AI/browser work.
- Refactoring a module's internals is safe by construction — nothing outside it can be depending
  on its Eloquent models, because that would have failed CI.
- Domain events give a clean seam to extract a module into a real service later, if a specific
  module (not the whole app) turns out to need independent scaling or a different runtime.
- Matches the PF→PFA/SRL extensibility goal (`BLUEPRINT.md` §32–§33): a new module can be added
  alongside existing ones without touching their internals, because the contract is already the
  only thing they exposed.

**Bad:**
- `deptrac` rules are an added CI step and a genuine maintenance cost: every new module needs a
  layer definition, and a legitimately-needed new cross-module dependency requires updating the
  ruleset, not just writing code. This will occasionally block someone mid-task on a Friday.
- DTO/contract indirection has real overhead versus "just call the Eloquent model" — more files,
  more mapping code, more places a field rename has to propagate through.
- A monolith invites the temptation to reach for the shared `database` connection as an escape
  hatch from the module boundary (e.g. a raw query joining two modules' tables). This is not
  prevented by deptrac (which only sees PHP imports) and has to be caught in review.
- If a specific module genuinely needs independent scaling later (e.g. `Documents`/OCR triggering
  under Python instead — see ADR 0005), the migration path exists but is not free: it means turning
  an in-process contract into a network one, with all the versioning and latency questions that
  implies.

## Alternatives considered

- **Microservices per domain module.** Rejected: no current workload justifies independent
  scaling per module, and it would multiply operational surface (deploys, service discovery,
  network security, distributed tracing) that a PF-first MVP does not need. `BLUEPRINT.md` explicitly
  calls for a modular monolith, not a rewrite of this decision.
- **No enforced boundary, convention only ("please use DTOs").** Rejected: conventions erode
  under deadline pressure, and the failure mode (silent cross-tenant coupling) is exactly the kind
  of thing this project cannot afford to discover late. Mechanical enforcement via `deptrac` was
  chosen instead.
- **Split Python out further, e.g. one service per document type.** Rejected as premature: there
  is no evidence yet that OCR and structured extraction have different enough scaling/failure
  profiles to justify separate deployables. Revisit if/when `python-worker` becomes a bottleneck
  for one specific document type.

## References

- `BLUEPRINT.md` §2.1 (module list, DTO/contract boundary), §1.2 (Python as separate plane), §32–§33
  (extensibility requirements).
- `docs/AGENTS.md` §1.11 (no pointless abstraction), §6 (architecture rules, module creation
  rule), §10 (working with other agents).
- `docs/reference/verified-versions.md` — `deptrac/deptrac` 4.7.1 verified maintained;
  `qossmic/deptrac-shim` verified dead since 2022.
