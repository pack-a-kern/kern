# 050 — Term deposits from a CSV

## What was built

The add-term-deposit dialog on Accounts can add many deposits at once from a CSV file. It is the same
dialog with a switch, *One deposit · From a CSV file*, and the same behaviour in bulk:

1. The file is chosen, with its separator (`;` or `,`), date format and decimal separator.
2. Each per-deposit field is mapped to a column: start date, principal, rate, term in months. Bank and
   currency come from a column or are one answer for the whole file. Funding account, rate type,
   interest method and payment frequency are the form's own fields, answered once.
3. A preview shows every line as the deposit it becomes, with any line that cannot be read marked.
4. **Add N deposits** opens them all, or none.

Nothing is stored but the deposits: no document, no import record, no staged rows.

## Files created / changed

- `packages/tax-romania/src/Http/Controllers/Wallet/DepositFields.php` — new. The deposit's
  validation constraints and the construction of `DepositTermsInput`, moved out of
  `OpenAccountController` so the form and the bulk endpoint refuse exactly the same things.
- `OpenAccountController` and `UpdateAccountController` — now take those constraints from
  `DepositFields`. Behaviour unchanged; the edit keeps its two deliberate differences (a closed bank
  the deposit is already at, and no `after:starts_at` when the start is not sent).
- `ReadDepositCsvController.php` — new, `POST wallet/deposits/csv`. Reads the file in memory and
  answers its columns, first lines, and every line read under the mapping. Writes nothing.
- `OpenDepositsController.php` — new, `POST wallet/deposits`. Validates every deposit with
  `DepositFields`, then opens all of them through `OpenFinancialAccount` in one transaction.
- `Banks/Parsing/DelimitedRows::fromCsv()` — optional separator (default `,`, unchanged for the bank lists).
- `StatementImport/Services/StatementValueParser::amountMinor()` — takes the two separators instead
  of a whole `StatementForm`, so a deposit's principal is read by the same rule as a statement amount.
- `ShowBanksController::OFFERED` — public, so the CSV matches banks against the list the form offers.
- `config/wallet.php` `deposit.import.max_file_bytes` / `max_rows`, documented in the package
  `.env.example`; messages in `lang/en/wallet.php`.
- Frontend: `components/wallet/DepositCsvImport.tsx`, `lib/depositCsv.ts`, `pages/wallet/accounts.tsx`,
  `api/walletApi.ts`, `types/wallet.ts`; `DATE_FORMATS` moved to `types/statementImport.ts` and shared
  with the statement import screen; strings in `i18n/*`.
- Tests: `tests/Feature/TaxRomania/Wallet/DepositCsvImportTest.php`, `resources/js/lib/__tests__/depositCsv.test.ts`.

## Key decisions

- **Same rules, one copy.** The bulk endpoint receives deposits shaped exactly as the form sends one
  and checks each with `DepositFields`. It never trusts what the browser read.
- **The maturity is derived in the browser with `maturityFor`**, exactly as the form derives it. A
  second implementation on the server would be a second copy of the weekend rule.
- **Reading happens on the server** so dates and amounts use `StatementValueParser`, the statement
  import's parser, and not a second reader in TypeScript.
- **All or nothing.** Every deposit is validated before the first is opened, and they open in one
  transaction: a file with one bad line opens nothing and says which line.
- **No virus scan.** The file is never stored and nothing but `fgetcsv` opens it. CSV only, bounded in
  size and lines, UTF-8 only (a guessed encoding would be a bank name that silently matches nothing).
- **Bank by name or code** from the same active list the form offers; an unknown name is a line error,
  never guessed.

## How to verify

```bash
docker compose exec -T app sh -c 'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: vendor/bin/pest tests/Feature/TaxRomania/Wallet/DepositCsvImportTest.php'
make check-php-pg
(cd apps/api && npx vitest run)
```

## Interfaces exposed to other modules

None. Both endpoints are HTTP only, inside the `{subject}` group (`auth:web`, `tax-subject`).

## What is NOT done

- No duplicate check against deposits already recorded — the one-deposit form has none either.
- One rate per deposit, as on the form; variable-rate periods are not read from a file.

## Open questions

None.

## Sources consulted

None external.

## Threat model

- **Tenancy:** both routes sit in the `{subject}` group; the subject comes from `TenantContext`, never
  the body. A funding account of another subject is refused by `StreamSource` (tested).
- **The file:** never stored or logged, read in memory and dropped with the request; bounded by
  `wallet.deposit.import`; CSV extension only; not passed to any external service.
- **Writes:** only through `OpenFinancialAccount`, so each deposit is encrypted and audited exactly as
  one added by hand.
