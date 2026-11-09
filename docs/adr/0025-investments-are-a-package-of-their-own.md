# ADR 0025 — Investments are a package of their own: what is on the market, apart from what a person holds

**Status:** proposed
**Date:** 2026-11-09
**Concerns:** a new `kern/investments` package; `kern/legislation` (the way out to official sources);
`kern/tax-romania` (the holdings, the tax treatment, the screens)

## Context

People hold Romanian government bonds — **Fidelis**, listed on the Bucharest Stock Exchange, and **Tezaur**,
sold by the Treasury and never listed — and shares and corporate bonds traded on BVB. Today the app has no
notion of either: a holding could only be typed as transactions into a `BROKER` stream, with its terms in a
person's head.

Three things are wanted:

1. **What is on the market**, the same for everyone: every issue and listed security, its terms, its price,
   its coupons and dividends, a company's shareholders and meeting calendar — read from official sources,
   kept as evidence, never typed.
2. **What a person holds**: units of one of those, bought at a price per unit on a date, held at a place —
   with its coupons, dividends, maturity and early sale following from the terms rather than typed.
3. **A broker integrated later** (Tradeville or another) must find what a person already entered and adopt
   it, not add a second entry for the same holding.

### The sources, as observed on 2026-11-09

Each was requested from this installation; nothing below is assumed.

| Source | What it holds | How it answers |
|---|---|---|
| **legislatie.just.ro** — the Ministry of Finance orders | The full terms of every issue. A Fidelis order carries the final terms of each issue in its round: ISIN, symbol, issue price, issue date, currency, face value, rate, every interest period with its record and payment date, maturity. A Tezaur order carries the prospectus: the issue code, the interest periods and payment dates, and the interest formula itself (interest = face value × rate × days accrued ÷ days in the current year). Tezaur has no ISIN. Orders go back to 2018 (Tezaur) and 2020 (Fidelis). | Already read by `kern/legislation`, through the queued search of ADR 0024 ("Programului Tezaur", "Programul FIDELIS") and the ingest that keeps every document as evidence. The Fidelis base prospectus is an order of its own and names the day-count convention. |
| **mfinante.gov.ro** — "Subscrieri realizate prin TEZAUR și FIDELIS" | Two spreadsheets (`.xlsx`): every Fidelis issue since 2020 and every Tezaur issue since 2018 — symbol (Fidelis) or issue code (Tezaur), issue month, currency, rate, maturity, value subscribed, number of subscriptions — grouped by year, with yearly totals. No ISIN. | A request with a browser's `User-Agent` answers 200; a short or crawler `User-Agent` has the connection reset. The file names carry their update date (`FIDELIS2020-2026_<ddmmyyyy>.xlsx`), so the links are read from the page each time. The issue date is a real date cell, but only the month: always the 1st, written on a round's first row only. Year headings and totals are rows of the same table; Tezaur's data starts in the second column. The Fidelis file has errors (2026-10-12 edition): six symbols of February 2025 repeated under March 2025 with other rates, and `R2707B` (RON, July 2025) repeated as EUR under August 2025. |
| **bvb.ro** — `FinancialInstruments/Markets/Shares` | One HTML table, every listed share: symbol + ISIN, company, price (RON), change %, the date and time of the price, category. | A plain request answers. Prices are 15 minutes delayed, as the page says. |
| **bvb.ro** — `FinancialInstruments/Markets/Bonds` | One HTML table, every listed bond: symbol + ISIN, issuer, price (% of face value), change %, the date and time of the price, current coupon, currency, maturity, type (government / corporate). | As above. Matured issues leave the table; their instrument page stays, marked delisted. |
| **bvb.ro** — `FinancialInstruments/CorporateActions/InfoDividend` | One HTML table: symbol + ISIN, company, dividend per share, yield, ex-dividend date, payment date, financial year, record date, total paid. | As above. |
| **bvb.ro** — `FinancialInstruments/Details/FinancialInstrumentsDetails.aspx?s=<symbol>` | One instrument. Its "Informatii emisiune" block: for a bond, the current coupon, units issued, face value, issue value, first trading day, maturity, currency, and a prospectus link when there is one; for a share, the number of shares, face value, share capital, first trading day. Then the shareholders' meeting calendar (date and kind of meeting), the shareholder structure (holder, shares, percentage), news. A Fidelis issue has a page of its own (`?s=R2707B`). | As above. One request per instrument. |
| **bvb.ro** — `proxyshld.aspx/GetInstrumentsList` | The site's own search box: symbol, ISIN, name, status (traded / delisted) as JSON, ten results a query. | Used to cross-check an ISIN and its status; never to enumerate. |

