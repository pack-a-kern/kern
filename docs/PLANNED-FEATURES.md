# Planned features

Work agreed on but not designed in full yet. Each is designed together, explained and agreed before
anything is built. A plan, not a record: when one is built, its entry moves to a handoff note.

---

## 1. Sharing a taxpayer

Agreed 2026-10-20, while fixing "per-tenant roles are not enforced" (`SECURITY-FINDINGS.md`). Today
only an OWNER membership opens a taxpayer (`MembershipRole::opening()`); every other role is refused at
the tenant gate until this feature gives it rules.

**The idea so far:**

- The owner invites someone **by email**.
- Each person uses **their own account** — nobody shares a password or signs in as the owner.
- The owner can **revoke** an invitation or a person's access **at any time**, and it ends at once.

**Still to decide:**

- What each role may do: ADMIN, ACCOUNTANT and VIEWER exist in `MembershipRole` with no rules yet —
  read only, edit transactions, file declarations, connect SPV, see documents, invite others.
- What happens to an invitation sent to an address with no account (and how it says so without
  revealing whether the address has one — see the registration fix in `SECURITY-FINDINGS.md`).
- Whether the owner can see what a shared person did (the taxpayer's own activity, which today only a
  super administrator can read).
- How long an invitation stays valid.

**Constraints already set:** the rule lives in one place (`MembershipRole` / `SubjectMembership`), the
tenant gate stays the only door, and every grant and revoke is written to the audit chain.

---

## 2. Backups

Agreed 2026-10-20 as the answer to "no backups exist" (`SECURITY-FINDINGS.md`). Today nothing on the
server makes one, and losing the disk loses every account, taxpayer, transaction and declaration.

**What has to be saved:**

| What | Where now | If it is lost |
|---|---|---|
| The database | `postgres_data` volume | all data |
| The master key (OpenBao) | `openbao_data` volume | the database becomes unreadable for good, even from a database backup |
| The secrets file | `apps/api/.env` | the application cannot start, and some data cannot be read |
| Uploaded pictures and the like | `app_storage` volume | small losses |

Documents are on the external MinIO, not on this server; that MinIO needs its own backup.

**The trap:** `openbao_data` also holds `init.json` — the unseal key and the root token. A database
backup and a key backup kept together are everything, readable. The two are never stored side by side,
and this is tied to the unseal-key decision still open.

**The plan so far:**

1. `make backup` writes two separate files: data (database and uploads) and keys (OpenBao and `.env`).
2. Both encrypted to a **public key**: the server can make a backup and cannot open one, and the key
   that opens them stays with the owner, off the server — a broken-into server cannot read old backups.
3. Copied **off the server**: a backup on the same disk dies with it.
4. Daily and automatic, keeping the last 14 (configurable, not written in code).
5. `make restore`, and a test that really restores a backup into a throwaway copy and checks the
   application works. A backup nobody has restored is not a backup.
6. The steps in `DEPLOYMENT.md`, in plain words.

**Still to decide:**

- Where backups go: another server, an S3 or MinIO bucket, a disk elsewhere.
- How often, and how many to keep.
- Where the keys backup lives, apart from the data backup.
- The unseal key itself (`init.json`): kept as it is, split, or moved off the server.

---

## 3. Investments: government bonds, shares, and what a person holds

Agreed 2026-11-09. The decisions are **ADR 0025**; this is the order they are built in. Each task is
built, tested and shown before the next starts, and each ends with the definition of done in
`docs/AGENTS.md` §3: tests run on SQLite and Postgres, phpstan, pint, deptrac, the frontend checks where a
screen changed, a handoff note, `docs/STATUS.md`.

**Rules for every task** (on top of `docs/CODE-QUALITY.md` and `docs/DEPENDENCY-POLICY.md`):

- Every URL, host, path, page size, schedule, age and limit is configuration read from the environment,
  documented in the package's `.env.example`, with **no default in the config file**. A missing or
  malformed value fails at first use, naming the variable (ADR 0025 §4).
