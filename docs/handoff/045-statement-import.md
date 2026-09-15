# 045 — Bank statement import (plan)

> **Status: plan. Nothing in this document is built yet.** It records the decisions taken on
> 16 September 2026 and the design they imply, so the work can start from a settled shape.
> A reading copy of the same plan is published at
> <https://claude.ai/artifact/Qzq3mfWhJ25dQaHPo8FtA5>.

A person uploads the statement their bank gives them — CSV, Excel or PDF — and it becomes
transactions on the Transactions page, each with a bank, a proposed tax category and tags. The file
is read locally; the AI only matches columns and says what unknown counterparties are.

## Why, and what replaces what

Today a statement can only reach the ledger through the document pipeline, where the model is asked
to rewrite **every row** as JSON. That is slow, it is capped by `AI_EXTRACTION_MAX_TOKENS` (4096, so
a long statement is cut off), it sends every name and figure to a provider, and nothing imports the
result — `review.tsx` only counts the rows it produced.

This plan reads the file deterministically and spends the model only where a machine cannot decide:
which column is which, and what an unfamiliar counterparty is. **The AI statement reading stays for
now**; removing it is the last phase, once import has replaced it.

## Decisions taken

| Decision | Detail |
|---|---|
| Formats | CSV, XLS, XLSX, and PDF **with a text layer**. MT940 and CAMT.053 later |
| Column matching | AI on **every file**, confirmed by the person. Mappings are **not** remembered between files |
| Remembered answers | Per counterparty: category and tags. This is what makes the second statement cheap |
| Tags | Created by the AI, which is shown existing tags and reuses them where they fit; people rename and merge. Tags filter only — they never affect tax |
| Bank | A label on the transaction, not a separate account. Money stays in General, so the balance rules are untouched |
| AI statement reading | Kept for now; removed in the last phase |

## Two corrections to earlier assumptions

- **`apps/processing` uses `pypdf`, not PyMuPDF.** pypdf cannot find tables, so PDF statements need
  `pdfplumber` (MIT). PyMuPDF is not an option to add: it is AGPL-licensed.
- **The PHP image has no `zip` extension** (`docker/php/Dockerfile` installs `pdo_pgsql bcmath intl
  pcntl` plus pecl redis), which `.xlsx` needs. Spreadsheets are therefore read in the Python
  service beside the PDFs — which also keeps parsing of untrusted files in the container that holds
  no database credentials.

## The flow

| # | Step | Who |
|---|---|---|
| 1 | Choose account and bank, upload the file. Stored as an encrypted document (evidence), virus-scanned as usual, and **marked as an import so the AI document reading skips it** | Person · PHP |
| 2 | File becomes a grid of text cells, plus a *shape* per column (`date DD.MM.YYYY`, `number -1.234,56`, `text ~38 chars`) computed from all its values | Python |
| 3 | AI answers which column is what, from headers and shapes only; the person confirms against a 20-row preview | AI · Person |
| 4 | Every row gets a fingerprint; rows already in TaxOS are marked and excluded by default | PHP |
| 5 | Remembered counterparties are applied; unique unknown ones go to the AI in batches for category and tags | PHP · AI |
| 6 | Review, then Import: a chunked background job saves rows, writes counterparty rules from the final answers, records one audit event, and can be undone | Person · Queue |

Without AI consent (`DocumentAiConsentGate`), steps 3 and 5 are skipped and both screens are filled
by hand — the import still works end to end.

## Data model

Every table is tenant-scoped (`tax_subject_id`). Free text is encrypted; lookups on it use blind
indexes, the mechanism CNP lookup already uses.

### `financial_transactions` (changed)

| Column | Change | Why |
|---|---|---|
| `bank_id` | add, uuid null → `ext_banks` | Bank label for filters and reports; deposit rows take their deposit's bank |
| `institution` | **drop** | Free-text column nothing reads or writes; empty on every row |
| `import_id` | add, uuid null → `statement_imports` | What undo deletes |
| `counterparty_blind_index` | add, char(64), indexed with the subject | Find the remembered answer without decrypting. Backfill command for existing rows |
| `external_id_blind_index` | existing, unique per account | Now filled with the import fingerprint, so the database refuses a duplicate |

### `statement_imports` (new)

`account_id`, `bank_id`, `document_id`, `format` (`CSV|XLS|XLSX|PDF`), `status`
(`READING → MAPPING → CLASSIFYING → REVIEW → IMPORTING → IMPORTED`, or `FAILED` / `UNDONE`),
`column_mapping` (json — the confirmed mapping, kept as provenance, never reused for another file),
`mapping_source` (`AI|PERSON`), `row_count`, `duplicate_count`, `excluded_count`, `imported_count`,
`failure_reason` (a code, never a provider response), `created_by`, `imported_at`, `undone_at`.

### `statement_import_rows` (new, staging until import or undo)