BVB sells real-time and delayed data feeds under a licence; there is no free official API. Its public pages
show the same data 15 minutes late, free, and that is what is read — once a day, after the market closes,
for a person's own valuation. Showing it to third parties commercially needs BVB's licence and is not done.

### What already exists and is reused

- **The way out.** Every download from an official source goes through `kern/legislation`:
  `AllowlistedHttpFetcher` (host allowlist, size cap, timeout, redirects checked, one `User-Agent` decided in
  `OfficialSourceRegistry`) and `SourceIngestionService` (the same, plus the bytes kept, hashed, as
  evidence). Laws, BNR and ECB rates, the bank list and GLEIF already go this way; nothing else calls the
  internet for an official source.
- **Searching the portal and keeping its documents**: ADR 0024 and the legislation ingest.
- **Day-count conventions** and their arithmetic: `Wallet\Enums\DayCountConvention` and
  `Wallet\Services\DayCount\DayCountCalculator`, used by deposits.
- **A holding whose movements follow from its terms**: `TERM_DEPOSIT` — `deposit_terms`, generated interest
  events, closing at maturity, maturity reminders.
- **Scheduled tasks**: `Operations\Enums\OperationTask`, a closed enum of what may run, with when it runs in
  `config/operations.php`.
- **Notices**: `kern/notifications`, one class per kind, a switch per kind for each person (ADR 0022).
- **One environment file**: `php artisan kern:env` prints the application's `.env.example` followed by every
  installed package's; `make env` does the same before anything runs. `ConfigKeysTest` fails when a
  configuration file reads a variable its unit's `.env.example` does not name.

## Decision

### 1. A package, `kern/investments`, for what is on the market

It knows securities, their payouts and prices, and where each fact came from. It knows nothing about a tax,
a taxpayer or a person's holdings. It depends on `kern/platform` and on `kern/legislation` — for the way out,
for the evidence, and for the orders, which are legislation documents. `kern/tax-romania` depends on it,
through its contracts and DTOs only; `deptrac` enforces it.

Its own `README.md`, `config/investments.php`, `.env.example`, migrations, routes, `lang/`, and a Pest suite
that runs on its own, like every other package.

### 2. Six tables: five of the market, one of the holdings

All public ids are UUIDv7. Timestamps are UTC (`timestampTz`); calendar facts — an issue date, a payment
date — are `date`. Money is integer minor units. A price, a rate or a number of units is a decimal held as
text and computed with `ext-bcmath`, as `Fx\ValueObjects\DecimalRate` already is, for the reason `fx_rates` records: a SQLite
`decimal` is a float, and the suite runs on both databases. Every decimal column is validated against its
pattern before it is written.

The market tables carry the `ext_` prefix this codebase gives public data from outside (`ext_banks`,
`ext_portal_searches`): the same for every installation user, owned by none.

**`ext_financial_instruments`** — one row per security: a Fidelis or Tezaur issue, a corporate bond, a
share.

