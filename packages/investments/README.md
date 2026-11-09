# kern/investments

What is on the market, and where each fact came from: Romania's government bonds for people (Fidelis,
Tezaur), the shares and bonds listed on the Bucharest Stock Exchange (BVB), what each pays and when, and
its price each trading day.

It knows securities. It knows nothing about a tax, a taxpayer or what a person holds: those are the
application's (`kern/tax-romania` keeps a person's holdings and reads this package's data through it).
Every table here is public market data — the same for every user, owned by none, nothing personal and
nothing encrypted.

The decisions behind it are [ADR 0025](../../docs/adr/0025-investments-are-a-package-of-their-own.md).

## What it holds

| Table | One row per | Identity |
|---|---|---|
| `ext_financial_instruments` | security: a Fidelis or Tezaur issue, a listed bond, a share | ISIN; or programme + issue code (Tezaur); or market + symbol |
| `ext_instrument_payouts` | payment per unit: a coupon, the repayment, a dividend | instrument + kind + payment date |
| `ext_instrument_prices` | instrument per trading day | instrument + trading date |
| `ext_issuer_profiles` | company: its shareholders' meetings and shareholders | the symbol BVB files it under |

Each identity is a unique index, so reading a source twice changes nothing.

- **Decimals are text** — a price, a rate, a face value — and are computed with `ext-bcmath`. A SQLite
  `decimal` is a float, and the application's tests run on SQLite and Postgres. The `Decimal` cast refuses
  anything but a plain decimal before it reaches a column: no exponent (`6.95E-2`), no locale comma
  (`6,95`), no thousands separator.
- **Money is integer minor units** (`subscribed_value_minor`: bani for RON, cents for EUR).
- **A bond's price is a percent of its face value**, as BVB lists it; a share's is per share.
- **Nothing read from a source is deleted.** An issue that matures, or leaves the market, changes status:
  `ON_OFFER` → `ACTIVE` → `MATURED`, or `DELISTED`.

## How it reaches the outside

It never calls `Http::` itself. Every request goes through `kern/legislation`'s `AllowlistedHttpFetcher`:
the host must be on the source's allowlist, the request names itself as the installation's identity says
(a browser, or the crawler), and what is kept as evidence is kept by `SourceIngestionService`.

The package adds its own source, `BVB`, to that registry from its service provider
(`config/sources.php`). Which hosts may be fetched is code, reviewed with a change; only the addresses read
from them are configuration.

## Configuration

Every value comes from the environment, and **the config file has no defaults**: a value missing or
malformed fails at first use with an error naming the variable. The values, with a sentence explaining
each, are in [`.env.example`](.env.example); `php artisan kern:env` puts them in the installation's one
`.env`.

| Variable | What |
|---|---|
| `INVESTMENTS_BVB_SHARES_URL` | BVB's list of every listed share |
| `INVESTMENTS_BVB_BONDS_URL` | BVB's list of every listed bond |
| `INVESTMENTS_BVB_DIVIDENDS_URL` | BVB's list of dividends |
| `INVESTMENTS_BVB_INSTRUMENT_URL` | One instrument's page; `{symbol}` is replaced by its symbol |

Each address must be `https` and on a host the `BVB` source allows (`www.bvb.ro`, `bvb.ro`).

The configuration is read once, into readonly objects, by `InvestmentsConfig` — the only class that
reads `config('investments.…')`:

```php
use Kern\Investments\Config\InvestmentsConfig;

$bvb = app(InvestmentsConfig::class)->bvb;

$bvb->sharesUrl;                // 'https://www.bvb.ro/FinancialInstruments/Markets/Shares'
$bvb->instrumentUrl('EXMPL');   // '…/FinancialInstrumentsDetails.aspx?s=EXMPL' — the symbol encoded
```

An address set wrong stops it there, not at a request:

```text
RuntimeException: INVESTMENTS_BVB_SHARES_URL must be an https address on a host the BVB source allows (www.bvb.ro, bvb.ro).
```

## Using it

Install it beside `kern/platform` and `kern/legislation`; the provider registers itself. Run the
migrations, and set the variables above.

The models are ordinary Eloquent models over the tables. An example, with made-up data:

