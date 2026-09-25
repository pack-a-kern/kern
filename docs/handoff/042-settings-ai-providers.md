# 042 — Settings page: provider presets, toasts, redesign

Continues `041-settings-module.md`. The settings screen is now finished end to end;
treat it as the template other screens copy.

## What changed

### 1. The AI group could not be saved (real bug, now fixed)

`PUT /api/v1/settings/ai` matched `UpdateAiSettingsController`, which requires
`enabled`, `provider`, `base_url` and `model` all present. The generic
group-update controller was registered as `PUT {group}` and so never saw the `ai`
group at all: any partial save of AI settings returned 422.

The generic route is now `PUT /api/v1/settings/group/{group}`. Distinct paths,
no ordering subtleties — a bare `{group}` wildcard next to specific `ai/*` routes
is a trap that had already bitten once (see 041).

### 2. Provider presets — `config/ai_providers.php`

Reference data, not configuration: choosing a preset only prefills `base_url` and
offers a model list, and every field stays editable. Served from
`GET /api/v1/settings/ai/providers` rather than bundled into the frontend, so
adding a provider is a config edit with no rebuild.

`dialect` is the only field with behavioural weight. **Anthropic's public API is
not OpenAI-compatible** — it is `POST /v1/messages` with `x-api-key` and an
`anthropic-version` header, not `/v1/chat/completions` with a bearer token.
Listing Anthropic as a preset without modelling that would have shipped an option
that could never report a working connection, so `Kern\Platform\Settings\Enums\AiDialect`
now selects the wire format and `TestAiProviderConnection` branches on it. This is
consistent with ADR 0007, which standardises on the OpenAI shape and allows a thin
per-provider adapter.

### 3. Live model discovery — `POST /api/v1/settings/ai/models`

Asks the provider what it actually serves, using a *draft* `base_url`/`api_key`
from the form so the operator can see the list before saving. POST, not GET: the
draft key travels in the body and never reaches a URL, an access log, or browser
history (§4.10).

Two response shapes cover every provider: `{"data":[{"id":…}]}` (OpenAI,
Anthropic, OpenRouter) and `{"models":[{"name":…}]}` (Ollama's `/api/tags`, which
lists what is actually pulled onto that machine). An empty list is reported as a
failure, not as "connected, 0 models" — a 200 carrying something other than a
model list is the common case there.

The base URL is operator-supplied by design (self-hosted Ollama, corporate
gateway), so this is a deliberate outbound request behind the settings-admin gate,
not an SSRF hole: an administrator who can set `ai.base_url` can already cause the
same request via the connection test. A test asserts an ordinary user gets 403 and
that **nothing is sent**.

### 4. Toasts instead of page-level errors

`sonner` was already mounted in `app.tsx`. A failed load no longer replaces the
page with "Settings could not be loaded" — the page stands, a toast explains, and
a retry button appears in the header, so a transient blip cannot discard edits.
Save, connection test and model discovery all report through toasts.

`ApiError.message` is no longer shown raw for transport failures: `lib/http.ts`
now throws `reason: 'network'` with a developer-facing English message, and the
UI translates from the reason.

### 5. No user-facing copy left in code

Three Romanian strings lived outside `resources/js/i18n` and are now dictionary
entries: the network error in `lib/http.ts`, the upload-failure fallback in
`useUploadQueue.ts` (it also no longer *persists* copy into IndexedDB — a queued
item stays correct if the locale changes before it retries), and
`(valoare neafișabilă)` in `documents/review.tsx`.

### 6. Layout

Adopted the tighter proportions from the user's own mock (`kern-settings-interactive-v2.html`)
— 15px card padding, 13px inputs, 12px labels, 10.5px badges — **without tabs**,
which they explicitly rejected. Cards flow in CSS multi-columns rather than a
row-aligned grid: these cards differ a lot in height, and a grid aligns rows,
padding a tall card's neighbour with dead space.

### 7. The font was wrong

The reference design is **Inter**; the app shipped **IBM Plex Sans**. No amount of matching sizes
closes that gap — the letterforms, widths and texture are what the eye reads first.
`@fontsource-variable/inter` (pinned `5.3.0`) replaces it; IBM Plex was removed rather than left
installed and unused.

### 8. Reusable panel vocabulary — `resources/js/components/panel/`

Every admin/detail screen composes from these instead of restating geometry. Four card bodies, and
which one a card uses is a statement about its content:

| body | for |
|---|---|
| `FieldGrid` | values the operator types or picks |
| `RowList` | one setting per line with a switch, status or badge — anything that is a *state*, not free text |
| `MetricGrid` | values read far more often than changed; shown formatted, edited in a dialog |
| plain children | anything else |

Plus `Panel` (head / body / foot with note-left, actions-right), `Field` (label row is
`justify-between`, so markers align in a column), `Status` (dot + word, live state only), `Pill`,
`PanelButton`, `CONTROL`.

Applied on this screen: upload limits became metrics + an edit dialog; read-only settings became
rows carrying their value and a `locked` pill instead of disabled inputs each followed by the same
explanatory paragraph; the AI key became a masked box with a **Replace** dialog, since a stored key
is never sent to the browser and there is nothing to edit in place.

### 9. Unit bug — display units vs stored units

Typing `26` into a size field replaced it with `27262976` on the next keystroke: the draft held the
*converted* value and was then re-rendered raw. Drafts now hold what the operator typed, in the unit
shown; conversion happens once, when the save payload is built.

The unit also moved out of the input and into the label — `Mărime maximă PDF (MB)`. A suffix inside
a number input collides with the spinner arrows, which is exactly what it looked like.

### 10. System status — real probes only

`GET /api/v1/settings/health` probes database, cache, object storage, Python processing and ClamAV
(`zPING` → `PONG` on its own line protocol). Each probe is wrapped so one unreachable service
degrades its own row and never fails the request — the endpoint is most useful when something is
down. Exception text is never forwarded (§4.10); the class name identifies the layer and the real
cause is logged.

**Deliberately not shown**, rather than guessed: queue workers (Horizon's supervisor state is not
readable without its storage contract, and a wrong worker count misleads during an incident) and
Reverb (a TCP connect proves a socket is open, not that broadcasting works). The reference design's
Qdrant and PaddleOCR rows are absent because ADR 0006 and ADR 0005 mean we do not run them.

## Guard added

`tests/Feature/Platform/Settings/SettingLabelCoverageTest.php` asserts from PHP that
every `SettingKey` has a label in `ro.ts` **and** `en.ts`, and that no label names
a key that no longer exists. This is the only place the two halves meet: `tsc`
proves the two dictionaries agree with each other, but nothing proved they agree
with the enum. `sources.fetch_max_response_bytes` vs `sources.fetch_max_bytes`
had already shipped, and the screen rendered the raw key — caught only by looking
at the page.

**Look at the rendered page before calling a screen done.** Every automated gate
passed while that label was wrong and its value showed as `10485760` instead of
`10 MB`.

## Still open

- **Passkey registration returns 422** ("challenge expired, was already used, or
  was never started"). Pre-existing, deferred by the user; two tests fail because
  of it. STATUS.md P1.
- **No role system.** Settings admin is still the `SETTINGS_ADMIN_USER_IDS`
  allowlist from ADR 0013. Spatie is the chosen direction but is not adopted.
- **Antivirus scanning is deliberately not switchable.** There is no
  `documents.antivirus_enabled` setting and one should not be added casually:
  §14.1/§14.2 make the ClamAV scan a gate on the upload pipeline, and §35 lists it
  as a pre-production requirement. `ai.enabled` is the one subsystem the screen
  can switch off.
