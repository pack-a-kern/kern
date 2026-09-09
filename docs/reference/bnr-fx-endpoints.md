# BNR exchange-rate XML endpoints — investigation

**Investigated:** 2026-09-08 · **Status: RESOLVED 2026-09-08**

## RESOLUTION — BNR moved its XML feeds to a dedicated server

The feeds now live on **`curs.bnr.ro`**, not `www.bnr.ro`. The official XML page announces this
in its rendered content: *"NOU: Server dedicat fişierelor XML — curs.bnr.ro"*. That text and the
links are injected client-side, which is exactly why every plain-HTTP attempt below failed.

Discovered by rendering the page with the project's own Playwright worker image and reading the
XML links out of the live DOM.

| Feed | URL | Verified 2026-09-08 |
|------|-----|---------------------|
| Daily | `https://curs.bnr.ro/nbrfxrates.xml` | 200 `text/xml`, 1 796 B, 37 rates, `PublishingDate 2026-09-07` |
| Last 10 days | `https://curs.bnr.ro/nbrfxrates10days.xml` | listed on the page |
| Yearly archive | `https://curs.bnr.ro/files/xml/years/nbrfxrates{year}.xml` | **2005–2026** available; 2025 fetched = 350 KB, 248 banking days (2025-01-03 → 2025-12-31) |

Namespace: `https://www.bnr.ro/xsd`, schema `https://curs.bnr.ro/xsd/nbrfxrates.xsd`.

### The multiplier trap is real
Real data confirms five currencies are quoted **per 100 units**, carrying `multiplier="100"`:
**HUF, IDR, ISK, JPY, KRW**. EUR and the rest are per single unit. Treating a multiplier-100 rate
as per-unit scales a conversion by 100 silently. M2's importer stores rate and multiplier verbatim
and divides at read time; `tests/Unit/Domain/Fx/BnrRealFeedParsingTest.php` pins this against the
genuinely captured fixture `tests/Fixtures/Fx/Bnr/captured-daily-2026-09-07.xml`.

`config/sources.php` now defaults to these URLs, still overridable via `BNR_DAILY_XML_URL` /
`BNR_YEARLY_XML_URL_TEMPLATE` so a future relocation needs no code change (§7.1).

---

## Original investigation (kept for the record)

`BLUEPRINT.md` §7.1 names these as the canonical FX sources:

- daily: `https://www.bnr.ro/nbrfxrates.xml`
- yearly archive: `https://www.bnr.ro/files/xml/years/nbrfxratesYYYY.xml`

The blueprint itself warned (§7.1) that BNR issued communications in 2026 about XML access, and that
the crawler must tolerate endpoint changes. **That warning has materialised.**

## What was actually observed

Direct HTTPS requests from this environment, 2026-09-08:

| URL | Result |
|-----|--------|
| `https://www.bnr.ro/nbrfxrates.xml` | **302 → `https://www.bnr.ro/`** |
| `https://www2.bnr.ro/nbrfxrates.xml` | **302 → homepage** |
| `https://www.bnr.ro/files/xml/years/nbrfxrates2026.xml` | 200 but **`text/html`, 119 971 bytes** — the homepage |
| `https://www.bnr.ro/files/xml/years/nbrfxrates2025.xml` | identical homepage payload |
| `https://www.bnr.ro/nbrfxrates10days.xml` | identical homepage payload |
| `https://www.bnr.ro/xml/nbrfxrates.xml` | identical homepage payload |

Retried with a browser `User-Agent` and `Accept: application/xml` — still 302.

We are genuinely reaching BNR: `https://www.bnr.ro/` returns the real site
(`<title>BNR Banca Națională a României (BNR)</title>`).

The official page **“Cursurile pieței valutare în format XML”**
(`https://www.bnr.ro/23988-cursurile-pietei-valutare-in-format-xml`) returns 119 215 bytes that
contain only the site navigation shell — **no `.xml` links appear in the served HTML at all**
(`grep -oiE 'href="[^"]*\.xml"'` → zero matches). The substantive content is not server-rendered,
which points to client-side rendering or some form of access control.

## Additional evidence (same session)

- **Session cookies do not help.** Establishing a session on `https://www.bnr.ro/` (5 cookies set)
  and re-requesting the feed with those cookies plus a `Referer` still returns the homepage HTML.
- **The XML page is genuine, but its body is empty server-side.** It is not a redirect to the
  homepage — its `<title>` and `og:url` correctly identify it
  (`BNR Cursurile pieței valutare în format XML`). However its `<main>` element contains
  **314 bytes and no links at all**. The English equivalent
  (`/en/24006-exchange-rate-list-in-xml-format`) behaves identically: `<main>` is 314 bytes and the
  string `nbrfxrates` appears **zero** times.
- **No client-side data fetch is visible** either: the page's HTML contains no `fetch(`, `axios`,
  `XMLHttpRequest`, or `.json` reference. Its scripts are `assets/js/main.js`, CKEditor, an
  accessibility widget, and Cookiebot.

Conclusion: the rate listing is populated client-side by the site's own bundle, so a plain HTTP
fetch cannot see it. **A JavaScript-capable browser is required just to discover the URLs.**

## What this does NOT tell us

We have **not** established that the XML feeds are discontinued. Plausible explanations, none yet
confirmed: client-side rendering hides the links from plain HTTP fetches; bot/UA filtering or a WAF;
geo/IP filtering on this egress; a genuine relocation; or a new registration requirement.

**No endpoint has been invented or assumed.** `docs/AGENTS.md` §1.1 and §2 forbid it, and an
invented FX endpoint would silently corrupt every currency conversion in the product.

## Resolution paths, cheapest first

1. **A human opens the page in a normal browser** and reports the literal XML URLs. Two minutes,
   and it settles the question.
2. **Render the page with the Playwright worker** we already have (`apps/spv-browser`) to execute
   the page's JavaScript and extract the links. Infrastructure exists; needs a small discovery task.
3. **Ask BNR directly** whether programmatic access now requires registration.

## Consequence for Milestone 2

M2's importer, storage, parser, idempotency, anomaly detection and monthly/annual averaging can all
be built and tested against **recorded fixtures** — that work is not blocked. What *is* blocked is
the live daily import, which cannot be pointed at a real URL until one is verified.

**Do not hardcode any BNR URL until this file records a confirmed one.** The source registry
(§8.2) must hold it as configuration so it can change without touching the tax engine.
