# Plan — `kern/banks`: the bank directory and its logos, as a package

**Status:** proposed, not started
**Date:** 2026-11-05

## Goal

The list of banks — where it comes from, how it is kept current, what each bank looks like on screen —
in one package any Laravel application can install with `.env` alone. This product plugs its own
settings screen and evidence store in on top, as it does for `kern/notifications` (ADR 0022).

## What moves out of `kern/tax-romania`

| Today (`Kern\TaxRomania\Banks\…`) | In the package (`Kern\Banks\…`) |
|---|---|
| `Actions/ImportBanks`, `Actions/BackfillBanks` | same |
| `Parsing/*` (ECB list, GLEIF BIC↔LEI, legal forms, zip, rows) | same |
| `Services/BankSources`, `BankKindResolver`, `BankNameBuilder` | same |
| `Models/Bank`, `Enums/BankKind`, DTOs, exceptions | same |
| `Queries/BankDirectory` | same |
| `Console/Commands/Banks/*` (`kern:banks:import`, `:backfill`) | same names |
| `config/banks.php` | the package's config |
| migration `2026_09_15_500000_create_ext_banks_table` | same file name, `ext_banks` only |
| the IBAN → bank part of `StatementImport/Services/GuessStatementBank` | `Queries/BankFromIban` |

**Stays in tax-romania:** the wallet controllers, deposits, transactions, the statement import's
guess from the AI's reading, the deposit notice — they use the package's `Bank` model. The
`deposit_terms.bank_id` foreign key leaves the `ext_banks` migration for a new tax-romania migration
that adds it only where it is missing, so existing databases change nothing and fresh ones end up the same.

**Downloading the source files.** `ImportBanks` fetches through `kern/legislation`
(`AllowlistedHttpFetcher`, `SourceIngestionService`), which keeps every file as evidence. The package
cannot require that, so it asks through a contract, `Contracts\FetchesSources`:

- the package's default: Laravel's HTTP client, https only, the configured hosts only, size and
  time limits;
- this product: a binding onto `kern/legislation`'s fetcher and evidence store, as today, so nothing
  about evidence changes.

## Bank data: three additions, all from GLEIF

GLEIF is free, needs no key, and is called by the server during an import only, for banks that are
new or changed — never per request. Only what a screen shows is stored; nothing "for later".

| Addition | GLEIF source | Stored in |
|---|---|---|
| **The missing LEIs** — 22 of 60 active Romanian banks have one today, because it comes only from the BIC↔LEI file | `lei-records?filter[entity.legalName]=…&filter[entity.legalAddress.country]=…` | the existing `lei` column |
| **The parent group** — BCR · Erste Group | `lei-records/{lei}/ultimate-parent` | the existing `parent_name` / `parent_lei`, empty today for every subsidiary |
| **The short name** — "BCR" | `lei-records/{lei}` → `entity.otherNames` | one new column, `short_name` |

- **Identity is the LEI** (ISO 17442: global, one per legal entity, never reused). The BIC stays what
  statements and IBANs are matched on.
- **An LEI found by name is kept only on an exact match:** one result, same country, legal name equal
  once case, punctuation and the legal-form suffix are set aside. Anything else stays empty — no
  guess, nothing to review.
- The ECB file's `HEAD_*` columns are a *branch's* head office, which is why a subsidiary such as BCR
  has no parent today. Checked 2026-11-05: GLEIF gives BCR's ultimate parent as ERSTE GROUP BANK AG
  (`PQOH26KWDF7CG10L6792`).
- Not stored: successor, legal form, register number, headquarters, founding date, all BICs, LEI
  renewal, subsidiaries. No screen shows them; a feature that needs one reads it then.

## Logos — a folder the installation fills

**The package ships no logos.** Logos are the banks' trademarks: showing a bank's logo in one's own
application to identify it is ordinary, shipping the files to everyone who installs a package is not
ours to decide. And no logo service: no key, no credits, no request leaving the server, no wrong
match. The package is told where a folder is, and matches files in it to banks.

**The folder:**

```
storage/app/bank-logos/          ← BANKS_LOGOS_PATH on BANKS_LOGOS_DISK (here: the Banks card)
├── RO/
│   ├── BTRLRO22.svg
│   ├── RNCBROBU.webp
│   └── WBANRO22.png
└── BG/
    └── UNCRBGSF.webp
```

- **A file is named after the bank's BIC**, its first eight characters (no branch code); a bank
  with no BIC by its LEI. The BIC is stored for every bank, is what an IBAN leads to, and is written
  one way only; a short name is not.