- `kind` (enum: government bond, corporate bond, municipal bond, share — BVB's three bond types and its shares; no other kind until a source brings one), `programme` (enum, nullable: Fidelis,
  Tezaur), `isin` (nullable — Tezaur has none), `symbol` (nullable), `issue_code` (nullable — Tezaur's),
  `name`, `issuer_name`, `currency` (ISO 4217), `face_value` (decimal, nullable), `rate` (decimal, nullable),
  `issue_date`, `maturity_date` (nullable), `market` (nullable), `status` (enum: on offer, active, matured,
  delisted).
- From the Ministry of Finance spreadsheets: `subscribed_value_minor` (bigInteger, nullable),
  `subscriptions_count` (integer, nullable), `subscriptions_read_at` — one figure per issue, so columns,
  not a table; the only source of them for Tezaur, which BVB does not list.
- Provenance: `terms_document_id` (the legislation document the terms were read from), `listing_read_at`.
  A law an instrument was read from cannot be deleted: the package declares the column to
  `kern/legislation`'s `DocumentDependents`, and deleting the law is refused (`LAW_IS_READ_FROM`, with the
  count) — as a law a fiscal value rests on is.
- Indexes: unique `isin` where not null; unique (`programme`, `issue_code`) where not null; unique
  (`market`, `symbol`) where not null; (`kind`, `status`); (`maturity_date`); a trigram index on `name`
  for the search, Postgres only and guarded, as every Postgres-only statement here is.

**`ext_instrument_payouts`** — one row per payment per unit: a coupon, a repayment of principal, a dividend.
They are one thing — an amount per unit, owed to whoever holds the unit on a date, paid on another.

- `instrument_id`, `kind` (enum: coupon, principal, dividend), `period_start`, `period_end` (nullable),
  `ex_date` (nullable), `record_date` (nullable), `payment_date`, `amount_per_unit` (decimal, nullable when
  only the rate is known), `rate` (decimal, nullable), `financial_year` (nullable), `status` (enum:
  announced, paid), `source` (enum: order, BVB).
- Indexes: unique (`instrument_id`, `kind`, `payment_date`); (`payment_date`); (`kind`, `ex_date`) for
  "upcoming dividends".

**`ext_instrument_prices`** — one row per instrument per trading day.

- `instrument_id`, `trading_date`, `price` (decimal; per unit for a share, percent of face value for a
  bond), `change_percent` (decimal, nullable), `priced_at` (the time BVB gives), `evidence_id` (the page it
  was read from).
- Indexes: unique (`instrument_id`, `trading_date`) — reading a day twice changes nothing; (`trading_date`).

**`ext_issuer_profiles`** — one row per company, as its BVB page last showed it: the shareholders'
meetings and the shareholder structure. Per issuer, not per instrument: a company with shares and bonds
has one profile.

- `issuer_key` (the symbol BVB files the company under), `meetings` (json: date, kind), `shareholders`
  (json: holder, shares, percent), `shareholders_as_of` (the day the company reported it as of, when the
  page says), `read_at`, `evidence_id`.
- Index: unique `issuer_key`. Read only when asked for (§5), never by a schedule.

**`ext_treasury_orders`** — a Ministry of Finance order read for its issues: the legislation document
(unique; removed with it), the programme, how many issues it held, when it was read. An order with none
— one amending a prospectus — is recorded all the same, so no order is read twice (added 2026-11-09).

**`investment_lots`** (`kern/tax-romania`) — one row per purchase a person made. Each purchase on its own
because the tax on a share's gain depends on how long each purchase was held.

- `tax_subject_id` (every query scoped to it), `financial_account_id`, `instrument_id`, `units` (decimal),
  `price_per_unit` (decimal), `bought_on`, `fees_minor`, `currency`, `held_at` (enum: Treasury, bank,
  broker — and which one), `external_ref_ciphertext` and `external_ref_blind_index` (nullable: the broker's
own id, once one is integrated), and for a
  lot closed early `sold_on`, `sold_price_per_unit`, `sale_fees_minor`. Selling part of a lot splits it: the
  units sold close in a row of their own, the rest stay open, so each row is one fact.
