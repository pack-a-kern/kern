# ANAF SPV — what is officially documented

**Investigated:** 2026-09-08. Every statement below was read from an ANAF-published document; the
URL is given so it can be re-checked. **Nothing here is inferred or assumed.**

## Reachability (from this environment, 2026-09-08)

| Host | Result |
|------|--------|
| `static.anaf.ro` | 200 — reachable, serves the technical docs |
| `www.anaf.ro` | 200 — reachable |
| `legislatie.just.ro` | **403 without a browser User-Agent · 200 with one** |

> **M3 fetcher requirement:** `legislatie.just.ro` rejects plain HTTP clients. The crawler must send
> a realistic `User-Agent` (and `Accept-Language: ro-RO`). Verified: 403 → 200 on that change alone.

## Documented SPV web services

Source: `https://static.anaf.ro/static/10/Anaf/Informatii_R/Prezentare_WS_SPV.txt`
(published 2018-02-21; retrieved 2026-09-08). ANAF also publishes a reference client at
`https://github.com/MfpAnaf/ClientSPV`.

**Exactly two operations are documented — no more:**

| # | Operation | Call |
|---|-----------|------|
| 1 | List available messages | `https://webserviced.anaf.ro/SPVWS2/rest/listaMesaje?zile=<days>` |
| 2 | Download one message | `https://webserviced.anaf.ro/SPVWS2/rest/descarcare?id=<id>` |

`listaMesaje` returns JSON: `titlu`, `mesaje[]` (each with `id`, `detalii`, `cif`, `data_creare`,
`id_solicitare`, `tip`), plus `cnp`, `cui`, `serial` of the authenticating certificate. Errors come
back as `{"titlu":…, "eroare":…}` — an error is HTTP-200-shaped JSON, so **response parsing must
check for `eroare`, not just the status code.**

`descarcare` returns a **PDF**, or the same JSON error shape.

**Authentication: a qualified digital certificate** (*certificat digital calificat*). The
certificate holder gets access to every CIF/CNP they are authorised for.

## OAuth-protected services

Source: `https://static.anaf.ro/static/10/Anaf/Informatii_R/Servicii_web/servicii_oauth.html`
(updated 2024-04-12; retrieved 2026-09-08).

- `logincert.anaf.ro` — Identity Provider issuing access tokens, **authenticated by qualified
  digital certificate**.
- `api.anaf.ro` — token-protected APIs.
- The documented OAuth-protected APIs are **RO e-Factura** and **RO e-Transport** only.

## What this means for the project

**1. ADR 0003 (Playwright for SPV PF) is confirmed, not overturned.** There is **no documented
public API for submitting D212**, and no documented API path for the PF *username + password +
security-code* flow described in `BLUEPRINT.md` §13.2. Both documented auth routes require a qualified
digital certificate. For an ordinary PF user without one, the portal remains the only channel —
exactly what §13.1 anticipated.

**2. A real optimisation exists for certificate holders.** Inbox listing and message download —
two operations §13 assumed would need browser automation — have an **official, stable, documented
API** for users who hold a qualified certificate. That is strictly better than scraping: no DOM
fragility, no session juggling. Worth building as an alternative transport behind the same
interface, once the PF path works. Recorded here so M10 does not overlook it.

**3. Blocked item #2 is narrowed, not closed.** We now know ANAF publishes **no** documented D212
submission API. The artifact format the portal itself accepts is still unverified and must be
observed, never guessed.

## Explicitly NOT established

- The D212 submission artifact format.
- Whether ANAF's official validator can run in CI.
- Any SPV portal DOM detail, field name, or error code.
- Whether `webserviced.anaf.ro` is the current production host (the document dates from 2018;
  it has not been called, because doing so requires a qualified certificate we do not have).
