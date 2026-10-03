# 047 — Reports redesign, and a counterparty answer that reaches the ledger

**Milestone:** M6   **Status:** complete
**Date:** 2026-10-02

## What was built

**1. The wallet reports screen, rebuilt** against a supplied mockup. Six sections: four summary
tiles, a cash-flow line chart with an *In & out* / *Net only* switch, an annual-composition panel of
three rings, one card holding *By month* and *Largest* as tabs, a spending breakdown by category /
tag / counterparty, and money movement by bank / currency / source beside a *Needs attention* panel.

The period is a **year**, chosen from the years the taxpayer actually has transactions in — not the
last four calendar years. This taxpayer has records in fourteen of them.

**2. `Kern\TaxRomania\Wallet\Services\CounterpartyTransactions`** — one counterparty's rows, and what
can be done to them. The apply-the-answer logic already existed but was a `private` method inside
`ClassifyKnownCounterparties`, reachable only by the AI batch job. So a model run updated every
matching row and a person classifying a row by hand updated exactly one. Now both call the same
service.

**3. The counterparty (parties) screen** — `/wallet/counterparties`. One row per party rather than
per direction, busiest first, with a search box and paging over the tail. Each direction's rule is
editable in place, and several spellings can be folded into one name.

**4. `Normalizer` builds its ICU transliterator once.** It was rebuilt per value, which was 486ms of
a 871ms counterparty search over 2585 names.

## Files created / changed

- `packages/tax-romania/src/Wallet/Queries/WalletReports.php` — added `years`, `totals`, `previous`
  (the same length of time immediately before), `by_counterparty`, `by_account`, per-month counts and
  top category, `largest` split five each way, and a completeness denominator.
- `packages/tax-romania/src/Wallet/Queries/DuplicateTransactionGroups.php` — added `count()`.
- `packages/tax-romania/src/Wallet/Services/CounterpartyTransactions.php` — new.
- `packages/tax-romania/src/Wallet/Queries/CounterpartyList.php` — new; counts in SQL, decrypts a page.
- `packages/tax-romania/src/Wallet/Actions/{UpdateCounterparty,MergeCounterparties}.php` — new.
- `packages/tax-romania/src/Http/Controllers/Wallet/{Show,Update,Merge}Counterpart*.php` — new.
- `apps/api/resources/js/pages/wallet/counterparties.tsx` — new.
- `packages/tax-romania/src/Wallet/Actions/ClassifyTransaction.php` — applies the remembered answer
  to the counterparty's unanswered rows.
- `packages/tax-romania/src/StatementImport/Services/ClassifyKnownCounterparties.php` — delegates;
  no longer imports `Wallet\Models\FinancialTransaction`.
- `packages/platform/src/Crypto/BlindIndex/Normalizer.php` — cached transliterator.
- `apps/api/resources/js/pages/wallet/reports.tsx` — rebuilt.
- `apps/api/resources/js/components/Chart.tsx`, `resources/js/lib/themeColor.ts` — new.
- `apps/api/resources/js/components/panel/index.tsx` — `Stat` gained `icon`/`tone`; new `Tabs`.
- `apps/api/resources/css/app.css` — `.tabs`/`.tab`, `.stat-head`/`.stat-mark`, `.col-figure`,
  `.is-in`/`.is-out`, `.direction-mark`, `.select-compact`, `.chart-*`, `.report-split*`.

## Key decisions

- **Chart.js over hand-rolled SVG** — ADR 0018.
- **Counts of duplicates come from the rule, not a restatement of it.** The attention panel first ran
  the day/amount/currency grouping itself and reported 26 groups while the duplicates screen showed
  none. That grouping is the *candidate bucket*; a group must also name one counterparty and draw
  from more than one import. 302 buckets, 0 groups.
- **A person's answer applies to unanswered rows only**, never over an existing split — the same rule
  `CounterpartyDirectory::remember()` already follows.
- **Figures are per currency throughout.** The screen picks the busiest and offers the rest beside the
  period; nothing adds EUR to RON.

## How to verify

```
docker exec anaf-app-1 sh -c 'cd /var/www/html && ./vendor/bin/pint --test'          # PASS 216 files
docker exec anaf-app-1 sh -c 'cd /var/www/html && ./vendor/bin/phpstan analyse'      # [OK] No errors
docker exec anaf-app-1 sh -c 'cd /var/www/html && ./vendor/bin/deptrac analyse'      # Violations 0
docker exec -e APP_ENV=testing -e DB_CONNECTION=pgsql -e DB_DATABASE=kern_test \
  anaf-app-1 sh -c 'cd /var/www/html && php -d memory_limit=1G vendor/bin/pest tests/Feature/TaxRomania/Wallet'
```

Measured on live data (1127 transactions in 2026, 2585 counterparties):

| | before | after |
|---|---|---|
| counterparty search | 871ms | 292ms |
| whole report payload | — | 129ms |
| parties page, busiest first | — | 33ms over 2533 parties |
| parties page, searching | — | 308ms (the decrypt-everything path) |

Tests: 16 in `CounterpartyDirectoryTest`, three of them new — a classification reaching the party's
other unanswered rows, an existing answer surviving a later rule, and a three-way merge whose rows
all decrypt afterwards (which a single shared ciphertext would not).

## Interfaces exposed to other modules

`CounterpartyTransactions::applyAnswer()`, `::countFor()`, `::descriptionsFor()`. All take and return
scalars or DTO-shaped lists. `StatementImport` reaches `Wallet` only through this and the services it
already used — §6's sanctioned shape. Deptrac cannot see the shape, only which classes a file names,
so this is a review constraint rather than an enforced one.

## What is NOT done

- **Export report** is a button that says export is not built.
- **No "All accounts" filter** on reports — it would need a backend parameter.
- The counterparty search is 292ms, not fast. The remaining cost is 216ms of Eloquent hydration in
  `CounterpartyDirectory::search()`, which reads two columns off 2585 fully hydrated models. The
  parties screen does not pay it: its default ordering counts rows in SQL and decrypts only the page,
  which is 33ms over 2533 parties. Only searching pays.
- **A merge is not reversible and does not stick.** A statement naming a folded-away spelling creates
  it again, to be merged again. Decided deliberately — see *Key decisions*.
- **Three controllers cap a counterparty name at three different lengths**: 255 in
  `RecordTransactionController`, 200 in `UpdateTransactionController`, 120 in
  `ShowTransactionsController`. One invariant, three numbers, which `docs/CODE-QUALITY.md` §1 calls a
  defect. Not fixed here: it touches three files outside this change.

## Open questions

- When a rule gains an answer, should applying it to existing rows be silent (as built) or confirmed?
  Built silent, because the user asked for "all that party transactions must match".
- The duplicates empty state reads *"Same day, same amount, same currency"*, which is the candidate
  bucket and not the rule. It should also say *the same counterparty, from different statements*.

## Sources consulted

`docs/AGENTS.md` §6, §7b; `docs/UI-PATTERNS.md`; `docs/CODE-QUALITY.md` §1–2;
`docs/DEPENDENCY-POLICY.md`; mockup `taxos_reports_full_v3.html`.
