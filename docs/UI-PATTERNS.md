# UI patterns

Screens compose from `apps/api/resources/js/components/panel`. A page should not contain a size.

## Why this exists

Three separate rounds of review went on the same defect: a page hand-wrote a padding or a font
size, it drifted from every other page, and fixing it meant editing each place separately. The
vocabulary below exists so a size is decided once. `resources/js/components/panel/__tests__/
noRawStyling.test.ts` enforces it — a page that writes `text-[13px]` or `p-[15px]` fails the suite.

The guard covers only pages that import from `components/panel`. A screen still on its original
markup is not a violation; the moment it adopts the vocabulary it is covered. Coverage grows as
screens are rebuilt, without a flag day.

## The cascade rule — read this before adding a class

These classes live in `resources/css/app.css` **outside any `@layer`**. Tailwind puts everything it
generates into layers, and unlayered CSS beats every layered rule. That is what lets `.ctl`
override a vendor component's own `data-[size=sm]:h-7` with no `!important` and no edits to `ui/*`.

It cuts both ways, and has three times:

- `.panel { box-shadow: … }` replaced `ui/card`'s `ring-1` border outright, and every card lost its
  outline. Anything these classes set, they own completely — `.panel` now declares its border.
- `<Panel className="p-0">` does nothing: `p-0` is a utility and loses. Use `flush`.
- A `cn()` merge cannot help here either. tailwind-merge resolves conflicts *between utilities*; it
  does not know about these classes.

## Vocabulary

| Component | For |
|---|---|
| `Page` | Outer wrapper: title, lede, optional actions, 18px rhythm |
| `Panel` | One padded card. `flush` when the body reaches the edges (a table, a toolbar) |
| `FieldGrid` / `Field` / `FieldSpan` | Values the operator types or picks |
| `RowList` / `Row` | One setting per line with a switch, status or badge on the right |
| `MetricGrid` / `Metric` | Values read far more often than changed; edited in a dialog |
| `StatGrid` / `Stat` | Summary tiles above a listing |
| `Toolbar` / `SearchInput` / `PanelSelect` | Filter bar above a table |
| `DataTable` | Listing table; scrolls inside its own wrapper, never the page |
| `DetailList` / `Detail` | Key/value pairs in a drawer |
| `TabRail` | Flat underline tabs |
| `PanelInput` / `PanelButton` | Controls at the standard 32px size |
| `Pill` / `Status` / `Check` | Provenance, live state, matrix cells |
| `PanelDialog` | Small edit dialog, 460px, plain right-aligned actions |

Classes for the rest: `.page-stack`, `.page-title`, `.page-head`, `.page-lede`, `.note`,
`.eyebrow`, `.mono`, `.tile`, `.drawer`, `.filter-select`, `.locked-rows`.

## Which card body to use

The choice is a statement about the content, not a layout preference:

- **`FieldGrid`** — the operator types or picks the value.
- **`RowList`** — the thing is a *state*, not a value: a switch, a health check, a lock. Anything
  that is not free text reads better as a row than as a labelled input.
- **`MetricGrid`** — read far more often than changed. Shown formatted and read-only; editing
  happens in a dialog, so the display never has to double as an input. This is also what removed a
  real defect: a unit suffix inside a number input collides with the spinner arrows.

## Three-state marks

`Check` has three states, not two: granted-and-enforced (filled), granted-but-checked-nowhere
(outlined), not granted (empty). `Status` and `Pill` follow the same principle — a screen must not
assert a protection or a provenance the system does not actually have.