- **The country folder is for whoever fills it** — the BIC already holds the country.
- `.svg`, `.webp`, `.png`; two files for one bank, that order wins. At most 200 KB.

**What the package does:**

1. **An index**, BIC → file and its fingerprint, built by reading the folder and kept in the cache.
   Rebuilt by "Refresh" on the Banks card or `kern:banks:logos`; never on a schedule, never per
   request.
2. **Serving**, `GET {prefix}/banks/{bank}/logo`, from that folder: long cache, the fingerprint as
   etag. SVG is sent with `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'`
   and `X-Content-Type-Options: nosniff`, and the screen shows every logo through `<img>`, where an
   SVG's scripts never run. A bank with no file is a 404.
3. **`logo_url` on every bank** the API returns, always present:

   | | `logo_url` |
   |---|---|
   | no folder configured | `null` for every bank |
   | no file for this bank | `null` |
   | a file | `/api/v1/banks/{id}/logo?v=<fingerprint>` — a replaced file gets a new address |

   `null` means the screen draws the initials.

Local storage, not the object store: some dozens of files of tens of KB, public reference data. The
app container serves them; the folder is read nowhere else.

## Configuration

**Installed anywhere else — `.env` only:**

```
BANKS_COUNTRIES=RO
BANKS_LOGOS_DISK=local
BANKS_LOGOS_PATH=          # empty: no logos, every logo_url null
```

**This product — on the settings screen** (ordinary settings, put in `config()` by the settings
overlay; nothing secret, so nothing enciphered):

- `banks.countries`
- `banks.logos.path`

**A "Banks" card in Admin → Settings:** the countries; the logo folder; "Logos: 31 of 87 banks"; the
banks without one, with the file name each would need (`RO/RNCBROBU.*`); "Refresh". No upload for now:
files go into the folder by hand or with a deploy.

## Frontend

- `components/wallet/BankMark.tsx` — the logo, or the initials, at the dropdowns' size.
- `bankOptions` (the four bank selects), the accounts list, deposits, transactions and the import
  screen show it in place of today's dot. Whether the bank is closed moves to the description line
  it already has ("closed"), and a closed bank's mark is greyed.
- `types/wallet.ts` — `logoUrl: string | null` on `Bank`.

## Security

| Threat | Answer |
|---|---|
| A logo file carries a script | shown through `<img>` only; SVG served with `default-src 'none'` and `nosniff`; size cap |
| A path in a request reaches another file | the file is looked up in the index by the bank's id, never built from the request |
| The folder setting points somewhere it should not | a path relative to the configured disk only; `..` and absolute paths refused |
| Someone else changes the folder or countries | `system.settings.manage`, as every setting |
| Who banks where reaches a third party | nothing leaves the server for logos; GLEIF is asked about banks, never about users |

## Tests

- Package suite (Testbench, Postgres), as `kern/notifications`: import and backfill moved with their
  tests; GLEIF additions with a fake HTTP (LEI kept on an exact match only, parent, short name);
  the index (BIC and LEI names, format order, size cap, files that match no bank); serving
  (headers, 404); `logo_url` for each row of the table above.
- App: the Banks card endpoints (permission, the path rule); the bank lists carry `logo_url`;
  existing wallet and statement-import tests unchanged and passing.
- Frontend: `BankMark` (logo, initials, same colour for the same BIC), `bankOptions`.

## Docs

- `packages/banks/README.md` — what it does, installing, `.env`, the GLEIF additions, the logo folder
  and its naming, the `FetchesSources` contract, commands, a demo with made-up banks only, and a
  note that logos are their owners' trademarks and the package ships none.
- ADR 0023 — the bank directory is a package, and logos come from a folder the installation fills.
- A handoff note; `docs/STATUS.md`; `Makefile` package lists; `phpstan.neon`; Deptrac layers.

## Order of work

1. Commit what is uncommitted now (notifications package, CNP, bell empty state, tabs).
2. Move the directory into `kern/banks` with no behaviour change; every suite green.
3. GLEIF additions: missing LEIs, parent, short name.
4. Logos: the index, serving, `logo_url`, `.env` defaults.
5. Platform: the two settings and the Banks card.
6. Frontend: `BankMark` everywhere a bank is shown.
7. README, ADR, handoff; all suites at the end.

## Open questions

None.

## Considered and dropped

- **logo.dev**, by name or by domain. Name lookup took BCF for BCR; storing is allowed only through
  its API, 2 credits a request against 500 a month on the free plan; its terms forbid redistributing
  what it returns. A folder needs none of that.
- **Storing every GLEIF field.** Nothing would show it.