```php
use Kern\Investments\Enums\{InstrumentKind, InstrumentStatus, Market, Programme};
use Kern\Investments\Models\FinancialInstrument;

FinancialInstrument::query()->create([
    'kind' => InstrumentKind::GOVERNMENT_BOND,
    'programme' => Programme::FIDELIS,
    'isin' => 'ROEXAMPLE001',
    'symbol' => 'R2899A',
    'name' => 'Example issue',
    'issuer_name' => 'Example issuer',
    'currency' => 'RON',
    'face_value' => '100',
    'rate' => '6.95',            // percent a year, as text
    'market' => Market::BVB,
    'status' => InstrumentStatus::ACTIVE,
]);

// The active government bonds maturing first — served by the (kind, status) and maturity_date indexes.
FinancialInstrument::query()
    ->where('kind', InstrumentKind::GOVERNMENT_BOND)
    ->where('status', InstrumentStatus::ACTIVE)
    ->orderBy('maturity_date')
    ->get();
```

A Tezaur issue has no ISIN, symbol or market, and is identified by its programme and issue code:

```php
FinancialInstrument::query()->create([
    'kind' => InstrumentKind::GOVERNMENT_BOND,
    'programme' => Programme::TEZAUR,
    'issue_code' => '0001',
    'name' => 'Example Tezaur issue',
    'issuer_name' => 'Example issuer',
    'currency' => 'RON',
    'face_value' => '1',
    'rate' => '6.50',
    'status' => InstrumentStatus::ON_OFFER,
]);
```

## Reading it over HTTP

Read-only JSON under `/api/v1/investments`, behind authentication and a per-person budget
(`INVESTMENTS_API_READS_*`); the one call that reaches out to BVB has its own (`INVESTMENTS_API_REFRESHES_*`).
Every answer is shaped for the screen: dates `Y-m-d`, decimals as text, enums by value.

| Call | Answers |
|---|---|
| `GET instruments?kind=&programme=&status=&q=&cursor=` | The market, active by default, by name; `q` is a symbol, ISIN or code exactly, or words of a name. Each with its latest price. |
| `GET instruments/{id}` | The terms, every payout in date order, the latest price. |
| `GET instruments/{id}/prices?from=&to=` | The price each trading day of a span, at most `INVESTMENTS_API_MAX_SPAN_DAYS`. |
| `GET payouts/upcoming?kind=` · `GET payouts/recent?kind=` | Coupons, repayments and dividends from today on, or before it. |
| `GET issuers/{key}` | A company's stored profile — meetings, shareholders — or 404. Never reaches out. |
| `POST issuers/{key}/refresh` | The profile read from BVB now; the stored one while younger than `INVESTMENTS_PROFILE_REFRESH_HOURS`. |

Lists page by cursor, `INVESTMENTS_API_PAGE_SIZE` rows at most; follow `nextCursor` until it is `null`.

```sh
curl -b cookies.txt 'https://example.test/api/v1/investments/instruments?kind=SHARE&q=example'
```

```json
{
  "data": [
    {"id": "0190…", "kind": "SHARE", "isin": "ROEXAMPLE001", "symbol": "EXMPL", "name": "Example S.A.",
     "currency": "RON", "status": "ACTIVE", "latestPrice": {"price": "11.5000", "tradingDate": "2026-11-09"}}
  ],
  "nextCursor": null
}
```

## The imports

The **orders** arrive through `kern/legislation`'s law watches, which this package declares
(`config/watches.php`): each programme's orders searched on the portal by words of their title, every
`INVESTMENTS_ORDERS_WATCH_HOURS`. A new order is read as a law; when that finishes, this package reads
its issues from it into instruments and payouts (`ext_treasury_orders` remembers which). The legislation
README shows how any package declares a watch of its own.

Two scheduled tasks of this package, each on the Operations screen:

- **`investments.import_government_bonds`** (weekly) — the Ministry's subscription lists, and any order
  already a law but never read for its issues (added before this package was installed, say).
- **`investments.import_bvb`** (weekdays, after the close) — shares, bonds, dividends, the day's prices.

Each says in its run history what it wrote and what it skipped.

## Tests

The package's own suite runs on Postgres in an application with nothing but this package and what it
requires:

```sh
make check-packages        # every package, this one included
```

## What comes next

ADR 0025 orders the rest: the parsers (BVB's pages, the orders, the Ministry of Finance's spreadsheets),
the imports that fill these tables, the read-only endpoints, and — in `kern/tax-romania` — a person's
holdings and their tax. This README grows with each.