- Indexes: (`tax_subject_id`, `instrument_id`); (`financial_account_id`); unique (`tax_subject_id`,
  `held_at`, `external_ref_blind_index`) where it is not null.
- **Encryption**, as `docs/AGENTS.md` §8 and the existing wallet tables do it:
  - **Encrypted** with the subject's key: `external_ref` — an identifier from the person's broker, like a
    transaction's `external_id`; found again for the duplicate check by its blind index, never decrypted to
    compare.
  - **Plain**, as amounts are in `financial_transactions` and `transaction_splits` today: `units`,
    `price_per_unit`, `fees_minor`, `sold_price_per_unit`, `sale_fees_minor`, the dates, `currency`,
    `held_at`, `instrument_id`. They are not identifying on their own, and tax, valuation and reports sum
    and sort them in the database.
  - The account's name and details stay where they already are, encrypted on `financial_accounts`.
  - The five `ext_` tables hold public market data only: nothing encrypted, nothing personal.

### 3. One way out, and who goes out as what

Every request this package makes to an official source goes through `kern/legislation`:
`SourceIngestionService` for what is kept as evidence (the orders, the Ministry's spreadsheets, the
BVB listings each day), `AllowlistedHttpFetcher` for a page read on request (an issuer's profile, kept
through `evidence_id` all the same). The package never calls `Http::` itself.

**Two identities, one mapping.** How a request names itself stays decided in one place,
`OfficialSourceRegistry`, from two identities and a mapping:

- **browser** — one browser `User-Agent`, a single setting (`sources.browser_user_agent`). It replaces the
  setting kept today for legislatie.just.ro alone; the stored value moves to the new key in a settings
  migration.
- **crawler** — the existing crawler name and contact ("Kern-RO-SourceCrawler/1.0 (contact: …)"), kept for
  a source that one day asks to be told who is calling.
- **the mapping** — each source entry says `identify_as: browser | crawler`; an entry that says nothing
  takes the installation's default, `SOURCES_IDENTIFY_AS`, which is `browser`. Which source goes out as
  what is configuration and nothing else: no code names a source to choose its header.

On the settings screen the three values — the browser `User-Agent`, the crawler's name, the crawler's
contact — move to a card of their own, **Browser**, so what a request says about itself is in one place.

Before the default becomes `browser`, each source on the allowlist is asked once with it — BNR, ECB, ANAF,
GLEIF, legislatie.just.ro, mfinante.gov.ro, BVB — and one that refuses it is mapped to `crawler`; the
results go in the task's handoff note.

**A package declares its own sources.** `config/sources.php` in `kern/legislation` names its own sources;
`kern/investments` declares `BVB` in its own configuration and adds it to the registry from its service
provider, so `kern/legislation` never names a stock exchange. The existing `MF` entry stays where it is.

### 4. Everything in configuration — and nothing missing goes unnoticed

Every URL, path, host, page size, schedule, refresh age, limit and pause the package uses is configuration,
read from the environment.

- **The value lives in `.env.example`**, not as a default in the config file: `config/investments.php`
  reads `env('INVESTMENTS_…')` with no fallback. `make env` and `php artisan kern:env` put the package's
  `.env.example` into the installation's one `.env`, with each value and the sentence explaining it.
- **A value that is missing or malformed fails**, at first use, with an error naming the variable. The
  package reads its configuration once into readonly DTOs (`InvestmentsConfig`, one per source) that
  validate each value — a URL is an `https` URL on an allowed host, a number is in its range — and throw
  otherwise. No code reads `config()` for this package anywhere else.
- `ConfigKeysTest` already proves every variable read is documented.

This rule binds the new package. The existing packages keep their defaults until each is changed for a
reason of its own; the BVB and MF entries this ADR adds or changes, and the three identity values of §3, follow the new rule.

### 5. The flow

