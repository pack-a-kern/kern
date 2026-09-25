# ADR 0016 — Installable packages, with a base that carries only the security spine

**Status:** accepted
**Date:** 2026-09-25
**Extends:** ADR 0001 (modular monolith). Nothing is superseded: this is the same boundaries,
enforced by Composer instead of by deptrac alone.

## Context

`packages/tax-romania/src` holds sixteen modules and deptrac reports zero violations — every cross-module reach
is already a DTO, a contract or an application service rather than a foreign Eloquent model. The
boundaries hold. What they do not do is let anything *leave*.

Three wants pushed this:

1. A second product should start from this one's security work — envelope encryption per subject,
   blind indexes, the append-only audit chain, MFA and passkeys — without inheriting a single line
   about Romanian tax.
2. The legislation half of `TaxKnowledge` is useful on its own. A site that only indexes and lists
   the law wants ingestion, consolidations, provision history and hybrid search, and wants no tax
   engine anywhere near it.
3. The user interface is not settled. It is Inertia and React today and may be Blade tomorrow, and
   that decision should not be able to reach into the domain code at all.

The blocker is not the code — it is where the code's *supporting files* live. All 113 migrations sit
in one flat directory with no per-module ownership; `routes/api.php` (486 lines) and `routes/web.php`
register every module's routes centrally; only eight of sixteen modules have a ServiceProvider. A
module is therefore not a thing that can be moved — it is a folder of classes plus an unmarked share
of three other files.

One genuine coupling is not a filing problem. The tenant scope every business model uses
(`Subjects\Tenancy\{TaxSubjectScope,ScopedToTaxSubject,TenantContext}`) is generic in behaviour and
Romanian in vocabulary: the mechanism is "every row belongs to an owner, every read is restricted to
the active one, every insert is stamped with it", but the column is `tax_subject_id`, the table is
`tax_subjects` and the model is `TaxSubject` — and `Subjects` also owns taxpayer registration and
membership, which no other product wants.

## Decision

Four packages, consumed by a thin application.

| Package | Holds | Depends on |
|---|---|---|
| `kern/audit` | the append-only hash-chained trail, its two read endpoints and its verifier | — |
| `kern/platform` | `Crypto`, `KeyManagement`, `Identity`, `Settings`, `Access`, `Approval`, `Logging`, the tenancy mechanism and the text matcher | audit |
| `kern/documents` | upload, virus scan, the encrypted object store, extraction | platform |
| `kern/legislation` | official sources, parsing, consolidations, provision history, hybrid search | platform |
| `kern/tax-romania` | taxpayer, fiscal parameters, tax engine, wallet, statement import, banks, FX, rentals, declarations, SPV, the operations console | platform, documents, legislation |
| `apps/api` | routes to pages, the user interface, wiring | all of the above |

Four things decide the shape.

**The base carries no product vocabulary.** Encryption, key management, the audit chain, identity
and settings are the same for any application. Nothing in `kern/platform` knows what a taxpayer is.

**Tenancy is a mechanism in the base, named by configuration.** The global scope, the stamping on
insert and the refusal when nothing is bound live in `kern/platform`; `config/tenancy.php` carries
the column, which this product names `tax_subject_id`. Who may act for whom is a contract,
`Tenancy\Contracts\TenantGate`, answered here by a non-revoked subject membership — elsewhere it
would be a seat in a workspace. An application with no such concept binds no gate and puts the
column on no table: identity, audit, crypto and documents all work unscoped.

**Owner foreign keys are added when the owner's table exists.** A package cannot declare a foreign
key to a table another package may not have installed. Each owner column is added with a shared
schema helper that reads the configured owner table and constrains only when that table is present —
so this installation keeps today's `cascadeOnDelete`, and an installation with no tenants gets an
indexed column and no constraint. The helper, and the configuration naming that table, arrive with
the first package migration that needs them; the migrations written so far all belong to the
application, which knows the table is there. The alternatives were rejected: a column with no constraint
anywhere loses referential integrity this product relies on, and a generic `tenants` table in the
base forces a table on every future application, which is what the base exists to avoid.

**Packages ship PHP, never pages.** Actions, jobs, models, migrations, config, JSON routes. The
Inertia pages, `AppShell` and its navigation stay in the application, so replacing React with Blade
replaces application code and leaves every package untouched.

`Documents` is its own package rather than part of the base because encrypted file handling is
independently useful, and it arrives anyway for anyone installing tax — `kern/tax-romania` requires
it, the way a Laravel application acquires `illuminate/*`.

