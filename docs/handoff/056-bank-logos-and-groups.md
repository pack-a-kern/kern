# 056 — Bank logos from a folder, each bank's group, and a Banks section in the settings

**Milestone:** — (outside the milestones)   **Status:** complete
**Date:** 2026-11-05

## What was built

- **Logos.** `Kern\TaxRomania\Banks\Logos\BankLogos` reads `BANKS_LOGOS_PATH` (by default
  `storage/app/private/bank-logos`): a folder per country, named by the bank's country code, and a
  file per bank named after its BIC (first eight characters) or LEI — `RO/BTRLRO22.svg` — or, for a
  branch with neither, its parent's LEI. The index is kept in the cache. `GET /api/v1/banks/{bank}/logo` serves one.
  Every bank the API returns carries `logo_url` — null without a folder or a file.
- **Groups.** `Banks\Services\BankGroups`, called at the end of `ImportBanks`, asks GLEIF's API for
  each bank's ultimate parent: BCR → Erste Group Bank. Two new columns, `group_name` / `group_lei`.
- **Settings.** A `BANKS` group with `banks.countries` (`COUNTRY_LIST`). The Banks section of Admin →
  Settings: the countries; where the logo folder is and what goes in it; "N of M banks have a logo";
  the banks without one and the file each would need; and "Read the folder again"
  (`POST /api/v1/settings/banks/logos/refresh`, or `kern:banks:logos`). The folder is not chosen there:
  a picker was built, then removed (ADR 0023).
- **Screens.** `components/wallet/BankMark.tsx` — the logo through `<img>`, or the bank's initials on
  a hue of its own, in three sizes. Shown in the four bank selects (with the group and "closed"
  beneath the name), the accounts list beside a deposit's bank, the transactions list beside a row's
  bank, and the dashboard's deposits by bank (`logo_url` added there by `ShowWalletOverviewController`
  through `BankDirectory::findMany()` — the wallet's own query knows nothing of banks, as Deptrac
  requires).

The plan to make the bank list a package of its own (`docs/BANKS-PACKAGE-PLAN.md`) was dropped: the
directory stays a module of `kern/tax-romania`, and the plan file is removed.

## Files created / changed

- `packages/tax-romania/src/Banks/Logos/{BankLogos,BankLogo,LogoCoverage}.php` — new.
- `packages/tax-romania/src/Banks/Services/BankGroups.php` — new; `BankNameBuilder::fromRegisteredName()`
  (and `build()` now uses it for a bank's own name).
- `packages/tax-romania/src/Banks/{Actions/ImportBanks,DataTransferObjects/BankImportSummary,DataTransferObjects/BankOption,Queries/BankDirectory,Models/Bank}.php`
- `packages/tax-romania/src/Http/Controllers/Banks/{ShowBankLogo,ShowBankLogos,RefreshBankLogos}Controller.php`, routes.
- `packages/tax-romania/src/Console/Commands/Banks/RefreshBankLogosCommand.php` (`kern:banks:logos`).
- `packages/tax-romania/database/migrations/2026_11_05_200000_add_the_group_to_ext_banks.php`.
- `packages/tax-romania/config/banks.php` (`logos.path`, `logos.max_bytes`), `.env.example` (`BANKS_LOGOS_PATH`), `README.md`.
- `packages/legislation/config/sources.php` — GLEIF: `api.gleif.org` allowed, `ultimate_parent_template`.
- `packages/platform/src/Settings/Enums/{SettingKey,SettingGroup,SettingType}.php`,
  `Services/{StoredSettingsOverlay,ResolveSettings}.php`, `UpdateSettingsGroupController.php`
  (the currency and country lists share one parser).
- Frontend: `components/wallet/{BankMark.tsx,bankOptions.tsx}`, `lib/bankMark.ts`, `types/{wallet,settings}.ts`,
  `api/settingsApi.ts`, `pages/admin/settings.tsx` (`BanksPanel`), `css/app.css` (`.bank-tile`), en/ro.
- Tests: `BankLogosTest` (new), `ImportBanksTest` (groups), `BankNameBuilderTest`, settings tests,
  `lib/__tests__/bankMark.test.ts`, `types/settings.test.ts`, `components/wallet/__tests__/BankMark.test.tsx`.
- ADR 0023; `docs/STATUS.md`.

## Key decisions

See ADR 0023.

- **No logo service.** logo.dev was tried: its name lookup gave BCF for BCR, storing is allowed only
  through its API at 2 credits a request against 500 a month free, and it forbids redistribution.
- **No short names, no LEI lookup by name.** GLEIF's `otherNames` are *former* names (Credex was
  Porsche Bank Romania), not "BCR"; and of the 38 active banks without an LEI, 30 are cooperatives the
  selects do not offer and 8 are foreign branches, which are not legal entities. Checked 2026-11-05.