**Weekly — government bonds** (`investments.import_government_bonds`, a new `OperationTask`):

1. **The orders.** For each programme, search the portal (ADR 0024) year by year, in the way the settings
   choose — the service or the browser, exactly as the "Add a law" dialog does — and ingest each order
   through `kern/legislation`.
   - **The first run** reads every year from the programme's first (configuration) to this one.
   - **Every run after it** reads this year only — and the year before, while this one is younger than
     the configured overlap (an order of late December is published in January). An order whose portal id
     is already a document is never fetched again; an order already read into terms is never parsed again.
2. **The terms.** Each new order is read into instruments and payouts. An order that holds no issue terms —
   one that amends a prospectus, say — is recorded as read and skipped, so it is not read again.
3. **The subscription figures.** Read the Ministry of Finance page, find the two spreadsheet links, ingest
   both as evidence; a file whose bytes were ingested before — the same hash — is not parsed again. A row
   gives its two figures to an instrument matched **by its code alone** — the symbol (Fidelis) or the issue
   code (Tezaur), exactly; the spreadsheet has no ISIN. Only where the list holds the same code more than
   once is the **month and year** asked too: the row whose month (or one of its two, for a round written
   "Apr - May 2023") is the instrument's issue month. What does not match for sure — no instrument, or
   still more than one row — is skipped and reported; nothing is guessed, created or changed otherwise
   (decided 2026-11-09). The figures are information, never required: an instrument without them is
   whole.
4. **The notice.** An issue seen for the first time while on offer raises a notice to those who
   subscribed (§8).

**The search, in configuration.** Per programme, exactly what the portal search takes (`PortalQuery`): the
words searched for in the title, the year, and the act type kept (`ORDIN`) — the same search the "Add a law"
dialog makes. The issuer is not a criterion: the service cannot search by it, and the search has none. What
narrows the results further is the title: the patterns an act's title must match to be read as that
programme's, also configuration — so a law that only mentions Tezaur in passing is not taken for an
order. The words come from the titles observed on the portal:
"Programului Tezaur" for Tezaur; "Programul FIDELIS" for Fidelis, whose orders are titled after their
"Termenii finali" or the "Prospectul de bază". The first task runs each search over every year and records
the titles it finds, so the patterns are fitted to what the portal holds, not to these examples.

**Which instruments get a price.** Fidelis is listed: its price comes from the BVB run below. Between its
subscription and its first trading day it has no price, and is worth its face value. Tezaur is never listed
and has no market price: a Tezaur holding is worth its face value until it matures.

**Which instruments get payouts.** Fidelis and Tezaur: every coupon and the repayment, from the order. A
share: its dividends, from the BVB dividends page. **A corporate or municipal bond: none** — BVB publishes its current
coupon and its maturity, not its payment dates, so its coupons are never guessed; a corporate or municipal bond holding
shows its value and its maturity, and is sold or matures like any other.

**Every weekday, after the market closes — BVB** (`investments.import_bvb`). No holiday calendar: a price
is stored under the date the page prints beside it, so on a day the exchange is closed the page still shows
the last trading day and the run adds nothing.

1. Ingest the shares, bonds and dividends pages as evidence.
2. Upsert instruments by ISIN; add the day's price; upsert dividends as payouts.
3. **The check.** A Fidelis is matched to the order its terms came from by ISIN: terms from the law, price
   and units issued from BVB. A listed government bond with no order behind it, or an order whose ISIN is
   not listed once its trading day has passed, is reported by the run, never invented. An instrument that
   left the listing is marked delisted or matured, never deleted.

**Only when asked — an issuer's profile.** Never read by a schedule and never by opening a page: only
when a caller asks for it — later, a button on a screen. The package delivers it whole (the parser, the
table, the endpoints); no screen is built now. Asked again while the stored one is younger than
`investments.profile_refresh_hours`, it answers the stored one without a request to BVB.

