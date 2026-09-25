# 040 — UI foundation: shadcn/ui, design tokens, app shell, i18n, and the full navigation

**Milestone:** cross-cutting (frontend foundation for all future screens)
**Status:** complete for the six existing screens + the full navigation/placeholder pass;
partial for i18n coverage (see "What is NOT done")
**Date:** 2026-09-08

## What was built

The shadcn/ui component foundation the task brief asked for, **plus** a scope extension the
product owner added mid-task: a full app shell with every planned menu item reachable (real screen
or an honest placeholder), and a minimal i18n system (RO/EN) with no hardcoded user-visible
strings in any new or converted code. Both are described here together since the shell and the
i18n system were built as one piece from the start, per the product owner's explicit instruction
not to retrofit i18n across 30 screens later.

Entry points (all under `apps/api/resources/js/`):
- `components/AppShell.tsx` — the authenticated layout: a collapsible left sidebar (desktop) that
  becomes a `Sheet` drawer (phone), a topbar with the tax-subject switcher, language switcher,
  theme switcher, and account menu. Every authenticated page renders `<AppShell>{...}</AppShell>`;
  none of them pass a "which page is active" prop — `AppShell` derives it from Inertia's own
  `usePage().url`.
- `components/PlaceholderPage.tsx` — the one reusable "not built yet" screen, driven by
  `{ title, description, blockedOn? }` props.
- `i18n/dictionary.ts`, `i18n/ro.ts`, `i18n/en.ts`, `i18n/index.ts` — the hand-rolled i18n system
  (see "i18n" below).
- `components/ui/**` — 24 shadcn-generated primitives (see "Components added").
- `components/{StatusBadge,ErrorText,ListSkeleton,SubjectPanel,SubjectSwitcher,
  AccountSecurityCard,DocumentAiConsentCard,GuestLanguageToggle}.tsx` — shared pieces the six
  converted screens and the new settings screens compose.
- `resources/css/app.css` — the full design-token rewrite (see "Design tokens").
- `routes/web.php` — one route per navigation item (see "Routes").

## Setup steps (do this, in this order, on a future project without this foundation)

This is the part the task brief specifically asked to be documented, because the shadcn CLI's
current ("universal registry") architecture assumes Next.js/Vite conventions that do not exist
automatically in a Laravel + Inertia project, and the friction is not obvious from the CLI's own
output.

1. **Add the `@/` path alias before running anything else.** The CLI's `init` step checks for it
   ("Validating import alias") and every generated component imports as `@/components/ui/button`,
   `@/lib/utils`, etc.
   - `tsconfig.json`: add `"paths": { "@/*": ["./resources/js/*"] }` under `compilerOptions`. Do
     **not** also add `"baseUrl": "."` — TypeScript 6's `tsc` treats `baseUrl` without `paths`
     needing it as deprecated (`TS5101`) once `paths` alone can resolve relative to the tsconfig
     file's own directory (true since TS 4.1).
   - `vite.config.js`: add a `resolve.alias` entry mapping `@` to `path.resolve(dirname,
     'resources/js')`. TypeScript's `paths` only affects the type checker — Vite/Vitest need their
     own alias to actually resolve the import at build/dev/test time.
2. **Run `npx shadcn@<pinned-version> init`** — pass `-y --template laravel -b radix
   --css-variables -p <preset>` non-interactively (any preset; every token it writes gets
   overwritten in step 3 anyway — `nova` was used here). The CLI detects "Laravel" as a framework
   and "Vite" underneath it, writes `components.json`, and (in this CLI generation) also writes a
   runtime `shadcn` npm dependency plus an `@import "shadcn/tailwind.css"` line and a font import
   into `resources/css/app.css` — **do not keep these**, see step 3.