- **The group is not `parent_name`.** That column says whose *branch* a row is, and the name builder
  depends on it; a subsidiary is a bank of its own.
- **GLEIF's answer is read as JSON** even though, asked with the fetcher's `Accept: application/xml…`,
  it labels it `text/xml`. 404 is "no parent".
- **A group failure never undoes an import**: the list is written first; one bank's failure is counted
  and logged by class, the rest carry on.

## How to verify

```
make check-php        PASS 273 files · PASS 1368 files · phpstan [OK] No errors · deptrac 0 violations
                      Tests: 120 skipped, 1431 passed (4935 assertions)
make check-php-pg     Tests: 12 skipped, 1539 passed (5393 assertions)
make check-packages   audit 12 · notifications 31 · platform 106 · documents 95 (3 warnings, ClamAV-unreachable tests) ·
                      legislation 82 · tax-romania 156
make check-js         tsc ok · eslint 0 errors (7 warnings, files this did not touch) · prettier ok ·
                      vitest 39 files, 308 passed · vite build ok
```

By hand: `mkdir -p apps/api/storage/app/private/bank-logos/RO`, put `BTRLRO22.svg` there, press "Read
the folder again" in Admin → Settings → Banks, and the Banca Transilvania option in any bank select
shows its logo.

## Interfaces exposed to other modules

`BankOption::$group`, `BankOption::$logoUrl` (`group`, `logo_url` in every bank list);
`GET /api/v1/banks/{bank}/logo`; `GET /api/v1/settings/banks/logos`,
`POST /api/v1/settings/banks/logos/refresh`; `SettingType::COUNTRY_LIST`,
`isCountryCode()`, `decodeCountryList()`.

## Threat model

| Threat | Answer |
|---|---|
| A typed value becomes a path | none can: the folder is server configuration, and the rest of a file's path is the bank's country code and BIC/LEI, matched by `\A…\z` patterns |
| A file or country folder links outside the folder | links refused, checked at index and again at serve; a resolved path must be inside the folder; a file swapped for a link after indexing is refused; tested |
| A request names a file | the file is looked up by the bank's id in the index, never built from the request; a non-UUID path is 404 |
| A logo carries script | `<img>` only; `Content-Security-Policy: default-src 'none'; sandbox`, `nosniff`; content checked against its type; 200 KB cap |
| Someone else changes the countries, or refreshes | `system.settings.manage` (and `.view` to read); tested 401/403 |
| Who banks where reaches a third party | nothing about a user leaves the server; GLEIF is asked about banks |

## What is NOT done

- No upload on the settings screen: files are put in the folder by hand or with a deploy.
- The dashboard's deposits-by-bank list falls back to its 4-letter code badge (`.bank-mark`) where a bank has no logo.
- Not checked in a browser.
- Not committed.

## Open questions

None.

## Sources consulted

GLEIF API (`api.gleif.org/api/v1/lei-records`, `…/ultimate-parent`), checked 2026-11-05; logo.dev
docs (fair use, self-hosting, pricing, search); Wikidata query service; BNR register pages.
