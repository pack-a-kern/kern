# kern/tax-romania

Romanian tax for an individual — a *persoană fizică* — from the taxpayer to the declaration: who the
taxpayer is, what they own and earn, what the law says that costs, and what is filed.

Every figure it produces traces back to a value someone approved, which traces to a paragraph of a
law, which traces to the bytes of the consolidation that paragraph was read from. That chain is the
product; the tax arithmetic is the easy part.

> **AI can read, classify, extract and explain. AI never decides a tax number.** Every amount owed comes
> from deterministic, versioned, tested rules over approved values. A model may suggest a category or
> read a statement's columns; a person accepts it, and the rules do the sum.

---

## Contents

- [What it gives you](#what-it-gives-you)
- [Installing](#installing)
- [A first walk through](#a-first-walk-through)
- [The modules](#the-modules)
- [Background tasks and commands](#background-tasks-and-commands)
- [Configuration](#configuration)
- [Security and data rules](#security-and-data-rules)
- [What it deliberately does not have](#what-it-deliberately-does-not-have)
- [Testing](#testing)

---

## What it gives you

| Module | In one line |
| --- | --- |
| `Subjects` | the taxpayer, who may act for them, and their CNP — read, checked and encrypted |
| `FiscalParameters` | the values a tax is computed from, read out of the law and approved by a person |
| `TaxEngine` | deterministic, versioned rules: income tax, CASS, CAS — never a model's output |
| `Wallet` | accounts, term deposits, transactions, splits, categories, counterparties, tags, reports |
| `StatementImport` | a bank's own statement becomes transactions, its columns mapped once per bank |
| `Fx` | the BNR's reference rates, kept as evidence, and the period averages the law asks for |
| `Banks` | the bank list from the ECB and GLEIF, each bank's group, and a logo per bank |
| `Rental` | rental contracts, the rent they owe, and the accruals that follow |
| `Declarations` | the forms, their versions, drafts, submissions and corrections |
| `Spv` | ANAF's own mailbox (SPV), read through a browser because there is no API |
| `Operations` | the scheduled tasks that keep all of that current, and their run history |
| `Notices` | the kinds of notice it sends — a statement import moved on, a deposit matures — on `kern/notifications` |

One package rather than twelve, deliberately. These modules have one consumer between them — each
other — and `StatementImport` depends on most of the rest precisely because it is where a wallet and a
document meet. Splitting further buys version numbers and cross-repository test matrices, and buys no
reuse: nobody wants Romanian rental accruals without Romanian income tax.

The boundaries *inside* it are still enforced. The installing application's `deptrac.php` declares one
layer per module, every crossing is a declared grant with its reason beside it, and a module never
imports another module's Eloquent model.

---

## Installing

```json
"require": { "kern/tax-romania": "^1.0" }
```

It requires `kern/platform` (identity, tenancy, encryption, settings), `kern/audit` (the append-only
chain), `kern/documents` (stored files and their text), `kern/legislation` (official sources and the
law) and `kern/notifications` — the way an application requires `illuminate/*`. The service provider
is discovered by Composer; it merges its config, loads its migrations, translations (`en`, `ro`) and
routes, and registers its commands.

**1. Say what a tenant is.** This package is why the tenant is a taxpayer, but the word belongs to the
installation, so `kern/documents` can be told the same thing:

```php
// config/tenancy.php
return ['column' => 'tax_subject_id'];
```

**2. Migrate**, as the database's owner role:

```bash
php artisan migrate
```

**3. Write the environment.** Every variable it reads is in its `.env.example`, with what it does:

```bash
php artisan kern:env    # prints the application's and every package's variables together
```

**4. Let the scheduler run.** The bank list, the exchange rates, deposit interest and reminders, the
SPV mailbox and the audit chain's verification are tasks (see
[below](#background-tasks-and-commands)); they need `php artisan schedule:work` (or cron) and a queue
worker.

**5. Seed what the rules need.** Register the tax rules this version ships, then import the year's
rates:

```bash
php artisan kern:tax:register-rules      # each rule as a CANDIDATE version, for a person to approve
php artisan kern:fx:backfill 2025        # BNR reference rates, from 2025 to this year
php artisan kern:banks:import            # the bank list, from the ECB and GLEIF
```

The routes mount under `config('kern.routing.prefix')` (`api` by default) with its middleware
(`web`), so every path below is `/api/v1/…` and every call carries the signed-in session.

---

## A first walk through

The data below is made up — the CNP is built to pass the check digit and belongs to no one.

**Register a taxpayer.** The CNP is read first, so the form can show what it says before anything is
saved:

```http
POST /api/v1/subjects/cnp
{ "cnp": "1850307400125" }
```

```json
{
  "sex": "MALE",
  "foreign_resident": false,
  "birth_date": "1985-03-07",
  "county_number": "40",
  "county_code": "B",
  "locality": null
}
```

```http
POST /api/v1/subjects/pf
{
  "display_name": "Ion Exemplu",
  "first_name": "Ion",
  "last_name": "Exemplu",
  "cnp": "1850307400125",
  "county_code": "B",
  "locality": "București",
  "address": "Str. Exemplu nr. 1"
}
```

The caller becomes the taxpayer's `OWNER`. The CNP, name and address are stored encrypted under the
taxpayer's own key; the CNP is found again only through a keyed blind index, so a second registration
of the same CNP is refused without the number ever being searched for.

**Open an account and record income.** Every route below lives under
`/api/v1/subjects/{subject}/…` and answers only for a taxpayer the caller acts for:

```http
POST /api/v1/subjects/{subject}/wallet/accounts
{ "display_name": "Cont curent", "kind": "GENERAL", "currency_code": "RON" }
```

```http
POST /api/v1/subjects/{subject}/wallet/transactions
{
  "account_id": "…",
  "amount_minor": 450000,
  "currency_code": "EUR",
  "booked_at": "2025-03-14",
  "description": "Chirie martie",
  "category_code": "RENT"
}
```

Money is always an integer of minor units — `450000` is €4,500.00 — and a foreign amount is converted
with the BNR rate the rules name for that kind of income, never a rate typed in.

**Or import a statement** instead of typing it: upload the bank's own CSV, XLSX, XLS or PDF, map its
columns once (the mapping is remembered per bank), review the rows, commit.

```http
POST /api/v1/subjects/{subject}/wallet/imports            (multipart: file)
PUT  /api/v1/subjects/{subject}/wallet/imports/{import}/mapping
POST /api/v1/subjects/{subject}/wallet/imports/{import}/commit
```

**Assess the year.**

```http
POST /api/v1/subjects/{subject}/tax/assessments
{ "fiscal_year": 2025 }
```

The answer is each rule's result with its evidence: which transactions, which approved values (each
with the provision it was read from), which exchange rates. `tax/assessments/export` hands back the
figures or the evidence as a file; `tax/assessments/final` marks the year final, after which its
transactions cannot change until it is unlocked.

---

## The modules

### Subjects — the taxpayer

- **The CNP is read, not just matched** (`Subjects\Support\CnpParser`): length, the check digit
  (weights `279146358279`, modulo 11), the first digit's sex and century, a birth date that exists and
  has come, and the county code — 01–52, the former Bucharest sectors 47 and 48, and 70, which every CNP
  generated since 8 May 2024 carries in place of a county. A foreign resident (7/8) is given the
  century that does not put the birth in the future. A non-resident (9) is refused on purpose: this
  product computes resident taxation only.
- **Each refusal has a reason code** (`CNP_CHECKSUM`, `CNP_FUTURE_BIRTH_DATE`, …), which the screen
  translates.
- **Who may act** for a taxpayer is a membership with a role; every query a business module makes is
  scoped to the taxpayer in context (`tax-subject` middleware), and crossing it is tested.

### FiscalParameters — the values

A rate, a ceiling, a minimum wage multiple: each is a versioned value with the provision it was read
from (`source_section_id`) and the dates it applies to. A value read out of the law starts as a
`CANDIDATE`; a person approves it (`fiscal.parameters.approve`), and only approved values reach a
computation. A year with no approved value for something a rule needs is refused, with a reason, rather
than computed with a guess.

### TaxEngine — the rules

`TaxEngine\Rules\*`: income tax on rent, on independent activity and on crypto gains; CASS on
independent activity and on other income; CAS on independent activity. Each rule is as near a pure
function as PHP allows — no HTTP, no database, no clock — fed the year's facts, the approved values and
the rates, and each version is registered (`kern:tax:register-rules`) and approved before it runs.
Ports (`TaxEngine\Contracts\*`) are what the engine reads through; other modules implement them, so the
engine knows nothing of wallets or rentals.

### Wallet — what the taxpayer owns and earns

Accounts of six kinds (`GENERAL`, `CASH`, `TERM_DEPOSIT`, `BROKER`, `CRYPTO`, `OTHER`); term deposits
with their bank, rate, method and maturity — opened one by one or from a CSV — whose interest is posted
when it is paid; transactions with splits across categories (`RENT`, `FREELANCE`, `DIVIDENDS_INTEREST`,
`CAPITAL_GAIN`, …), counterparties a person can merge, tags, duplicate detection, an overview in a
display currency, and reports exportable as files. Every change is recorded in the taxpayer's audit
chain.

### StatementImport — a bank statement becomes transactions

Upload → read → map the columns → review the rows → commit, as one resource with a state. The mapping
is remembered per bank, so the second statement from the same bank goes straight to review. The bank
is recognised from the statement's own IBAN (`RO49 BTRL …` → Banca Transilvania); categories and
counterparties may be *suggested* by a model, with the taxpayer's consent, and are never applied
without a person accepting them. A committed import can be taken back out of the ledger.

### Fx — the BNR's rates

The daily XML and the yearly archives, fetched through `kern/legislation`'s allowlist and kept as
evidence (content-addressed, versioned), so every converted amount names the file its rate came from.
`Fx\Services\FxRateQuery` is the one way other modules ask for a rate.

### Banks — the bank list, groups and logos

- **The list** is the ECB's list of credit institutions for the configured countries (`banks.countries`),
  with each bank's BIC from GLEIF's BIC-to-LEI file and its name built from the registered one
  ("BANCA TRANSILVANIA S.A." → Banca Transilvania). A bank that leaves the list becomes inactive and is
  never deleted — a deposit may name it. `kern:banks:backfill` adds the banks that closed before the
  first import, from the ECB's archive.
- **The group** each bank belongs to is GLEIF's "ultimate parent": BCR → Erste Group Bank.
- **The logos** are files the installation puts in one folder (`BANKS_LOGOS_PATH`, by default
  `storage/app/private/bank-logos`): a folder per country, named by the bank's country code, and one
  file per bank named after its BIC (first eight characters) or LEI — `RO/BTRLRO22.svg`; a branch with
  neither (it is no legal entity) is named after its parent's LEI. None ship with the package: a logo
  is its bank's trademark.
  Every bank in an API answer carries `logo_url`, null without a file; the screen then draws the bank's
  initials.

```
storage/app/private/bank-logos/      ← BANKS_LOGOS_PATH
├── RO/                              ← the bank's country code
│   ├── BTRLRO22.svg                 ← its BIC's first eight characters, or its LEI
│   └── RNCBROBU.webp
└── BG/
    └── UNCRBGSF.png
```

```json
{ "id": "…", "name": "Banca Comerciala Romana", "code": "RNCB", "country_code": "RO",
  "active": true, "group": "Erste Group Bank", "logo_url": "/api/v1/banks/…/logo?v=3f9c2a1b7d04" }
```

### Rental — contracts and their rent

A contract's income is what the contract owes, computed from its own terms (Codul fiscal art. 84), not
from transactions; accruals are recorded as the months pass and reach the engine as events.

### Declarations — the forms

Form definitions and their versions, drafts built from an assessment, validation, submission and the
response it received, and a rectificative declaration that corrects one already filed — never an edit
to it.

### Spv — ANAF's mailbox

ANAF's Spațiul Privat Virtual has no API for an individual, so a browser worker signs in and reads the
messages, following declarative playbooks (`config/playbooks`). Credentials are encrypted, a security
code is asked of the person when the portal asks for one, and every act against the authority is
recorded as somebody's.

### Operations — the tasks

Every background task is an `Operations\Enums\OperationTask`, scheduled from
`config/operations.php`, run on the queue, and recorded: each run's outcome, duration and output, for
the "Tasks" screen. Starting one by hand needs `operations.run`; reading their history,
`operations.view`.

### Notices — what it tells people

`Notices\StatementImportNotice` (an import moved on or stopped) and `Notices\DepositMaturityNotice`
(a deposit matures soon), on `kern/notifications`: each writes its own words when shown, and is shown
only while the reader still acts for the taxpayer it is about.

---

## Background tasks and commands

| Command | What it does | Scheduled as |
| --- | --- | --- |
| `kern:fx:import-daily` | today's BNR rates | `fx.import_daily` |
| `kern:fx:backfill {year}` | BNR rates from that year to this one (`--dry-run` checks the address only) | `fx.backfill_year` |
| `kern:banks:import` | the bank list from the ECB and GLEIF, then each bank's group | `banks.import` (monthly) |
| `kern:banks:backfill` | banks that closed before the first import | `banks.backfill` |
| `kern:banks:logos` | read the logo folder again, after files were added or replaced | — |
| `kern:wallet:post-deposit-interest` | post the interest of deposits that paid it | `deposit.post_interest` |
| `kern:wallet:remind-maturities` | notice a deposit's owner before it matures | `wallet.maturity_reminders` |
| `kern:counterparties:classify` | queue the counterparties nobody has answered, for a model to suggest a category | `counterparties.classify` |
| `kern:counterparties:backfill`, `kern:wallet:index-counterparties` | one-off upkeep after an upgrade | — |
| `kern:spv:close-expired-sessions` | end SPV sessions past their time | `spv.close_expired_sessions` |
| `kern:tax:register-rules` | register a candidate version of each rule that has none | — |

The schedule also runs other packages' tasks (`kern:audit:verify`, `kern:legislation:sync`,
`kern:access:sync`, …), so one screen shows everything the installation does on its own.

---

## Configuration

Each file is merged under the application's own; every value in force is in `.env.example`.

| File | What it decides |
| --- | --- |
| `subjects.php` | the default country, and how many taxpayers an account may hold |
| `fiscal_parameters.php` | how values are read out of the law and approved |
| `wallet.php` | deposits: the terms offered, and the limits of opening them from a CSV |
| `statement_import.php` | formats (CSV, XLSX, XLS, PDF), the parser's limits, rows and rate limits |
| `banks.php` | the countries and categories kept, kinds of institution, the backfill, the logo folder (`BANKS_LOGOS_PATH`) |
| `operations.php` | each task's schedule (`OPERATIONS_CRON_*`), staleness rules |
| `spv.php`, `browser_worker.php`, `playbooks/` | the SPV mailbox and the browser that reads it |
| `ai/statements.php` | what a model is asked about a statement, when consented |
| `reference/*` | facts about Romania: counties, countries, months, legal-form words, tax categories |

**Settings screen.** Some values can also be changed on the application's settings screen
(`kern/platform`'s settings), which stores them and puts them in front of `.env` — the bank countries
(`banks.countries`) among them. The logo folder is not one of them: it is the server's
`BANKS_LOGOS_PATH`, and the rest of a logo's path comes from the bank's own row, so nothing anyone
types becomes a path. The reader follows no link and checks every file again when it serves it.
`GET /api/v1/settings/banks/logos` says where the folder is, whether it exists, and which banks still
have no logo, with the file each would need; `POST …/refresh` reads the folder again.

---

## Security and data rules

- **Tenant isolation.** Every business query is scoped to the taxpayer in context; a route under
  `subjects/{subject}` answers only a member of that taxpayer. Cross-tenant isolation has tests.
- **Encryption at the application level** for the CNP, names, addresses, IBANs, SPV credentials and
  identifying transaction details — each under the taxpayer's own key, bound to its row, so ciphertext
  moved to another row does not decrypt. Lookups go through keyed blind indexes, never a bare hash.
- **An audit chain** per taxpayer: account, transaction, rental, approval and SPV changes are appended,
  hash-linked, and verified on a schedule. Nothing in it is ever updated or deleted.
- **Official sources are evidence.** Every file a figure depends on — BNR rates, the ECB and GLEIF
  lists, the law — is downloaded through an allowlist (https, known hosts, size and time limits) and
  kept, never overwritten: a new version is a new row.
- **Consent before AI.** A document or a statement is sent to a model only after the taxpayer has
  consented, and the sending is recorded; a model's answer is validated against a schema and stays a
  suggestion until a person accepts it.
- **No float for money**, anywhere: integer minor units, and decimal strings for rates.

---

## What it deliberately does not have

**Screens.** No Blade, no Inertia pages, no navigation. It ships actions, jobs, models, migrations,
config and JSON routes; what the user sees belongs to the application, which is what makes replacing
React with something else a change to one directory.

**PFA or SRL.** A `pfa_profiles` table exists and nothing reads it. Self-employment as a business and
companies are a different set of rules, and pretending otherwise in a type would be worse than not
having them.

**Bank logos.** Only the place to put them, and the code that reads it.

---

## Testing

```bash
composer install
vendor/bin/pest
```

The package's own suite holds its unit tests — the bank list's parsers and name builder, the tax rules,
the declarations' schema, Fx, rentals, SPV and wallet pieces. The
behaviour that needs the other packages — HTTP, tenancy, encryption, the audit chain — is tested in the
application that installs them, against both Postgres and SQLite.