3. **Immediately rewrite the theme.** shadcn's own default look is the single most recognisable
   "AI-app" visual signature in circulation today — see `docs/adr/0013-shadcn-ui-component-
   foundation.md` for why this matters enough to be an ADR, not just a style choice. In
   `resources/css/app.css`:
   - Remove `@import "shadcn/tailwind.css";` and the generated font import.
   - Copy (not import) the ~9 Radix state-attribute `@custom-variant` blocks from
     `node_modules/shadcn/dist/tailwind.css`'s "Custom variants" section — every generated
     interactive component (dialog, sheet, dropdown-menu, select, tooltip, calendar) uses the
     `data-open:`/`data-closed:`/`data-checked:` shorthand these define, and skipping this step
     means every entrance/exit animation silently does nothing (harmless, but confusing to debug
     later). Do **not** import the full file — the rest of it is shimmer/scroll-fade/accordion
     utilities this project does not use (`docs/CODE-QUALITY.md` §2, no unused CSS).
   - Keep the `@theme inline { --color-primary: var(--primary); ... }` mapping block and the
     `--radius-sm/md/lg/xl/2xl` scale — this is required plumbing for *any* shadcn v4 Tailwind
     setup, not preset-specific.
   - Replace every `:root`/`.dark` colour value, the `--font-sans` value, and `--radius` with your
     own palette (see "Design tokens" below for what was chosen here and why).
   - Move the `shadcn` package itself to `devDependencies`, pinned exact — it is a **codegen CLI**,
     not runtime code; nothing in the shipped bundle should import from it.
4. **Add components with `npx shadcn@<pinned-version> add <names...> -y --overwrite`.** Expect it
   to loosen a version you already pinned exact back to a caret range in `package.json` (observed:
   `react-day-picker`) and to add an unwanted extra dependency your own components don't actually
   need (observed: `next-themes`, added by the generated `sonner.tsx` even though this app has its
   own theme hook — see Key decision 3). Re-pin/remove by hand afterward, then `rm -rf node_modules
   package-lock.json && npm install` once, then verify with `npm ci`.
