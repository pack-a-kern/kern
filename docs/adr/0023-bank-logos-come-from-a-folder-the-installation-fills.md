# ADR 0023 — Bank logos come from a folder the installation fills

**Status:** accepted
**Date:** 2026-11-05

## Context

The bank selects showed a dot for whether a bank still exists. A logo reads faster, and a bank list
of eighty-seven names is scanned, not read. Three ways to get logos were weighed.

- **A logo service, called from the browser** (logo.dev's CDN): every user's browser tells a third
  party which banks that person has, and the CSP opens to a new host.
- **The same service, downloaded once by the server and cached.** Allowed only through its API, two
  credits a request against 500 a month on the free plan; its lookup by name took BCF for BCR on
  2026-11-05; and its terms forbid redistributing what it returns.
- **Files the installation provides.** No key, no credit, no wrong match, no request leaving the
  server. A logo is its bank's trademark: showing it in one's own application to identify the bank
  is ordinary, shipping it to everyone who installs the code is not this repository's to decide.

No free, open source carries bank websites to look logos up by (ECB, GLEIF, Wikidata, BNR checked on
2026-11-05), so a service would also need a hand-kept list of domains.

## Decision

**Logos are files in one folder**, `BANKS_LOGOS_PATH` (by default `storage/app/private/bank-logos`),
with a folder per country inside it named by the bank's own country code, and one file per bank named
after its BIC (first eight characters) or its LEI — `RO/BTRLRO22.svg`. A branch of a foreign bank
with neither — it is no legal entity, and GLEIF may map it no BIC (Nexent's, opened 2025) — is named
after its parent's LEI, whose logo it is. SVG, WebP or PNG, by content as
well as by name; at most 200 KB. None ship with the code; none is downloaded.

**Nothing typed becomes a path.** The folder is the server's configuration, not a setting on the
screen; everything after it comes from the bank's row (two capital letters, then the BIC or LEI). Links
are not followed — not a country folder, not a file — and a file is read only when its resolved path is
inside the folder, checked when the folder is indexed and again when it is served.

**Served by the application**, `GET /api/v1/banks/{bank}/logo`, found by the bank and never from the
address, with `Content-Security-Policy: default-src 'none'; sandbox`, `nosniff`, and shown only through
`<img>` — so an SVG can be drawn and nothing else. The address carries the file's fingerprint and is
cached for good.

**`logo_url` on every bank**, null where there is no file; the screen then draws the bank's initials
on a hue of its own.

**The countries the bank list is kept for** — the ECB's file covers the whole EU — become a setting
(`banks.countries`, a `COUNTRY_LIST`: ISO 3166-1 codes as ICU knows them).

**A folder picker was built and taken out the same day.** A chosen folder with country folders inside
it read as the same country twice (`ro/RO/`), and a folder chosen on a screen is a path a person
types, however narrowly it is checked. The settings screen says where the folder is instead.

## Consequences

- A new installation has no logos until someone creates the folder and puts files in it; every bank
  reads with its initials meanwhile.
- The Banks section of the settings screen lists the banks still without a logo and the file name
  each would need.
- Adding a logo is copying a file and pressing "Read the folder again" (or `kern:banks:logos`).
- The bank's group (GLEIF's ultimate parent) is shown beside it in the selects — "Erste Group Bank"
  for BCR — read once per import, one request per bank with an LEI.
