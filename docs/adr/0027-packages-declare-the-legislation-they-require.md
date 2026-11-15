# ADR 0027 — Packages declare the legislation they require, and the app gates until it is met

**Status:** accepted
**Date:** 2026-11-14 (accepted 2026-11-15, built)
**Concerns:** `kern/legislation` (the requirements registry, the need resolvers for `law`/`watch`, the readiness query and its endpoint), `kern/tax-romania` and `kern/investments` (what each declares it needs; tax-romania also resolves `parameters`), `apps/api` (the global bootstrap gate), `kern/platform` (the setup screen)

## Context

A fresh install knows how to *find* laws — ADR 0026 gave packages their watches — but nothing says which
laws and which values a package must have before it can do its work at all. The gap shows at first run and
after: press **read a year** before Cod Fiscal is in the library and there is nothing to parse — the
reader looks the law up by `SourceDocument.external_identifier` = `config('fiscal_parameters.law')`
(`FiscalParameterBoard`, `LawProvisions`) and finds nothing; open a tax screen before the year's values
are approved and it has no numbers to show. Cod Fiscal is not watched (it is one stable law, added once
in "Add a law"), so the one step that unblocks the whole application is a manual step nobody is told to
take.

Today a package declares what it *watches* (`legislation.watches.declared`) and what values it *reads*
(`fiscal_parameters`), but neither carries whether the thing is **required to operate**, needed only **by
one feature**, or merely **nice to have**. So the app cannot tell a person what is missing, cannot stop
them walking into a blank screen, and cannot tell "the install is not set up yet" apart from "this one
feature is not available."

## Decision

**A package declares the legislation it requires, in its own configuration, classified by how much rests
on it. The application reads those declarations to answer one question — what is ready — and gates on the
answer.**

### Declared in config, merged from the provider — as watches are

Each package ships `config/legislation_requirements.php` and merges it into
`legislation.requirements.declared` from its service provider, exactly as ADR 0026 does for watches and
ADR 0017 requires for environment — every value from the environment, **no default in code**. A
requirement names itself by a key in the package's own namespace, and its `needs` point only at things
that already exist: a law by the identifier the reader already uses, a parameter by its catalog code.

```php
// tax-romania/config/legislation_requirements.php
return [
    'tax-romania.cod-fiscal' => [
        'declared_by' => 'kern/tax-romania',               // named here as a watch is, for the message a failure prints
        'tier'        => 'required',
        'needs' => [
            'law'        => env('FISCAL_PARAMETERS_LAW'),   // SourceDocument.external_identifier, e.g. LEGE 227/2015
            'parameters' => 'active-year',                  // ...and the minimum wage approved for the active year
        ],
    ],
];

// investments/config/legislation_requirements.php — the bond orders of ADR 0026
return [
    'investments.bonds' => [
        'declared_by' => 'kern/investments',
        'tier'        => 'feature',
        'feature'     => 'investments.bonds',
        'needs'       => ['watch' => 'investments.fidelis'], // the Ministry's orders must be in the library
    ],
];
```

`kern/legislation` declares none of them; it only reads what the other packages merge, the way it owns the
watch machinery but names no watch.

### Three tiers

- **required** — the application is not usable without it. Cod Fiscal present *and* the active year's
  values approved is the one baseline. Unmet ⇒ the install is not bootstrapped.
- **feature** — one feature rests on it; the rest of the app does not. Bonds rest on the Fidelis *and*
  the Tezaur orders being in the library — declared as two requirements naming the same
  `investments.bonds` feature, ANDed by the roll-up, because a holding of either programme is priced
  from its own order (`TreasuryOrders`); shares do not. Unmet ⇒ that feature is unavailable, nothing else.
- **optional** — better with it, correct without it. Unmet ⇒ a note, never a block.

### A need is checked, not assumed — and its parts fail apart

A requirement is met only when every part of its `needs` is. Each part is resolved by the lookup that
already exists, so the readiness query adds no new notion of "present":