- No request to an official source except through `kern/legislation` (ADR 0025 §3).
- A parser validates every value before it becomes a DTO; a source that does not parse writes nothing.
- Readonly DTOs between layers; enums for every finite set; no model crosses a package boundary.
- Every table indexed for the queries that use it — no query without an index behind it.
- Short code: the shortest correct form, no layer that only forwards a call.
- No personal data in a fixture, a log line, a broadcast or a test name.

### Task 1 — One way out, and who goes out as what (`kern/legislation`, `kern/platform`, the settings screen)

1. `identify_as` (`browser` | `crawler`) on a source entry, the installation's default from
   `SOURCES_IDENTIFY_AS` (`browser` in `.env.example`, no default in the config file);
   `OfficialSourceRegistry` turns the mapping into the header and is the only code that does.
2. One setting, `sources.browser_user_agent`, replacing `legislation.portal_user_agent`; a migration moves
   the stored value; `SettingKey` and its words follow.
3. A settings card of its own, **Browser**: the browser `User-Agent`, the crawler's name, the crawler's
   contact.
4. Each source on the allowlist asked once as a browser; one that refuses is mapped to `crawler`; the
   results in the handoff note.
5. A package adds its own entries to the registry from its service provider, so `kern/legislation` names
   none of another package's sources.
6. Tests: legislatie.just.ro is sent the same header as before; every source goes out as the mapping says;
   a missing browser `User-Agent` fails, naming the setting; an entry from another package is allowlisted
   like one of its own.

### Task 2 — The package (`kern/investments`)

1. `composer.json`, service provider, `config/investments.php` (no defaults), `.env.example`, `README.md`,
   `lang/`, its Pest suite running on its own; registered in `apps/api`, in `deptrac`.
2. `InvestmentsConfig` and one DTO per source: read once, each value validated, a missing one fails.
3. The four market tables of ADR 0025 §2, their models, enums and indexes; Postgres-only statements
   guarded for the SQLite lane.
4. A law an instrument was read from is not deleted: `DocumentDependents` in `kern/legislation`, declared
   by this package, and the refusal on the laws screen.

### Task 3 — Parsers, against the real sources

Each from a real page or file saved as a fixture, and from hostile ones (a missing, renamed or reordered
column; an empty table; an oversize input; a malformed number, date or ISIN; markup in a cell).

1. One reader of "a table with these headers" (`Dom\HTMLDocument`, built into PHP).
2. BVB shares, bonds and dividends listings → DTOs.
3. A BVB instrument page → the issuer profile: meetings, shareholders.
4. The processing service: a second endpoint answering a spreadsheet as a grid (the statement reader's
   own code, without its statement semantics), with its Python tests; the Ministry's two spreadsheets →
   rows of symbol or issue code, issue month, value subscribed, number of subscriptions — a round's month
   carried down, year headings and totals skipped. Fixtures include the repeated Fidelis symbols.
5. A Fidelis order and a Tezaur order, as `kern/legislation` parses them (text and tables) → issues and
   payouts. Found doing it, and fixed in `kern/legislation`: an annex's articles were cited as the
   order's own and dropped — a Tezaur prospectus' table of issues with them; they are now cited within
   their annex ("ANEXĂ Art. 2"). A Fidelis coupon period is left empty: the order's cell for it does not
   read cleanly, and a coupon is the face value times the rate.

### Task 4 — The imports

1. `investments.import_government_bonds` (weekly) and `investments.import_bvb` (every weekday — no holiday calendar: a price is stored under the date the page prints,
   after the close), as `OperationTask` cases with their schedules in configuration.
2. The search per programme in configuration — the title words, the year and the act type `PortalQuery`
   takes, and the title patterns an act must match (no issuer: the search has none) — run through
   `PortalSearch` in the way the settings choose; every year from the programme's first on the first run,
   this year (and the last, within the configured overlap) after it; an order already a document never
   fetched again, one already read never parsed again. The titles each search returns are recorded in the
   handoff note, and the patterns fitted to them.