5. **Expect `exactOptionalPropertyTypes: true` (already on in this project's `tsconfig.json`) to
   flag 2–4 generated files.** Observed here: `calendar.tsx` (a `locale?: Partial<Locale>` prop
   needed `| undefined` added explicitly — destructuring an optional property always yields
   `T | undefined` locally, which this flag then refuses to pass back into another optional slot
   typed as exactly `T`) and `dropdown-menu.tsx` (`DropdownMenuCheckboxItem` destructured `checked`
   only to pass it straight back through — deleting the destructure and letting `...props` carry it
   fixed the same class of error). These are mechanical, targeted fixes, not something to disable
   the flag for.
6. **Run the quality suite before touching any page.** `tsc --noEmit`, `eslint`, `prettier --check`
   should all be clean on the generated components alone before you start converting real screens —
   isolates "shadcn CLI output problem" from "my own regression" later.

## Design tokens

`resources/css/app.css`'s `:root`/`.dark` blocks. Deliberately not shadcn's defaults — see ADR
0013 for the reasoning, this section is the reference for the actual values and why.

- **Neutrals carry a faint, deliberate cool tint** (`oklch(... 0.006–0.014 240)`, hue ≈ 240 = blue)
  instead of shadcn's pure-grey (`oklch(... 0 0)`) defaults. The tint is barely perceptible on its
  own but reads as more considered than flat grey, and shares its hue family with the accent colour
  below for a cohesive, not-random palette.
- **Primary accent is a desaturated ink-navy** (`oklch(0.32 0.07 245)` light / `oklch(0.78 0.09 245)`
  dark) — chosen explicitly to avoid both shadcn's near-black-on-white default primary and any
  bright SaaS blue or purple-to-blue gradient (task brief, explicit instruction). Navy reads as
  "official document," not "startup."
- **`--success` / `--warning` / `--info` are new tokens, not part of shadcn's base set.** Added
  because document/extraction status badges (`StatusBadge`, `ExtractionStatusBadge`) previously used
  hardcoded `bg-emerald-100 text-emerald-800`-style Tailwind classes with **no dark-mode variant at
  all** — confirmed by reading the pre-conversion `StatusBadge.tsx`. Every status colour is now a
  theme token with both a light and dark value, mapped into `@theme inline` alongside the
  shadcn-standard tokens so `bg-success`, `text-warning`, etc. are real Tailwind utilities.
- **Corner radius is smaller** — `--radius: 0.375rem`, not shadcn's `0.625rem` default — a
  deliberately squarer, less "bubbly" silhouette, again for the "trustworthy and precise, not
  playful" brief.
- **Typography is IBM Plex Sans Variable** (`@fontsource-variable/ibm-plex-sans`, self-hosted, no
  external font request), not shadcn's default Geist. Geist is Vercel's typeface and is itself part
  of the "default AI-app" visual signature the brief asked to avoid; IBM Plex reads as more
  institutional/engineering, is open-licensed (OFL-1.1), and has real tabular-figure support.
- **Money and dates use Tailwind's built-in `tabular-nums` utility** (`font-variant-numeric:
  tabular-nums` — core Tailwind, no dependency, no custom CSS needed) wherever a figure is rendered
  (queue file sizes, session timestamps, extraction confidence percentages, document received-at
  times). A low-specificity `@layer base` rule also applies it by default to every `<time>` element
  and anything marked `data-numeric`, so a screen that forgets the utility class still gets aligned
  digits.
- **Dark theme** is a first-class `.dark` class block (toggled by `resources/js/hooks/useTheme.ts`),
  not an afterthought bolted on later — every token above has both a light and dark value.

## Components added

Via `npx shadcn@4.21.0 add` into `resources/js/components/ui/`: `button`, `input`, `label`,
`field` (see Key decision 1 for why this instead of `form`), `select`, `checkbox`, `radio-group`,
`textarea`, `dialog`, `sheet`, `dropdown-menu`, `table`, `card`, `badge`, `tabs`, `sonner` (toast),
`skeleton`, `alert`, `tooltip`, `separator`, `avatar`, `pagination`, `calendar` + `popover` (the
latter is `calendar`'s own dependency for a real date-picker composition — not requested by name
but required to deliver "date-picker" from the brief without inventing a bespoke one).

## i18n

**Chosen: a hand-rolled, selector-based dictionary — not `react-i18next`/`next-intl`/`lingui`.**
`docs/DEPENDENCY-POLICY.md` §4 orders "standard library" and "an existing dependency" above "a
well-maintained third-party package," and this app already has a precedent for exactly this
reasoning (`docs/handoff/030-pwa-frontend.md` Key decision 4, hand-writing runtime validation
instead of adding `zod`). Two languages, a few hundred short strings, no plurals-with-complex-CLDR-
rules requirement, no lazy-loaded namespace-per-route requirement — the actual problem is well
within what a ~40-line hook solves.

The mechanism (`resources/js/i18n/`):
- `dictionary.ts` declares one `Dictionary` TypeScript interface covering every string.
- `ro.ts` and `en.ts` each export a `const` object `satisfies Dictionary` — **this is the load-
  bearing design decision**: because both files must structurally match the same interface, a
  translation present in one language but missing from the other is a `tsc` compile error, not a
  blank string a user can actually see in production. Most i18n libraries only catch this with an
  opt-in lint plugin or at runtime; here it is unconditional.
- `useTranslation()` returns `{ t, locale, setLocale }`. `t` takes a **selector function**, not a
  string key: `t((d) => d.nav.dashboard)`, not `t('nav.dashboard')`. This means there is no runtime
  string-path parsing at all, and every call site gets full autocomplete/type-checking on the exact
  shape of the dictionary — a typo in a key name is caught by `tsc`, not discovered at runtime.
- Locale is a non-sensitive UI preference in `localStorage['kern.locale']` (`BLUEPRINT.md` §17),
  default `'ro'`, following the exact pattern `resources/js/hooks/useTheme.ts` already established
  for the dark-mode preference.
- `AppShell`'s `LanguageMenu` (topbar dropdown) covers every authenticated screen;
  `GuestLanguageToggle` (a small fixed-position button) covers the two screens that render outside
  `AppShell` entirely — `home.tsx` and `auth/login.tsx`.

**Enum-keyed labels became part of the dictionary, not left as separate `Record<Enum,string>`
constants.** `types/documents.ts` and `types/subjects.ts` previously exported
`DOCUMENT_TYPE_LABELS`, `DOCUMENT_STATUS_LABELS`, `REJECTION_REASON_LABELS`,
`EXTRACTOR_TYPE_LABELS`, `EXTRACTION_STATUS_LABELS`, `SUBJECT_TYPE_LABELS`,
`MEMBERSHIP_ROLE_LABELS` — all hardcoded Romanian, unreachable from the English dictionary. These
were deleted and their exact key sets (read from the real enum arrays in those files, not guessed —
see "Key decisions" 2 below for a mistake this caught) moved into `Dictionary`'s
`documentType`/`statusBadge`/`rejectionReason`/`extractorType`/`extractionStatus`/`subjectType`/
`membershipRole` namespaces, with the original Romanian text preserved verbatim and a genuine
English translation added, not a shortened paraphrase.

## AppShell

`resources/js/components/AppShell.tsx`. Desktop: a collapsible sidebar (`localStorage
['kern.sidebarCollapsed']`, non-sensitive UI state) holding every navigation destination, grouped
into the main list, "Contul meu"/"My account", and (if `ADMIN_UI_ENABLED`) "Administrare"/
"Administration". Collapsed state shows icons only, each wrapped in a `Tooltip` carrying the real
label so the control stays discoverable/accessible even iconified. Phone: the identical navigation
renders inside a `Sheet` drawer opened from the topbar's hamburger button — chosen over a bottom tab
bar (this task's first draft, superseded by the product owner's scope extension) once the menu grew
past three items; a drawer scales to an arbitrary number of grouped destinations, a bottom bar does
not.

The active nav item is derived from `usePage().url` (Inertia's own current-URL state) — no page
passes a "which screen is this" prop, which is what makes adding the 17 new routes in this task not
require touching each new page's shell-wiring code.

**Subject switcher** (`SubjectSwitcher.tsx`, topbar): lists the first page of
`GET /api/v1/subjects`, lets the user activate one. Deliberately does a full `window.location.
reload()` rather than a client-side state update on switch — every existing page still reads
`resources/js/lib/subjectStore.ts` once per mount (a pattern predating this task), so a reload is
what guarantees every already-mounted page picks up the new value instead of a subset silently
going stale.

## Routes

Every item in the sidebar has a route in `routes/web.php`, all behind the existing `auth`
middleware group. **This required editing a PHP file**, which is outside this task's original file
scope (`apps/api/resources/js/**`, `apps/api/resources/css/**`, and four config files were the
brief's explicit list — "do not touch any PHP"). The product owner's mid-task scope extension
("create a route for every item... including ones with no backend") is structurally impossible to
satisfy in a Laravel + Inertia app without a server-side route, since Inertia has no client-only
router of its own — the alternative (not doing this at all) would have left the entire navigation
non-functional. The change made is the smallest possible one: 20 one-line closure routes in exactly
the same shape the file's own docblock already described ("no domain model is imported... this
module has no business logic of its own"), no new controller, no new middleware. Flagging this
explicitly per `docs/AGENTS.md` §10 rather than treating it as implicitly authorized.

`Documente`/`Capturare` in the sidebar point at the pre-existing `/documents`/`/documents/capture`
English-slug routes (not new `/documente` Romanian-slug duplicates), because those routes are
already load-bearing — the offline IndexedDB queue, the Reverb subscription, and
`docs/handoff/033-pwa-wiring.md`'s live end-to-end verification all reference these exact URLs.
Renaming a working, tested route for menu-label consistency alone was judged not worth the risk.

**Real screens:** `/dashboard`, `/documents`, `/documents/capture`, `/documents/{id}/review`
(unchanged, converted to the new components), `/setari/securitate`, `/setari/subiecte`,
`/setari/confidentialitate` (new — see below).

**Placeholder screens** (`PlaceholderPage`, honestly labelled "Încă neconstruit"/"Not built yet",
stating what they will do and what real thing blocks them): `/portofel`, `/portofel/conturi`,
`/portofel/depozite`, `/chirii`, `/impozite`, `/impozite/explica`, `/declaratii`, `/legislatie`,
`/spv`, `/setari/profil`, `/admin/setari`, `/admin/parametri`, `/admin/utilizatori`,
`/admin/operatiuni`, `/admin/audit`.

## Settings pages — reuse, not duplication

`/setari/securitate` and `/setari/confidentialitate` are real, not placeholders, because their
underlying functionality already existed on `/dashboard` (`docs/handoff/017-authentication.md`'s
MFA/passkey/session APIs; ADR 0005's AI-consent endpoint). Rather than duplicate that logic across
two pages, it was extracted into two standalone components (`docs/CODE-QUALITY.md` §1, DRY):
- `AccountSecurityCard.tsx` — TOTP/passkeys/recovery codes/sessions, mounted on both `/dashboard`
  and `/setari/securitate`.
- `DocumentAiConsentCard.tsx` — the AI-consent toggle, mounted on both `/dashboard` (when a subject
  is active) and `/setari/confidentialitate`.
- `SubjectPanel.tsx` (pre-existing) is mounted on both `/dashboard` and the new `/setari/subiecte` —
  no extraction needed, it was already a standalone component.

`/dashboard` itself shrank to a thin composition of `SubjectPanel` + `DocumentAiConsentCard` +
`AccountSecurityCard` — same visible content as before conversion, now sourced from shared
components instead of one 330-line page file.

## Admin navigation — no real gate exists yet

There is no role/permission system in this codebase — Spatie's `laravel-permission` is being
adopted in a separate, concurrent task per `docs/STATUS.md`, but does not exist yet. The
"Administrare" sidebar group and its five routes are gated behind a single named constant,
`resources/js/lib/featureFlags.ts`'s `ADMIN_UI_ENABLED`, carrying an explicit
`@todo(KERN-ROLES)` naming the real gate this must become
(`usePage().props.auth.user.can('platform.admin')` or equivalent, once Spatie lands). It currently
defaults to `true` **only** so the product owner's stated goal — see every planned screen, including
the admin ones — is achievable in this review; it is not, and must never be treated as, real
authorization. `routes/web.php`'s admin routes carry no server-side check either, same as every
other route in that file; this is a client-side navigation-visibility flag only, never a security
boundary. Flagged again in the code comment itself so this is not lost the next time someone reads
`featureFlags.ts` in isolation.

## Dependencies added

Every version exact-pinned, checked live against the npm registry on 2026-09-08 (not from training
memory). All MIT except `class-variance-authority` (Apache-2.0), `lucide-react` (ISC), and
`@fontsource-variable/ibm-plex-sans` (SIL OFL-1.1) — all permissive, all compatible.

| Package | Version | Why | Last publish checked |
|---|---|---|---|
| `shadcn` (devDependency) | 4.21.0 | Codegen CLI only — not imported at runtime. | 2026-09-04 |
| `radix-ui` | 1.6.7 | Consolidated single package for every Radix primitive this task's components use (dialog, sheet, dropdown-menu, select, checkbox, radio-group, tabs, tooltip, avatar, popover, label) — the current registry's replacement for many individual `@radix-ui/react-*` packages. | 2026-07-31 |
| `cn` | 0.2.6 | shadcn team's own official `clsx`+`tailwind-merge` replacement (zero dependencies) — first-party to this ecosystem, not a random name-squatted package (checked the npm listing's repo link: `shadcn-ui/cn`). | 2026-09-06 |
| `class-variance-authority` | 0.7.1 | Variant-class composition (`buttonVariants`, `badgeVariants`, etc.) — what every generated component uses for its `variant`/`size` props. | 2024-11-26 (stable, unchanged since; no newer release exists) |
| `lucide-react` | 1.43.0 | Icon set shadcn's registry standardises on. | 2026-09-08 |
| `tw-animate-css` | 1.4.0 | `animate-in`/`fade-in-0`/`zoom-in-95`-style utilities the generated dialog/sheet/dropdown/tooltip/calendar components use for enter/exit transitions — the Tailwind-v4-compatible successor to the old `tailwindcss-animate`. | 2026-02-28 |
| `@fontsource-variable/ibm-plex-sans` | 5.3.0 | Self-hosted variable font — see "Design tokens." | 2026-07-19 |
| `sonner` | 2.0.8 | The toast library the brief named directly ("toast/sonner"). | 2026-08-09 |
| `react-day-picker` | 10.0.1 | `Calendar`'s underlying date-grid implementation — required for a real date-picker, not requested by name but a direct dependency of delivering one. | 2026-08-31 |
| `date-fns` | 4.4.0 | `react-day-picker`'s own date-formatting dependency. | 2026-05-29 |

**Rejected additions** (recorded per `docs/DEPENDENCY-POLICY.md` §4):
- `next-themes` — the generated `sonner.tsx` imports it by default for theme-aware toasts. Built
  and named for Next.js's App Router (script injection before hydration to prevent a
  flash-of-wrong-theme on server-rendered HTML); this Inertia SPA has no server-rendered HTML to
  flash against. Replaced with this app's own `resources/js/hooks/useTheme.ts`, already needed for
  the whole app's dark-mode toggle regardless of `sonner`.
- `react-hook-form` + `zod` + `@hookform/resolvers` — shadcn's `form` registry item wraps
  `react-hook-form`, but querying it directly (`npx shadcn view form`) returned an **empty**
  registry entry (`{"name": "form", "type": "registry:ui"}`, no files) in this CLI version — it is
  not currently offered as downloadable content at all under the `radix`/`nova` base this project
  uses. Used `field` instead (`Field`/`FieldLabel`/`FieldDescription`/`FieldError`/`FieldSet`/
  `FieldGroup` — a dependency-free, purely presentational accessibility-wiring layer: real
  `<label for>`/`aria-describedby` association, no form-state library at all), matching this
  codebase's existing pattern of plain `useState`-driven forms with its own hand-written
  `resources/js/lib/validate.ts` (`docs/handoff/030` Key decision 4) rather than introducing a
  second, competing form-state paradigm.

## Key decisions

1. **`field`, not `form`, delivers this task's "form" component.** See "Rejected additions" above.
2. **Enum values for the i18n dictionary were read from the real source files, not guessed.** A
   first draft of `resources/js/i18n/dictionary.ts` invented plausible-looking enum keys (e.g.
   `rejectionReason: { MALFORMED_PDF, ENCRYPTED_PDF, UNSUPPORTED_MIME, ... }`) before actually
   reading `resources/js/types/documents.ts`, whose real `REJECTION_REASONS` array has twelve
   different values (`INFECTED`, `INVALID_MAGIC_BYTES`, `EXTENSION_MISMATCH`, ...) that don't match
   the guess at all. Caught before shipping by reading the real file per `docs/AGENTS.md` §2 ("never
   fabricate... API responses... you have not observed") — the dictionary now uses the exact enum
   values `DOCUMENT_TYPES`/`DOCUMENT_STATUSES`/`REJECTION_REASONS`/`EXTRACTOR_TYPES`/
   `EXTRACTION_STATUSES`/`SUBJECT_TYPES`/`MEMBERSHIP_ROLES` already declare, and the original
   Romanian label text those enums' now-deleted `*_LABELS` constants used, translated (not
   paraphrased) into English.
3. **`sonner.tsx`'s theme awareness uses this app's own `useTheme` hook, not `next-themes`.** See
   "Rejected additions."
4. **Status badges use new `--success`/`--warning`/`--info` theme tokens, not shadcn's four base
   variants (`default`/`secondary`/`destructive`/`outline`).** shadcn's `Badge` has no "soft amber/
   emerald/blue" variant out of the box; the pre-conversion `StatusBadge`/`ExtractionStatusBadge`
   already needed exactly these four semantic colours (quarantine=amber, processing=blue,
   ready=emerald, rejected=red) with **no dark-mode variant** — this was the actual defect being
   fixed, not a cosmetic choice.
5. **`AppShell` takes no "current page" prop.** Superseded an earlier, narrower design (a 3-item
   bottom tab bar keyed by an explicit `current: 'dashboard' | 'inbox' | 'capture'` prop) once the
   product owner's scope extension grew the navigation past what a bottom bar or an explicit enum
   could reasonably cover — `usePage().url` from Inertia is the actual source of truth for "what
   page is this" and needed no prop-threading at all.
6. **The offline capture queue (`useUploadQueue.ts`) now stores only the `RejectionReason` code, not
   a pre-rendered Romanian string, in a failed item's `errorMessage`.** Previously the hook itself
   picked the display string via the (now-deleted) `REJECTION_REASON_LABELS` constant, which baked
   Romanian text into IndexedDB regardless of the viewer's chosen language. `capture.tsx` now
   resolves the label at render time via `t((d) => d.rejectionReason[reason])`, so an item queued
   under one language displays correctly if the user switches language before it's dequeued.

## How to verify

All commands run for real, from `apps/api/`, output pasted verbatim, this session.

```
$ rm -rf node_modules && npm ci
added 620 packages, and audited 621 packages in 6s
found 0 vulnerabilities

$ npx tsc --noEmit
(no output — no errors)

$ npx eslint resources/js
.../components/ui/badge.tsx    44:17  warning  react-refresh/only-export-components
.../components/ui/button.tsx   63:18  warning  react-refresh/only-export-components
.../components/ui/tabs.tsx     73:52  warning  react-refresh/only-export-components
✖ 3 problems (0 errors, 3 warnings)
```
(The three warnings are shadcn's own generated `cva`-variant-export pattern — pre-existing in this
codebase's eslint config as `warn`, not `error`, specifically to allow this pattern; not introduced
by this task, exit code 0.)

```
$ npx prettier --check resources/js
Checking formatting...
All matched files use Prettier code style!

$ npx vitest run
 Test Files  10 passed (10)
      Tests  77 passed (77)
   Duration  2.03s

$ npm run build
✓ 2599 modules transformed.
public/build/manifest.json               2.94 kB │ gzip:   0.41 kB
public/build/assets/*.woff2              (6 IBM Plex Sans subset files, incl. latin-ext for
                                          Romanian diacritics ș/ț/ă/î/â)
public/build/assets/app-SjyfezUr.css   111.80 kB │ gzip:  18.40 kB
public/build/assets/app-B5U90Y84.js    699.78 kB │ gzip: 214.00 kB
✓ built in 742ms
(warning: JS chunk >500 kB — see "What is NOT done")

$ npm audit --audit-level=high
found 0 vulnerabilities
```

### Live-server verification — what was actually checked vs. assumed

The stack at `https://your.server.ip/` (self-signed cert, `-k`) already mounts this repository
directly — confirmed by observing the exact same content-hashed asset filenames
(`app-SjyfezUr.css`/`app-B5U90Y84.js`) served live as this session's own `npm run build` produced,
without any separate deploy step.

**Discovered and fixed as part of this verification, not before it:** the app container's PHP-FPM
runs with `opcache.validate_timestamps=Off` (confirmed via `docker exec anaf-app-1 php -i`) — a
production-style setting that caches compiled bytecode indefinitely. `php artisan route:list`
(a fresh CLI process) showed the 17 new routes correctly immediately after editing `routes/web.php`,
but the live HTTP server kept 404-ing them until `docker restart anaf-app-1` (a plain restart, not a
data-affecting operation) forced a recompile. Recorded here because the next person editing PHP on
this same running stack will hit the identical false negative.

Real, live, authenticated exercise (not mocked) after that restart:
```
POST /api/v1/auth/register        → 201 {"id":"01a0819b-..."}   (fresh test user)
POST /api/v1/auth/login           → 200 {"mfa_required":false, ...}
GET  /dashboard                   → 200, real Inertia shell, component "dashboard"
GET  /documents                   → 200, real Inertia shell, component "documents/inbox"
GET  /api/v1/subjects             → 200 {"data":[], ...}          (real empty-state, unchanged API)
GET  /dashboard (unauthenticated) → 302 → /login                 (auth middleware still enforced)

Every one of the 17 new routes, authenticated:
/portofel /portofel/conturi /portofel/depozite /chirii /impozite /impozite/explica
/declaratii /legislatie /spv /setari/profil /setari/securitate /setari/subiecte
/setari/confidentialitate /admin/setari /admin/parametri /admin/utilizatori
/admin/operatiuni /admin/audit
  → all 200, each serving the correct distinct Inertia component name (spot-checked
    /impozite/explica's raw response: `{"component":"taxes/explain", ..., "url":"/impozite/explica"}`)

GET /manifest.webmanifest, /sw.js, /build/manifest.json → all still 200 (PWA layer untouched)
```

**Honestly not checked — no Chromium/Lighthouse binary in this sandbox (same constraint
`docs/handoff/030`/`033` recorded):**
- No visual/screenshot confirmation of the sidebar, drawer, dark theme, or language switch actually
  rendering correctly in a real browser. The evidence above proves the correct component is served
  and the correct build artifact is live; it does not prove pixels are correct.
- No real accessibility audit (axe, screen-reader pass) was run — the accessibility claims in this
  document (real `<label for>`, `aria-current`, `aria-expanded`, tooltip-on-collapsed-icon) are
  verified by reading the rendered JSX/generated Radix markup, not by an assistive-technology tool.
- No contrast-ratio tool was run against the new palette's light/dark values — chosen by lightness-
  delta heuristic (documented in "Design tokens"), not measured. A follow-up should run an automated
  contrast check before this ships to real users.
- The document-capture offline queue, Reverb live updates, and extraction accept/correct flow's
  *behaviour* were not re-exercised end-to-end in this session (unlike `docs/handoff/033`'s full
  walkthrough) — none of their underlying logic changed in this task (only their markup/styling and
  the addition of i18n `t()` calls around already-existing strings), so the risk surface is the
  React/CSS layer already covered by `tsc`/`eslint`/`vitest`/live-route-200s above, not the
  documents pipeline itself.

## What is NOT done

- **i18n coverage is not literally 100%.** The new shell/sidebar/placeholder infrastructure and all
  six converted screens route every visible string through `t()`. Not converted: a handful of
  `alt`/`title` attributes on the document-preview `<img>`/`<iframe>` in `review.tsx` (fixed
  Romanian strings — "Previzualizare document", "Previzualizare document PDF"), and the
  `fieldValueAsText` fallback string `'(valoare neafișabilă)'` for values that fail to
  `JSON.stringify` (a genuinely rare edge case). Flagged rather than silently left — a future pass
  should finish these.
- **No automated contrast/accessibility audit tool was run** — see "Honestly not checked" above.
- **No route-level code-splitting.** `app.tsx`'s `import.meta.glob('./pages/**/*.tsx', { eager:
  true })` still eagerly bundles all ~30 pages into one ~700 KB gzip JS chunk. Switching to
  `{ eager: false }` (lazy, per-route dynamic import) is the natural fix and was not done here —
  it is a real, if not urgent, performance follow-up given Vite's own build warning.
- **The admin section has no real authorization** — see "Admin navigation" above; this is stated as
  a known, deliberate gap, not an oversight.
- **`resources/views/app.blade.php`'s `<meta name="theme-color">` still reads the old blue
  (`#2563eb`)**, not the new palette's primary token. Out of this task's file scope (a `.blade.php`
  view, not `resources/js/**`/`resources/css/**`) — a future task with that file in scope should
  update it to match.
