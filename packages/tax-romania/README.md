# kern/tax-romania

Romanian tax for an individual — a *persoană fizică* — from the taxpayer to the declaration.

Every figure it produces traces back to a value someone approved, which traces to a paragraph of a
law, which traces to the bytes of the consolidation that paragraph was read from. That chain is the
product; the tax arithmetic is the easy part.

## What is in it

| | |
| --- | --- |
| `Subjects` | the taxpayer, who may act for them, and the fiscal identifier |
| `FiscalParameters` | the values a tax is computed from, read out of the law and approved by a person |
| `TaxEngine` | deterministic, versioned rules — no AI anywhere near a number |
| `Wallet` | accounts, deposits, transactions, splits and the categories they are labelled with |
| `StatementImport` | a bank statement becomes transactions, with the columns mapped once per bank |
| `Fx` | the BNR's reference rates and the period averages the law asks for |
| `Banks` | which institution a deposit is at, from the ECB's and GLEIF's own lists |
| `Rental` | contracts, the rent they owe and the accruals that follow |
| `Declarations` | the forms, their versions, and what was submitted |
| `Spv` | ANAF's own mailbox, read through a browser because there is no API |
| `Operations` | the console for the tasks that keep all of that current |

One package rather than eleven, deliberately. These modules have one consumer between them — each
other — and `StatementImport` depends on most of the rest precisely because it is where a wallet and
a document meet. Splitting further buys version numbers and cross-repository test matrices, and buys
no reuse: nobody wants Romanian rental accruals without Romanian income tax.

The boundaries *inside* it are still enforced. `deptrac.php` declares one layer per module and every
crossing is a declared grant with a reason written next to it; a module never imports another's
Eloquent model.

## What it needs

```json
"require": { "kern/tax-romania": "^1.0" }
```

It pulls in `kern/platform`, `kern/audit`, `kern/documents` and `kern/legislation` the way an
application pulls in `illuminate/*`. Set `tenancy.column` to `tax_subject_id` in the installing
application — this package is the reason the tenant is a taxpayer, but the word stays in the
installation's config so that `kern/documents` can be told the same thing.

## What it deliberately does not have

**Screens.** No Blade, no Inertia pages, no navigation. It ships actions, jobs, models, migrations,
config and JSON routes; what the user sees belongs to the application, which is what makes replacing
React with something else a change to one directory.

**PFA or SRL.** A `pfa_profiles` table exists and nothing reads it. Self-employment and companies are
a different set of rules, and pretending otherwise in a type would be worse than not having them.

## Running its own suite

```
composer install
vendor/bin/pest
```

All five packages, no application: it migrates from nothing, registers a taxpayer whose CNP appears
nowhere in the clear, and shows one taxpayer's records invisible to another.