Every import parses the whole source before it writes anything; a source that does not parse writes
nothing and fails the run, saying what did not match. Writes are upserts by natural key, in chunks, so a run
repeated is a run that changes nothing. Nothing from a source is ever deleted.

### 6. Parsing: strict, bounded, and never trusted

- **HTML** with PHP's own `Dom\HTMLDocument` (PHP 8.4+): an HTML5 parser, the way a browser reads a
  page, with CSS selectors built in, loaded with `LIBXML_NONET` — no library added (chosen 2026-11-09 over
  `symfony/dom-crawler`, a wrapper over the older `DOMDocument` that adds two dependencies). Only
  `textContent` is read; no markup from a source is ever kept or shown. A table is found by its header row, by the
  header texts the source shows, never by position or by a generated id; a missing header fails the parse.
  One reader of "a table with these headers" serves the three BVB listings.
- **XLSX** is read where this codebase already reads spreadsheets: the processing service
  (`apps/processing`), which turns a statement file into a grid of text cells with `openpyxl` and
  `defusedxml`, refuses an archive that unpacks too far, and bounds the rows. Its reader is shared by a
  second endpoint answering the grid alone — no statement semantics, no column guessing — and the package
  reads the Ministry's rows out of that grid: the columns found by their header texts, a round's month
  carried down to the rows under it, year headings and totals skipped. No PHP spreadsheet library is added;
  files are parsed in Python, as `docs/ARCHITECTURE.md` places them.
- **Every value is validated before it becomes a DTO**: an ISIN by its pattern and check digit, a currency
  against ISO 4217, a date as a real date, a decimal by its pattern and range, text trimmed, its whitespace
  normalised and its length bounded, markup stripped. A row that fails is reported with its source and row,
  and fails the import — a half-read listing is not written.
- **Bounded input**: the fetcher's size cap applies; a page is parsed once and released; imports write in
  chunks, iterate with lazy cursors and keep no model collections between chunks, so a long run does not
  grow.
- Each parser is tested against real pages and files kept as fixtures, and against hostile ones: a missing
  column, a reordered one, an empty table, an oversize file, a malformed number, an injected script.

### 7. The endpoints

Read-only, behind authentication, rate-limited like the other read endpoints; prefix `v1/investments`.

- `GET instruments` — filtered by kind, programme and status (active by default), searched by symbol, ISIN,
  issue code or name; paginated by cursor.
- `GET instruments/{id}` — the terms, the payouts, the latest price.
- `GET instruments/{id}/prices` — the price history, by date range, bounded.
- `GET payouts/upcoming` and `GET payouts/recent` — dividends and coupons by date, bounded.
- `GET issuers/{key}` — the stored profile, as last read; 404 when it was never read. Never fetches.
- `POST issuers/{key}/refresh` — reads it from BVB now (§5), rate-limited; answers the profile.

Each answers a DTO shaped for the screen; nothing is parsed or computed in the browser.

### 8. Notices

A notice kind of its own for a new issue on offer, with its words, switched on or off per person like
every other kind (ADR 0022). The import raises the event; `kern/tax-romania` sends the notice.

### 9. Holdings, in `kern/tax-romania`

- **One holding kind, `INVESTMENTS`**, beside `TERM_DEPOSIT` in `AccountKind`: a holding whose movements are
  computed, never typed. One kind for bonds and shares alike — each lot names its instrument, of whatever
  kind — so that a broker integrated later, which holds both, has nothing to merge. The existing `BROKER`
  stream stays as it is; what becomes of it is decided with the broker integration.
- **Adding** is the deposit's flow: choose the account, choose the instrument from the active ones, and its
  terms fill the form and cannot be edited — they are the source's. The person enters the units, the price
  per unit, the date, the fees and where it is held.
- **A dividend** is credited only for the units held the day before its ex-dividend date and not sold
  before it — bought on the ex-date or later, or sold before it, they have no right to it. It is credited
  net of the 16% the company withholds (§10): 100 shares at 1 leu credit 84 lei, the 16 lei recorded as
  withheld.