- **No Lighthouse/real-browser installability or visual-regression audit** — see "Honestly not
  checked" above; same sandbox constraint every prior PWA-frontend handoff has recorded.
- Did not touch `app/Domain/**`, the six pre-existing screens' *business logic* (only their
  markup/styling/strings), `docs/STATUS.md`, or any file outside the scope named above and in
  "Routes."

## Open questions

1. Should `ADMIN_UI_ENABLED` default to `false` the moment this lands anywhere reachable by a real
   user, even before Spatie's role system exists? This handoff argues yes, once this specific
   review is over — left as an explicit decision for whoever merges this, not made unilaterally here
   since the product owner's own instruction was "so every menu item is reachable for this review."
2. Should the JS bundle-size warning be addressed now (route-level lazy loading) or batched with a
   future performance pass once more real screens exist and the actual per-route weight is known?
3. Is IBM Plex Sans the right long-term typographic identity, or a placeholder-but-deliberate choice
   for this pass? No user-facing type audit (readability at small sizes across the full Cyrillic-
   adjacent/Latin-ext glyph set the app actually needs) was performed beyond confirming the
   Romanian-diacritic subset (`latin-ext`) loads.

## Sources consulted

- `BLUEPRINT.md` §1.3, §1.4, §17, §19.5 — the original task's literal brief.
- `docs/AGENTS.md` in full, especially §2 ("never fabricate" — directly caught the enum-guessing
  mistake in Key decision 2) and §10 (recording the `routes/web.php` scope deviation and the
  opcache discovery rather than acting on them silently).
