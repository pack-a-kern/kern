# ADR 0018 — Chart.js for the report charts

**Status:** accepted
**Date:** 2026-10-02

## Context

The wallet reports screen was three bar lists built from `<div>`s with percentage widths. The
redesign asks for a twelve-month line chart with two series and a net mode, and five rings — a
category breakdown, a currency split, a data-quality dial and two smaller tiles. None of that is
expressible as a width on a div.

This repository had **no charting library and no chart of any kind**: not a canvas, not an SVG plot,
anywhere in `resources/js`. So the choice was open, and `docs/DEPENDENCY-POLICY.md` requires it to be
argued rather than assumed.

## Decision

Add **`chart.js` 4.5.1**, pinned exactly, as a dependency of `apps/api`.

Wrapped in one component, `resources/js/components/Chart.tsx`, which owns the canvas, destroys the
instance on unmount, and rebuilds when the theme changes. No screen talks to Chart.js directly;
screens hand the component a configuration.

## Alternatives considered

**Hand-rolled SVG.** Genuinely viable for the rings — a ring is two arcs — and that is how the month
bars were drawn before. It stops being viable at the line chart: curve interpolation, a shared
index tooltip across two series, axis ticks that thin themselves, and a legend are not a weekend of
SVG, and every one of them is a thing Chart.js has already got right. Writing that by hand is the
`RuleEngineFactoryBuilderManager` of charting.

**A React charting wrapper** (Recharts, visx). Heavier, and each pulls its own React reconciliation
model into the bundle. Chart.js draws to a canvas and needs ~60 lines of adapter, which is already
written and reviewable.

## Consequences

- One more dependency to keep current. It is widely used, has no runtime dependencies of its own,
  and is MIT.
- Chart.js resolves colours at configuration time and cannot read CSS variables, so every colour is
  read from the theme with `lib/themeColor.ts` and the chart is rebuilt when the theme attribute on
  `<html>` changes. Without that, a chart drawn in light mode stays light on a dark page.
- Charts are pictures of numbers that also appear as text on the same screen — the month table
  carries every figure the cash-flow chart plots. The canvas therefore gets `role="img"` and a
  label, rather than being made to describe itself.