3. Ingest through `SourceIngestionService`; a file ingested before is not parsed again; parse all, then
   upsert in chunks by natural key; never delete; delisted and matured marked.
4. The subscription figures: a spreadsheet row is matched to an instrument by its symbol or issue code
   alone; only a code the list holds twice is told apart by the issue month and year. What does not match
   for sure is skipped and reported — never guessed.
5. Fidelis against BVB, by ISIN: a listed government bond with no order, or an order not listed once its
   trading day has passed, is reported by the run.
6. The event for a new issue on offer.
7. Tests: a run repeated changes nothing; a source that does not parse writes nothing; a source that is
   down fails the run and keeps the previous data.

### Task 5 — The endpoints (`kern/investments`)

The read endpoints of ADR 0025 §7, cursor-paginated and bounded, rate-limited, behind authentication;
the issuer profile: `GET` answers the stored one and never fetches, `POST …/refresh` reads it from BVB only when asked (the stored one while younger than its refresh age). No screen for it now. Tests for each filter, each
bound and each refusal.

### Task 6 — Holdings (`kern/tax-romania`)

1. `AccountKind::INVESTMENTS`, `investment_lots`, scoped to the tax subject, with its indexes.
2. Adding a holding: the instrument's terms fill the form; units, price per unit, date, fees and where it is
   held are entered.
3. Coupons, dividends and the repayment at maturity generated from the payouts, as a deposit's interest is.
   `DayCountCalculator` (in `Wallet`, where it stays) gains ACT/ACT for Tezaur, tested on the order's own
   example.
   A dividend only for the units held the day before the ex-dividend date and not sold before it, credited
   net of the 16% withheld, the withheld part recorded. A corporate bond gets no coupons (BVB has no
   payment dates for it). A Fidelis not yet trading, and a Tezaur, valued at face value.
4. Closing early, all or part, with the units and the price per unit; the amount and the result computed;
   a Tezaur holding refused; the value recomputed after each BVB run, shown as unrealised.
5. The notice for a new issue on offer (ADR 0022), with its switch.
6. Tests, including cross-tenant isolation and a lot adopted by its `external_ref` (matched by blind index, stored encrypted) without a duplicate.

### Task 7 — Tax (`kern/tax-romania`)

Nothing about a tax in code: every value is a fiscal parameter read from the stored Fiscal Code and approved
(ADR 0025 §10).

1. **Research first: corporate bonds.** In the stored Fiscal Code, the treatment of a corporate bond's
   interest and of the gain on selling one; written into ADR 0025 §10 before any code, with the articles.
2. `ParameterAnswer` gains `yes_no` and `days`, with their checks and tests.
3. The six parameters of §10 in `config/fiscal_parameters.php`, each with its question in the law's words.
   Run the reading; the candidates and their quoted sentences go in the handoff note for approval.
4. The categories `PUBLIC_DEBT_INCOME`, `SECURITIES_GAIN`, `DIVIDENDS` in
   `config/reference/tax_categories.php`.
5. The rules: public debt untaxed only while its exemption is approved; the share gain at the long or short
   rate by the holding period; dividends at the dividend rate — each recorded as withheld, never owed again.
6. `CassOtherIncomeRule` leaves out `PUBLIC_DEBT_INCOME` while its exclusion is approved.
7. Tests: a parameter not approved stops the assessment and names it; an exemption answered no taxes the
   income; the weighted average price per symbol; first in, first out; either side of the holding period;
   a loss not offset; nothing of public debt reaching the CASS base.

### Task 8 — Screens

Market, an instrument, adding a holding, closing it early — per `docs/UI-PATTERNS.md`, every word in both
dictionaries, nothing parsed or computed in the browser.
