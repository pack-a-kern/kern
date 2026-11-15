# 057 — Packages declare the legislation they require (readiness)

**Milestone:** (outside the milestones) **Status:** complete — ADR 0027 steps 1–6 built (declarations, registry, query, endpoint, the global gate, the setup screen). Per-feature client gating is the only remaining follow-up.
**Date:** 2026-11-14 (gate, screen and Tezaur added 2026-11-15)

## What was built

ADR 0027, backend. A package declares the legislation it needs to operate, classified by tier, in
its own configuration; `kern/legislation` reads those declarations and answers one question — what is
ready — over an endpoint. Nothing enforces readiness yet; the endpoint is what the setup screen will
read.

- **Declarations** (`config/legislation_requirements.php`, merged into
  `legislation.requirements.declared` from each provider, exactly as watches are — ADR 0026):
  - `kern/tax-romania`: `tax-romania.cod-fiscal` (**required**) — Cod Fiscal present and in force
    (`law` → `FISCAL_PARAMETERS_LAW`, the same identifier the reader uses) **and** the minimum wage
    approved for the active year (`parameters` → `active-year`).
  - `kern/investments`: `investments.bonds-fidelis` and `investments.bonds-tezaur` (both **feature**
    `investments.bonds`) — the Fidelis watch and the Tezaur watch have each read at least one order.
    Two requirements naming one feature; the roll-up ANDs them, so bonds light up only once both
    programmes' orders are in the library (each programme's holding is priced from its own order).
- **Registry + resolution + query + endpoint** (`kern/legislation`, namespace `Requirements/`):
  `LegislationRequirements` reads the merged config into typed `RequirementDefinition`s;
  `NeedResolvers` is a closure registry (like `DocumentDependents`) that each package fills for the
  kinds it owns; `ReadinessReport` resolves every need, rolls up `is_bootstrapped` (every `required`
  met) and per-feature `available`; `GET /api/v1/setup/requirements` returns it.
- **Resolvers:** `kern/legislation` owns `law` (a `SourceDocument` under the identifier with a dated
  version in force now) and `watch` (the declared watch has read ≥1 law) — `LegislationNeeds`.
  `kern/tax-romania` owns `parameters` — `FiscalParameterReadiness`, the minimum wage approved
  (through the sanctioned path) and in force for `FiscalYears::current()`. So `kern/legislation` never
  imports a tax model; the dependency direction (platform ← legislation ← tax-romania/investments)
  holds.

## Files created / changed

- `packages/legislation/src/Requirements/{RequirementTier,RequirementNeed,RequirementDefinition,LegislationRequirements,NeedResolvers,ReadinessReport,LegislationNeeds}.php` — the registry, DTOs, resolver registry, readiness query, and this package's own `law`/`watch` checks.
- `packages/legislation/src/Http/Controllers/Setup/ShowRequirementsController.php` + `routes/api.php` — the endpoint, `auth:web`, outside the tenant group (install state, not a tenant's data).
- `packages/legislation/src/LegislationServiceProvider.php` — binds the two singletons, registers `law`/`watch`.
- `packages/tax-romania/config/legislation_requirements.php`, `src/FiscalParameters/Requirements/FiscalParameterReadiness.php`, `src/TaxRomaniaServiceProvider.php` — the required requirement, the `parameters` check, the merge and the resolver registration.
- `packages/investments/config/legislation_requirements.php`, `src/InvestmentsServiceProvider.php` — the two bonds-feature requirements (Fidelis + Tezaur) and their merge.
- **The gate** — `apps/api/app/Http/Middleware/RequireBootstrapped.php`, registered in `apps/api/bootstrap/app.php`'s `web(append:)` after `RequireVerifiedEmail`. Holds authenticated browser navigation on the setup checklist until `is_bootstrapped`; never touches the JSON API or the allowed-set screens (so bootstrapping cannot deadlock).
- **The screen** — `apps/api/resources/js/pages/setup/index.tsx` (route `setup` in `routes/web.php`), `api/setupApi.ts`, `types/setup.ts`, `i18n` (`dictionary.ts`/`en.ts`/`ro.ts` `setup` block + `nav.setup`), and a nav entry in `components/AppShell.tsx` (admin group, `system.settings.view`). The frontend lives in `apps/api`, not `kern/platform` — this install has no package-side UI.
- `packages/legislation/tests/Integration/RequirementsReadinessTest.php`, `packages/tax-romania/tests/Integration/CodFiscalRequirementTest.php`, `packages/investments/tests/Integration/InstallsBesideLegislationTest.php` (bonds-declaration test), `apps/api/tests/Feature/Setup/RequireBootstrappedTest.php` (the gate) — the suites.
- `docs/adr/0027-...md` — accepted; the gate lives in `apps/api` and holds navigation only (never the API); the screen is in `apps/api`; bonds gate on both programmes.

## Key decisions

- **Cross-package resolution by closure registry**, not model imports — mirrors `DocumentDependents`
  (handoff 056 area / ADR 0027). Keeps the module boundary; deptrac clean.
- **`parameters: active-year` = the minimum wage approved for the active year**, the one baseline, not
  every rule. The user chose this; other rules gate their own features. See ADR 0027.
- **The global gate belongs in `apps/api`, not `kern/platform`** — platform is the base layer and
  cannot read a legislation query. The ADR said platform; corrected there.
- **An approved wage is reached only through `approveByPerson()`** in tests, never a forced status —
  `ForbidsAgentApproval` (AGENTS §9).

## How to verify

Package suites run against a dedicated Postgres database (never the dev DB — `RefreshDatabase` targets
`KERN_PACKAGE_TEST_DATABASE`). On this machine:

```
# legislation
vendor/bin/pest tests/Integration/RequirementsReadinessTest.php
# → 9 passed (24 assertions)
# tax-romania
vendor/bin/pest tests/Integration/CodFiscalRequirementTest.php
# → 6 passed (13 assertions)
```

Quality (run from `apps/api` in the running `anaf-app` container):
`vendor/bin/pint --test` PASS · `phpstan analyse` (level 9) no errors · `deptrac analyse` 0 violations.

## Interfaces exposed to other modules

- `GET /api/v1/setup/requirements` → `{ is_bootstrapped: bool, requirements: [{key, declared_by, tier, feature, met, needs: [{kind, argument, met}]}], features: { <feature>: { available: bool } } }`.
- `Kern\Legislation\Requirements\NeedResolvers::register(string $kind, Closure(string): bool)` — a
  package adds a resolver for a kind it owns, from its provider.
- `Kern\Legislation\Requirements\ReadinessReport` (invokable) — the payload above, for a gate or screen.

## What is NOT done

- **Per-feature client gating.** The global gate and the setup screen are built. A product screen
  reading its own `available` flag — the bonds screen showing "needs setup" with a link while shares
  stay open — is not yet wired. The endpoint already returns `features.<key>.available`, so this is a
  client-only follow-up with no backend change.
- **No caching on the gate.** It runs the readiness query (a handful of indexed reads) on each held
  browser navigation, by design — approving the wage lifts the wall on the next navigation with no
  stale window. If that read ever matters, cache with explicit invalidation; it is localized to
  `RequireBootstrapped`.

## Open questions

- Per-feature gating (above) — which screens read `available`, and the "needs setup" copy and link.
- The gate + screen are a paired follow-up; who owns the screen's copy and states (ADR 0027 leaves it
  to `kern/platform`).

## Sources consulted

- `docs/adr/0027-packages-declare-the-legislation-they-require.md`, ADR 0026 (watches), ADR 0017 (env).
- `docs/AGENTS.md` §6 (module boundaries), §9 (no agent sets APPROVED).