- **Coupons, dividends, the repayment at maturity** are generated from the payouts, as a deposit's interest
  is from its terms; reminders before a coupon or a maturity reuse the deposits' maturity reminders. A
  holding in euro is converted at the BNR rate the FX module already provides.
- **Closing early** — selling a Fidelis or a share on BVB — is closing the holding, or part of it: the date,
  the units, **the price per unit**, the fees. The amount and the result are computed, never typed. **A
  Tezaur holding cannot be closed early**: it ends at maturity.
- **The value is informative.** After each BVB run a holding's value is recomputed — units × price, and for a
  bond units × face value × price percent. It is an unrealised result: it is shown, never income, never
  taxed. Only a sale realises a gain or a loss.
- **No duplicate when a broker comes**: a lot knows where it is held. A broker integrated later matches its
  positions to the lots already held there by instrument, and adopts them (setting `external_ref`) rather
  than adding new ones; where a match is not certain, the person is asked.
- **Day-count arithmetic stays where it is.** `DayCountConvention` and `DayCountCalculator` stay in
  `Wallet`: the market package stores what the orders say and computes no interest, and the holdings that
  do sit beside the deposits in `kern/tax-romania` (decided 2026-11-09 — moving them would take
  `Wallet`'s `Decimal` and its date-range exception along). Tezaur's formula — the days accrued over the
  days in the current year, 365 or 366 — is **ACT/ACT**, which the calculator lacks and gains there, with
  a test from the order's own example. A Fidelis coupon needs none: the order gives each payment date and the
  rate, so a coupon is the face value times the rate.

### 10. Tax

**Nothing about a tax is written in the code.** Every rate, every period and every exemption is a fiscal
parameter (`packages/tax-romania/config/fiscal_parameters.php`): a question asked, in the law's words, of
the Fiscal Code the app stores; the answer saved as a candidate with the sentence it quotes; counted only
once a person approves it — as the income tax rate and the CASS bands are today. The articles below are
where the answers were found on 2026-11-09 (Law 227/2015, in force from 2026-08-08); they guide the
questions and the tests, and are never a value.

**The parameters added**, each with its question and the shape of its answer:

| Parameter | Asks | Answer | Found in |
|---|---|---|---|
| `RO.INCOME_TAX.PUBLIC_DEBT_EXEMPT` | Is income from holding and transferring instruments of the state's public debt non-taxable? | `yes_no` | art. 93 (1) a) |
| `RO.CASS.PUBLIC_DEBT_EXCLUDED` | Is that non-taxable income left out of the CASS base? | `yes_no` | art. 170 (5) |
| `RO.INCOME_TAX.SECURITIES_GAIN_RATE_LONG` | The rate on a gain from securities sold through a Romanian intermediary, held at least the holding period | `percent` | art. 97 (8^1) a) |
| `RO.INCOME_TAX.SECURITIES_GAIN_RATE_SHORT` | The same, held less | `percent` | art. 97 (8^1) a) |
| `RO.INCOME_TAX.SECURITIES_HOLDING_DAYS` | The holding period that separates the two | `days` | art. 97 (8^1) a) |
| `RO.INCOME_TAX.DIVIDEND_RATE` | The rate withheld on dividends | `percent` | art. 97 (7) |

**Two answer shapes are added to `ParameterAnswer`**: `yes_no` — `{"value": true|false, "citation": …}` —
and `days` — a whole number of days. Both are checked like every other: the quoted sentence must stand,
word for word, in the paragraph named.

**The categories**, beside the existing ones (`CRYPTO_GAIN`, `RENT`, `DIVIDENDS_INTEREST`):

