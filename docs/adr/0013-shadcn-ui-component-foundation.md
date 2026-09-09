# ADR 0013 — shadcn/ui as the component foundation, with our own design tokens

**Status:** accepted, 2026-09-08

## Context

`BLUEPRINT.md` §1.3 calls for "a component library accessible, but without lock-in excessive" on top
of React + TypeScript + Tailwind CSS, and the PWA frontend (`docs/handoff/030-pwa-frontend.md`,
`docs/handoff/033-pwa-wiring.md`) had, until now, six screens of hand-written Tailwind with no
shared component vocabulary, no dark theme, and no accessibility pattern beyond what each page
happened to get right on its own. Roughly twenty more screens are coming. The choice made here is
the one every one of those screens will inherit.

## Options considered

**Mantine.** A complete, opinionated React component library with its own theming API and CSS
engine (Emotion under Tailwind, or its own CSS-in-JS). Rejected: it does not compose with Tailwind
utility classes at all — adopting it means either running two styling systems side by side
indefinitely or migrating the existing Tailwind-based markup (six screens, growing) off Tailwind
entirely. It also ships as an opaque `node_modules` dependency: a novel Mantine-specific bug or
missing prop is a wait for upstream, not a five-minute local fix.

**Hand-rolled components, no library.** Consistent with this codebase's stated preference for
hand-written code over a dependency when the standard library/existing tools suffice
(`docs/DEPENDENCY-POLICY.md` §4 — already the reasoning behind the hand-written service worker,
the hand-written runtime-validation library, and now the hand-written i18n in
`docs/handoff/040-ui-foundation.md`). Rejected here specifically: unlike a service worker or a
validation combinator, accessible interactive primitives — a dialog that traps focus and restores
it on close, a select that is keyboard-operable and screen-reader-correct, a dropdown menu with
proper `aria-*` wiring — are *hard to get right* and *easy to silently get wrong*. Radix UI (which
shadcn is built on) has already solved exactly this problem, correctly, for the eleven primitives
this task needed (dialog, sheet, dropdown-menu, select, checkbox, radio-group, tabs, tooltip,
avatar, popover, label). Re-deriving that from scratch is the "premature abstraction is more
expensive than the duplication it removes" mistake in reverse: it is *reinventing already-solved,
security/accessibility-relevant infrastructure*, not avoiding an unnecessary abstraction.

**shadcn/ui.** Not a component library in the Mantine sense — its CLI (`npx shadcn add <component>`)
copies component *source* into `resources/js/components/ui/**`, built on Radix primitives, styled
with Tailwind utility classes, merged via a `cn()` helper. Nothing is imported from `node_modules`
at runtime except Radix itself and small headless utilities (`class-variance-authority`, `cn`).
Chosen for exactly the reason `BLUEPRINT.md` §1.3 asks for: it composes natively with the Tailwind v4
setup already in place, and "lock-in" is minimal because the code is *ours* the moment it is
generated — a shadcn-specific bug is fixed by editing a file in this repository, not by waiting on
a maintainer or forking a package.

## The honest downside, stated plainly

shadcn's default look is extremely recognisable. Its "new-york"/"default" styles, Geist typography,
and Tailwind-default zinc/slate palette have become the visual signature of a huge fraction of 2024–
2026 AI-adjacent products — the thing this task's own brief calls "the default AI-app look." Adopting
shadcn and stopping there would have made TaxOS România look like a demo, not a fiscal authority's
neighbour. This is the entire reason `docs/handoff/040-ui-foundation.md`'s design-token section
exists: every default colour, the default font, the default border radius, and even the CLI's own
runtime CSS package were deliberately replaced (see that handoff's "Key decisions" for exactly what
was kept and what was overridden, and why).

## Decision

Use shadcn/ui's CLI (pinned exact — `shadcn@4.21.0`, a devDependency, not a runtime one) to generate
component source for the 23 primitives this task's screens need, built on the `radix-ui` /
`class-variance-authority` / `cn` trio the current registry uses, into `resources/js/components/
ui/**`. Immediately replace every default design-token value with TaxOS România's own palette,
typography, and radius scale (`resources/css/app.css`), and drop the generated runtime `shadcn`
package import and its "Nova" preset entirely — kept only the ~40-line Radix state-attribute
`@custom-variant` block the generated components depend on, copied rather than imported wholesale
(`docs/CODE-QUALITY.md` §2 — the rest of that package's stylesheet is shimmer/scroll-fade utilities
this product never uses). Full reasoning for each of these calls is in
`docs/handoff/040-ui-foundation.md`.

## Consequences

**Good**
- Twenty future screens get real accessibility (focus trapping, keyboard operability, ARIA wiring)
  for free from Radix, not re-derived per screen.
- The component source lives in this repository; a bug or a missing variant is a local edit.
- The visual identity is TaxOS România's own, not shadcn's out of the box — verified by rewriting
  every colour/font/radius token before converting a single page.

**Bad / accepted**
- The shadcn CLI's current ("universal registry") architecture assumes a runtime `shadcn` npm
  package supplying base CSS; working around that (copying instead of importing) is friction every
  future `shadcn add` invocation must repeat by hand — documented step by step in
  `docs/handoff/040-ui-foundation.md` so it is not rediscovered.
- `radix-ui` (the consolidated meta-package) and `cn` are both comparatively new publishing
  identities for their respective teams (checked directly, not assumed — see the handoff's
  dependency table); they are the current, actively-maintained, correctly-licensed choice as of
  2026-09-08, but "current" here means current within a fast-moving registry, not a decade-old
  stable API.
- The client JS bundle grew from ~450 KB to ~700 KB gzip after adding 23 components,
  `react-day-picker`, and `date-fns` — noted as a follow-up (route-level code-splitting) in the
  handoff's "What is NOT done," not solved in this task.