- `docs/CODE-QUALITY.md`, `docs/DEPENDENCY-POLICY.md` — read in full before adding any dependency;
  every addition/rejection above justified per §4's ordering.
- `docs/handoff/030-pwa-frontend.md`, `033-pwa-wiring.md` — read in full; every existing
  API/Reverb/IndexedDB contract these describe was preserved unchanged; their own "honesty note"
  precedent (state plainly what a sandbox without Chromium cannot verify) followed here too.
- Every file under `apps/api/resources/js/**` that predated this task was read directly before
  editing — `types/documents.ts` and `types/subjects.ts` in particular, whose real enum arrays
  caught the fabrication in Key decision 2.
- shadcn/ui CLI itself, queried live and directly: `npx shadcn@4.21.0 view <component>`,
  `npx shadcn@4.21.0 add <component> --dry-run`/`--view`, `npx shadcn@4.21.0 search @shadcn` — used
  to confirm the real registry contents (including the empty `form` entry) rather than assuming
  shadcn's public documentation (written for Next.js) applied unchanged to this Laravel/Vite setup.
- npm registry queried live (`npm view <pkg> version/license/time.modified`) for every dependency
  named in the table above, on 2026-09-08 — not from training-data memory.
- Live stack at `https://your.server.ip/`, `docker exec`/`docker restart` against `anaf-app-1` —
  see "Live-server verification" for exactly what was run and what its output was.