| Category | What | Treatment |
|---|---|---|
| `PUBLIC_DEBT_INCOME` | Fidelis and Tezaur: coupons, and a gain or a loss on a sale | Not taxed while `PUBLIC_DEBT_EXEMPT` is approved as yes; not counted for CASS while `PUBLIC_DEBT_EXCLUDED` is |
| `SECURITIES_GAIN` | A gain or a loss on shares sold through a Romanian intermediary | Withheld at source and final: the long or short rate by the holding period; a loss neither carried forward nor offset (art. 97 (8^5)); counted for CASS |
| `DIVIDENDS` | Dividends | Withheld by the payer at the dividend rate, final; counted for CASS net of the tax withheld (art. 170 (4) d)) |

**A parameter not approved stops the calculation.** If an exemption is not approved, or the law changes and
its answer becomes no, an assessment does not quietly treat the income as free: it stops and says which
parameter is missing — the same as a rate not yet approved.

**How a share's gain is computed is the law's, not a choice:** the tax value is the weighted average price
per symbol, costs included (art. 97 (8^2)); the holding period is counted first in, first out, per symbol
(art. 97 (8^1), last sentence) — which is why each purchase is a lot of its own.

**Withheld tax is recorded, not owed again.** The app shows what the intermediary or the company withheld,
computed from the same approved rates, and never adds it to what the person pays. What reaches the
Declarația Unică is CASS: `CassOtherIncomeRule` counts every category but `FREELANCE` today, so it is
changed to leave out `PUBLIC_DEBT_INCOME` while `PUBLIC_DEBT_EXCLUDED` is approved — otherwise Fidelis
income would raise the person's CASS.

**Corporate bonds are not covered yet.** Their interest and the gain on selling one have rules of their own,
not yet looked up. Until they are, a corporate bond holding is valued but its income is not categorised;
the research comes first in the tax task.

### 11. The screens

Composed from `resources/js/components/panel` per `docs/UI-PATTERNS.md`:

- **Market**: the issues on offer and active, the listed shares and bonds, the upcoming dividends.
- **An instrument**: its terms, its price history, its payouts.
- **Adding a holding** and **closing it early**, as in §9.

## Consequences

- What is on the market is read once, for everyone, and can be read without the rest of the app: a job, a
  console command or another package uses the package's actions and DTOs directly.
- Every term of a government bond traces to the order it was read from, and every price to the page it was
  read on.
- The Tezaur and Fidelis history is complete from 2018 and 2020, as far as the portal holds the orders;
  Fidelis is checked against the BVB listing.
- Six tables. A day's BVB run adds one price row per listed instrument that traded — a few hundred rows a trading day, none on a day the exchange is closed.
- One more source on the allowlist (`bvb.ro`), with a rate limit of its own; mfinante.gov.ro is now asked
  as a browser.
- The legislatie.just.ro `User-Agent` setting becomes the browser `User-Agent` setting, on a settings card of
  its own beside the crawler's name and contact; an installation keeps its value through the migration.
  Every source goes out as a browser unless its entry says otherwise.

## Not done

- **A brokerage account and broker integration** (Tradeville or others): a later decision. §9 keeps it
  possible without duplicates.
- **Shares through a foreign intermediary**: taxed by declaration, not withheld; it comes with the broker
  integration.
- **Corporate actions** — splits, free shares, consolidations — that change a holding's units: not read now.
  Until they are, a holding they touch is corrected by the person.
- **Accrued interest on a sale before a coupon**: the person enters the price per unit their broker gave;
  the app does not compute it.
- **Real-time or licensed prices.** Read once a day from the public pages; anything more is BVB's licence.
- **Valuations for third parties.** Prices are for a person's own holdings.

- **A company page** (meetings, shareholders): the package delivers the data and the endpoints (§7); the
  screen, with its button to read it, comes when a page per ticker is built.

## Open questions

- The exact title patterns of each programme's orders: fitted in the first task to every title the
  searches return (§5).
- Which sources, if any, refuse the browser `User-Agent`: asked once each before the default changes (§3).
