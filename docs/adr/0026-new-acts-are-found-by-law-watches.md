# ADR 0026 — New acts are found by law watches

**Status:** accepted
**Date:** 2026-11-09
**Concerns:** `kern/legislation` (the watches), `kern/tax-romania` and `kern/investments` (what they watch for), the laws screen

## Context

A law already read is kept up to date by the weekly sync, which reads its new consolidations. A **new
act** is another matter: each year's minimum-wage decision is a decision of its own, not an amendment of
the last, and nothing looked for it — someone had to find it in "Add a law", or the CASS bands went on
being computed on last year's wage. The bond import of ADR 0025 needed the same thing for the Ministry of
Finance's orders, and was about to search for them in a loop of its own.

## Decision

**A law watch is a search of the portal run again on its own clock, each new act it finds read as a
law.** They live in `ext_law_watches` (`kern/legislation`); the hourly task `legislation.watch` runs those
due (`next_run_at`), each:

- its first clean run searching every year from its first, each later one this year and, early in the
  year, the last;
- an act already in the library skipped, a new one read as a law the way "Add a law" reads one — whoever
  watches for it reads what it needs on `LawConsolidationsRead`, as before;
- under a lock of its own, failing alone: tried again the next hour, its reason its last result.

**Two kinds, one table.**

- **A package's watch** is declared in that package's configuration (`config/watches.php`, every value
  from its environment, no default) and added to `legislation.watches.declared` from its provider. Each
  run mirrors the declarations by key — new added, changed updated, gone removed, a pause made on the
  screen kept. `kern/tax-romania` declares the minimum-wage decisions; `kern/investments` the Fidelis and
  Tezaur orders. `kern/legislation` names none of them.
- **A person's watch** is kept from the "Add a law" dialog — "Watch this search" — for the words and type
  searched, every year from the one chosen, at an interval `LEGISLATION_WATCHES_INTERVALS` offers. It keeps
  every act its search finds. Changed, paused or deleted on the laws screen; audited; gone with the
  account. A package's watch can be paused there, never deleted or re-timed.

## Consequences

- A new minimum-wage decision is read without anyone adding it; the wage is still only proposed until a
  person approves it.
- `kern/investments` lost its own order search (`OrderSearch`); its weekly task keeps the Ministry's
  subscription lists and a sweep of orders already in the library but never read for their issues.
- An hour with no watch due costs one indexed query.
- The README of `kern/legislation` shows how any package declares a watch.

## Not done

- A pattern for a person's watch: it keeps every act its search finds. A narrower one is written by a
  package, where it is reviewed.