`import_id`, `row_number`, `booked_at`, `amount_minor`, `currency_code`, encrypted `description`,
`counterparty` and `reference`, `counterparty_blind_index`, `fingerprint`,
`duplicate_transaction_id`, `category_code`, encrypted `tags` (names until import creates them),
`proposal_source` (`RULE|AI|PERSON|NONE`), `included`.

Purged by a scheduled task after `statement_import.staging_retention_days`.

### `tags`, `transaction_tags` (new)

`tags.name` encrypted (a tag can be personal — "doctor Ion"), normalised (trimmed, lower-case,
single spaces), with `name_blind_index` unique per subject so "groceries" cannot exist twice.
`transaction_tags(transaction_id, tag_id)` as the primary key, plus an index on `(tax_subject_id,
tag_id)` so a tag filter over a date range stays fast.

### `counterparty_rules`, `counterparty_rule_tags` (new)

`counterparty_blind_index` + `direction` (`IN|OUT`), unique per subject; `category_code` (checked
against `TaxCategoryCatalogue` and the direction); `source` (`PERSON|AI`) — a person's answer is
never overwritten by a proposal. A rule is written from the **final** answer: on import commit, and
when someone classifies or tags a transaction by hand.

## Duplicates

Nothing hashes a whole row today, and `institution` is part of no comparison.

**At import, before saving (new).** Fingerprint = keyed hash of account, bank, date, amount,
currency and the normalised description, counterparty and reference — or the bank's own reference
alone where the file has one. Identical real rows in one file carry an occurrence number, so two
coffees on the same day both import and a re-import matches both again. Stored in the existing
`external_id_blind_index`, unique per `(account_id, external_id_blind_index)`.