`TaxKnowledge` splits. Its legislation half reaches only `Audit` and `Settings`; everything tax-bound
is in `FiscalParameters/*` and the two `Providers/Eloquent*Provider` classes that satisfy
`TaxEngine`'s contracts. The first half becomes `kern/legislation`; the second goes to
`kern/tax-romania`.

The Romanian modules are **one** package, not eight. `TaxEngine`, `Declarations`, `Fx`, `Banks`,
`Rental` and `Spv` have exactly one consumer between them, and `StatementImport` depends on seven
other modules precisely because it is where Wallet and Documents meet. Splitting them further buys
version numbers and cross-repository test matrices, and buys no reuse.

## How it is done

**Stage 0 — inside the application, nothing published.** Every module gets a ServiceProvider that
loads its own routes and migrations from paths relative to itself; the migrations move into
per-module directories, sorted by which table they create; the two route files become thin
aggregations; the tenancy scope moves out of `Subjects` and takes its names from configuration; the
conditional foreign-key helper arrives. Done when deptrac is green, the suite is green, and no
runtime behaviour has changed. This stage carries the whole risk of the work and it carries it while
everything is still in one place.

**Stage 1 — packages as path repositories.** `packages/*` wired through Composer path repositories,
so it stays one repository and one `composer install`. Modules move in dependency order — `Audit`
first, having no outbound dependencies, then crypto, key management and the object store, then
tenancy, identity and settings, then documents, legislation, and the remainder. Each move is a
folder rename plus a namespace change, because the provider already loads its own files.

**Stage 2 — prove the isolation.** Each package gets its own Testbench suite. The proof that matters
is installing `kern/legislation` into an empty Laravel application and ingesting and searching a law
with no tax code present.

**Stage 3 — publish, if wanted.** Private Packagist or plain git repositories with version
constraints. Path repositories are enough until a second product exists.

`Audit` is the pilot: zero outbound dependencies, so it answers whether the provider-and-paths shape
works here before sixteen modules commit to it.

## Consequences

**A rename touching roughly six hundred files.** Mechanical, and one enormous diff.

**Migration ownership has to be decided**, including for tables nobody owns cleanly.

**Cross-package foreign keys become conditional** — `rental_contracts`, `spv_messages` and
`declaration_submissions` all point at `documents`.

**CI grows.** One suite becomes several, and each package needs its own.

**The boundaries stop being advisory.** Today a cross-module reach is a deptrac failure a developer
can waive; afterwards it is a class that does not exist unless the package is required. That is the
point, and it is also the cost: a shortcut that used to take an afternoon now takes a released
version of another package.

**Nothing here is urgent.** Doing Stage 0 speculatively spends real time for a payoff that arrives
with the second product. It is sequenced for when that product is real.

## What was built, and where it differs from the above

Stage 0 and Stage 1 are done, in five packages rather than four, and with two changes to the table.

**`kern/audit` is its own package, and the platform depends on it.** It was the pilot because it has
no outbound dependencies, and keeping it separate turned out to be the right shape rather than an
accident of sequencing: every identity action and every settings change records an event, so the
platform requires it. That direction is also why `kern/audit` spells out its own route mounting
instead of using `Kern\Platform\Routing\PackageRoutes` — borrowing the helper would close the
dependency into a cycle.

**`Operations` went to `kern/tax-romania`, not to the base.** `OperationTask` enumerates
`fx.import_daily`, `legislation.sync` and `deposit.post_interest`, and `ProbeDataFreshness` probes
`ext_fx_rates` and the retrieval index. That is not a security spine; it is this product's console.
Making it generic means a task registry and a probe registry contributed by packages, which is worth
building when a second product asks for one and is speculative scaffolding until then.

Three things the work added that this ADR only gestured at:

- **`config('kern.routing')`** — where packages mount the routes they ship, and (as
  `kern.routing.tenant`) the segment and middleware that name a tenant. `v1/subjects/{subject}` and
  `tax-subject` live in the *application's* `config/kern.php`; `kern/documents` writes
  `Route::get('documents', …)` and learns nothing.
- **`Kern\Platform\Tenancy\Tenancy`** — the tenant column's name, for the query builders, array
  keys and migrations that `ScopedToTenant` cannot cover implicitly. `Tenancy::addColumn()` is the
  conditional foreign key promised above.
- **`Tenancy\Contracts\TenantNames`** — the second thing after `TenantGate` that only an
  application can answer: what a tenant is called, for recognising them on a document.