- `law` → a `SourceDocument` with that `external_identifier` exists and is consolidated for the active year.
- `parameters: active-year` → the **minimum wage** has an approved `FiscalParameterVersion` in force
  for the active year. It is the value every threshold is a multiple of, so nothing a tax is worked
  out from can be computed until it is in force; the other rules gate their own features, not the
  install. A `needs` is a map of kind → argument, so `parameters` takes one argument, `active-year`;
  any other argument is a declaration error and fails loudly.
- `watch` → the watch's acts are in the library (at least its first expected act read).

The parts are reported one by one, so the setup screen can say *which* step a person is on — "① Add Cod
Fiscal — not done" against "① done, ② read & approve this year's values — not done" — instead of one
opaque "not ready."

### One registry, one query, one endpoint — built once

- **`LegislationRequirements`** (a `kern/legislation` singleton, as `SectionDependents` is) reads the
  merged config into typed definitions, as `ParameterCatalog` reads `fiscal_parameters`.
- **A readiness query** resolves every requirement to its parts' statuses and rolls them up to
  `is_bootstrapped` (every `required` met) and, per feature key, `available`.
- **`GET /api/v1/setup/requirements`** returns the checklist and those flags. Nothing computes in the
  screen (UI-PATTERNS): the server says what is ready and what the next step is.

### The gate: global only until bootstrapped, then per feature

- **Until bootstrapped**, a middleware (`RequireBootstrapped`) routes the authenticated person to the
  setup checklist and holds them out of the rest — the true first-install wall, and the only global
  one. It lives in the application's own HTTP layer (`apps/api`), not `kern/platform`: platform is the
  base package and cannot depend on `kern/legislation` to read the readiness query, whereas the
  application composes every package and already hosts cross-cutting middleware
  (`BindTaxSubjectContext`).
- **It holds browser navigation only; it never walls the JSON API.** The install is made ready by
  approving the minimum wage (`/taxes/rules`) and reading Cod Fiscal into the library (`/legislation`),
  and both act through `/api/v1/**`. A gate that answered those calls with an error until the wage was
  approved would make the wage impossible to approve — the gate would deadlock the state it waits for.
  So only the screens that cannot work without fiscal data are held; the requests that create it, and
  the screens that drive them (on the gate's allowed set, matched by route name), stay open. The setup
  *screen* is a `kern/platform` concern in the ADR; it is built in `apps/api` beside the other
  product screens, which is where this install's React/Inertia screens live.
- **Once bootstrapped**, the global wall is gone. A feature gates itself on its own `available` flag read
  from the same payload: the bonds screen shows "needs setup" with a link while shares stay open. A
  feature may be gated by more than one requirement — availability is every one of them met.
- **optional** never gates; it shows a note.

## Consequences

- The manual "add Cod Fiscal, read the year" step is no longer folklore — a fresh install is told it, in
  order, and cannot skip past it into blank screens.
- Adding a need is one declarative entry in the package that has it, beside the watch or parameter it
  already declares; the registry, the query, the endpoint and the gate are never touched again.
- "The install is not set up" and "this feature is not available" are answered from the same data and no
  longer confused.
- A package that declares a `required` law it cannot actually satisfy would wall the whole app — the
  tier is a claim the package owns, reviewed as config is.
- The gate runs the readiness query on each held browser navigation (a handful of indexed reads); it is
  not cached, so approving the wage lifts the wall on the very next navigation with no stale window. The
  JSON short-circuit and the allowed-route check keep it off the API and off the bootstrap screens. If
  the per-navigation read ever matters, caching with explicit invalidation is a localized change here.

## Not done

- No requirement spans two laws with a condition between them; a `needs` is a flat list of parts, all of
  which must hold.
- The **global** gate and the setup checklist screen are built (`RequireBootstrapped`,
  `resources/js/pages/setup`). The **per-feature** client gating — a bonds screen that reads its
  `available` flag and shows "needs setup" with a link while shares stay open — is not yet wired; the
  endpoint already carries the flags it will read.
- Scheduling: the readiness query answers for the active year only; a requirement does not express "ready
  for next year too."