**After saving (unchanged).** `DuplicateTransactionGroups` groups rows with the same day, amount and
currency whose counterparties could be the same party (`PartyName::sharesName`, because "KAUFLAND
1234 BUCURESTI" and "Kaufland Romania SCS" are one shop). Merge or Keep, as today. This is what
catches the same payment recorded two ways — a receipt typed by hand and the statement row for it —
whose text differs, so the fingerprint cannot see it.

## Reading files — `POST /v1/statements/parse`

New endpoint in `apps/processing`, authenticated like `extract-text`.

| Format | Library | Handles |
|---|---|---|
| CSV | stdlib `csv` + `charset-normalizer` | Encoding (Windows-1250 is common here), separator, quoting, BOM |
| XLSX | `openpyxl` (read-only, `data_only`) + `defusedxml` | Values only, formulas never evaluated, `.xlsm` refused, sheet choice |
| XLS | `xlrd` (2.x reads only `.xls`) | The old Excel format several banks still export |
| PDF | `pdfplumber` | Tables from word positions; repeated page headers merged, wrapped descriptions joined, balance/total lines dropped |

Returns format, sheet, header-row guess, per-column header + shape + filled ratio, all rows as text
cells, and warning codes. Refusals carry a reason the screen translates: `NO_TEXT_LAYER` (scanned —
hint to export CSV/Excel instead), `ENCRYPTED_FILE`, `TOO_LARGE`, `NO_TABLE`.

`SniffedFileType` gains XLS (OLE2 signature) and XLSX (a zip that actually contains a spreadsheet
part, so a renamed `.docx` is refused). Library versions are pinned when the phase starts, after
checking releases and `pip-audit`.

## The two AI calls

Both go through the existing provider settings, consent gate, timeouts and schema validation.

**1 — Column matching, once per file.** Sent: row count and, per column, index, header and shape.
**No values.** Returned: `date {column, format}`, `amount {mode: signed|debit_credit, column(s),
decimal, thousands}`, `currency`, `description[]`, `counterparty`, `reference`, `ignored[]`.
Validated in PHP before it is shown: columns exist, exactly one amount shape, and at least
`statement_import.parse_threshold` (0.95) of rows parse as a date and an amount.

**2 — Classification, unique unknown counterparties in batches of 50.** Sent: interface language,
the allowed category codes per direction, the subject's existing tag names, and per counterparty an
id, direction, masked name, masked sample description and row count. Runs of six or more digits and
IBAN-shaped text are masked; no amounts, no dates, no IBAN, never a CNP. Returned: category (dropped
unless it fits the direction) and at most three tags, normalised, reusing an existing tag when it
matches. Each call records an audit event with counts only; a failure leaves rows unproposed rather
than failing the import.

## Screens

- **`/wallet/imports/new`** — File → Columns → Review → Import. Review is a paged table: include,
  date, amount, counterparty, category select filtered by direction, tag chips, proposal source
  badge, duplicate badge; filters for new / duplicates / without category; bulk set category, add
  tag, exclude.
- **`/wallet/imports`** — history with counts, status and Undo.
- **`/wallet/transactions`** — "Import statement" beside "Add transaction"; bank and tag filters;
  bank and tag chips per row; a dialog to rename, merge and delete tags.
- **Add / edit transaction** — optional bank select, tags input suggesting existing tags.
- **`/wallet/reports`** (later) — spending by tag per month, in/out per bank, category totals, per
  currency with the display-currency indication the dashboard uses.

**Prerequisite:** `ShowTransactionsController` returns one capped window (100 default, 250 max)
filtered in the browser. Imports make that untenable — the list moves to server-side paging and
filters (account, bank, tag, classification, direction, date range) in phase 1.

## Where the code lives (`deptrac.php`)

| Module | Gets | Boundary |
|---|---|---|
| `StatementImport` (new layer) | Import lifecycle, staging, fingerprints, mapping validation, both prompts, commit and undo jobs | May access Support, Subjects, Audit, Settings, Wallet, Documents, Banks. Nothing depends on it but HTTP — the shape `Operations → Documents` already has |
| `Wallet` | `bank_id`, tags, counterparty rules, a bulk `RecordImportedTransactions`, server-side list filters | Unchanged (`Support, Subjects, Fx, Audit`); `ext_banks` read through the query builder as the dashboard does |
| `Documents` | XLS/XLSX detection, import uploads skipping AI reading, a small structured-JSON call on the existing provider | Unchanged; knows nothing about Wallet |
| `apps/processing` | `/v1/statements/parse` | No database credentials, as today |

Saving reuses the existing guards — non-zero amount, a source that accepts transactions, splits
summing to the total, category fitting the direction — extracted so manual entry and import share
them. Rows are written with `RecordSource::IMPORT`, which already exists. One
`WalletStatementImported` audit event per import (with counts) plus `WalletStatementImportUndone`,
rather than one event per row.

## Config (`config/statement_import.php`)

`formats`, `max_file_bytes` (10 MB), `max_rows` (5 000), `max_pdf_pages` (60), `preview_rows` (20),
`parse_threshold` (0.95), `ai.batch_size` (50), `ai.max_tags` (3), `commit_chunk` (500),
`staging_retention_days` (30), and `prompts.*`. Defaults are starting values to confirm; the limits
also appear as settings an administrator can change. Every string on screen exists in RO and EN,
including the refusal reasons.

## Security

- Untrusted files are parsed only in the Python service (no DB credentials), after the virus scan.
- Column matching sends no real values; classification masks digit runs and IBAN-shaped text.
- Both AI calls require consent; without it the flow is manual.
- Descriptions, counterparties, references, tag names and staging rows are encrypted at rest.
- Audit: import, undo, each AI call (counts only), tag rename/merge/delete.
- Nothing about content is logged — no filenames, cells, names or provider responses.
- XML bombs blocked, formulas not evaluated, macro workbooks refused.
- Cell text reaches the model as data inside a schema; an answer outside the schema or the catalogue
  is dropped.

## Tests

Python fixtures per format (Romanian CSV in Windows-1250 with semicolons, XLS, two-sheet XLSX,
multi-page PDF with repeated headers and wrapped descriptions, scanned PDF, password-protected file,
XML bomb). PHP: signed vs debit/credit mappings, a mapping below the threshold refused, a fake AI
returning a bad schema; the same file twice imports nothing new, two identical coffees both import,
overlapping statements match, a forced duplicate is refused by the database; a remembered rule
applied without AI, a person's rule never overwritten, a category against the direction dropped,
masking verified on the payload; chunked commit, one audit event, undo removing exactly the import's
rows; no AI call without consent; cross-tenant isolation for imports, tags and rules.

## Phases

1. **Bank, tags, and a list that scales** — `bank_id` replaces `institution`; counterparty blind
   index + backfill; tags, transaction tags, counterparty rules; tags dialog; bank and tags on the
   add/edit form; transactions list moved to server-side paging and filters.
   *Usable: bank labels and tags on hand-typed transactions.*
2. **Reading statement files** — XLS/XLSX detection, `/v1/statements/parse` with fixtures and
   limits, import uploads skipping AI reading. *Nothing visible yet.*
3. **Import without AI** — imports and staging tables, manual mapping with live preview,
   fingerprints and duplicate marking, review page, chunked commit, undo, audit, history page.
   *Usable: a full import by hand.*
4. **AI column matching** — structured-JSON call, shapes, prompt, validation, prefilled mapping.
   *Usable: columns matched automatically.*
5. **AI classification and remembered answers** — rules first, batched AI for unknown
   counterparties, categories and tags with their source, rules written on commit.
   *Usable: most rows arrive classified and tagged.*
6. **Reports** — by tag per month, in/out per bank, category totals, per currency.
7. **Later** — remove the AI statement row reading; MT940 and CAMT.053.

## Risks and open points

| Point | Proposal |
|---|---|
| Consent for column matching, which sends no personal data | Require it anyway, for one simple rule: no consent, no AI calls. **To confirm** |
| PDF layouts vary by bank | When too few rows parse, say so and suggest the CSV/Excel export |
| Importing older statements | Past months change General's history; the daily never-below-zero rule still holds. Worth a line on the review page |
| Same payment, different wording | Fingerprint cannot see it; the duplicates view catches it after import |
| Pending card rows | Where the mapping includes a status column, rows marked pending are excluded by default |
| Tag drift | Existing tags are sent and answers normalised; merge fixes the rest |