Four endpoints stayed in `apps/api` because they compose two packages' facts, which is what an
application is for: the user directory (platform identity + membership counts), platform-data
(settings + BNR and legislation freshness), the AI-consent set, and turning a reviewed document into
a wallet transaction. `routes/api.php` went from 486 lines to 52, and `app/` from 705 classes to six
files.

Migration ownership was decided by which package creates the table. The application kept exactly two:
Laravel's `failed_jobs`, and the one migration that renames tables belonging to both `Fx` and
`Legislation`.

## Stage 2, and what installing each package alone found

Every package has a Testbench suite that boots Laravel with that package, its dependencies, and
nothing else: `make check-packages`. Each one installs its own dev dependencies rather than borrowing
the application's `vendor/`, because a suite that shared it would prove nothing about isolation.

The product's own 1293 tests run against an application where identity, tenancy and eleven tax modules
all happen to be present, so they could not have found any of this:

- **`kern/audit` could not migrate alone.** The migration adding the identity foreign keys named
  `tax_subjects` and `users` unconditionally. Both keys are now added only where the table exists,
  which is the same rule `Tenancy::addColumn()` follows; where they exist, as here, nothing changed.
- **`kern/legislation` could not migrate alone.** Its tables are created as `source_documents` and
  renamed to `ext_source_documents` — by a migration in the *application*, which also folded
  `fx_sources` into the official source. Every later legislation migration named the prefixed tables,
  and nothing had created them. The legislation half of that rename is now the package's
  (`2026_09_15_399000`), idempotent so the installation that already ran the old one is unaffected, and
  `ext_official_sources.priority` came with it — the repository that upserts a source writes it, so a
  bare install could not fetch anything without it.
- **`kern/legislation` needed a queue dashboard configured.** Its three jobs took their timeout from
  `horizon.defaults.supervisor-legislation.timeout`. The number is the package's now, and Horizon's
  supervisor and the queue's `retry_after` read the same environment variable — the only way two config
  files can agree, since Laravel loads them alphabetically and neither can read the other.
- **`kern/tax-romania` could not migrate alone**, for the same reason as legislation: the fx tables'
  `ext_` prefix and the `fx_sources` fold were in the application. That migration belongs to this
  package — it needs both `fx_sources` and `official_sources`, and this is the package that has both.
  `apps/api/database/migrations` now holds one file, Laravel's `failed_jobs`.
- **`kern/legislation` still held the product's vocabulary.** `TaxRule`, `TaxRuleVersion` and
  `TaxRuleSource` were in it, read by nothing there. They are `kern/tax-romania`'s. What both packages
  genuinely share — "only a person may approve a version", `ForbidsAgentApproval` and the status enum —
  is the platform's, because it is a governance rule and not a tax one.

One correction to the above: the encrypted object store moved to `kern/documents` after all. The
first attempt was reverted on the belief that taking it out would leave the base unable to encrypt a
file — checking who used it showed that the base encrypts no files at all. `kern/legislation` stores
public law through its own unencrypted store, the statement importer reads a *document*, and the only
real consumer was `kern/documents`. The platform keeps the cipher; the thing that streams a file
through it, and the key type that can only name a document, went with the package that uses them.

**The log redactor knew what a CNP was.** Its key list held `cnp`, `cui`, `cod_fiscal`, `nr_reg_com`
and its patterns held the thirteen-digit CNP shape and ANAF's "cod de siguranță" — Romanian
vocabulary, in the base, missed by the same sweep that fixed `tax_subject_id` and `ObjectKey`. The
platform now carries only what means the same thing anywhere, and `Logging\Redactions` is where a
package declares its own; `kern/tax-romania` does, in its provider, and that package's suite fails if
the declaration is removed — which is the point, since a redaction nobody checks is a leak nobody
notices. Writing the test found that the Romanian branch of the OTP pattern had never matched
anything: it read `siguran[țt]a`, and the word is "siguranță".

The proof this ADR asked for by name passes: `ReadsAndSearchesALawAloneTest` finds an act through the
portal's web service, reads both of its consolidations, dates each until the day before the next,
and answers "what did article 145 say on 15 June 2021" with the text that was in force then — in an
application with no `tax_subjects`, no fiscal parameter and no tax engine. `kern/platform`'s
`TenantIsNotATaxpayerTest` does the same for tenancy, with the tenant called a workspace and the column
`workspace_id`, configured and nothing else.
