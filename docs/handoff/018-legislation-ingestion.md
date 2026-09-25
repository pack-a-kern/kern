# 018 — Legislation ingestion (structural parsing, temporal model, chunking, candidate contract)

**Milestone:** M3   **Status:** complete (live fetch verified; parsing dates always require human review — see below)
**Date:** 2026-09-08

## What was built

Milestone 3's legislation/ANAF document ingestion, extending `Kern\Legislation` (M2's
source registry, allowlisted fetcher, and immutable raw storage — reused as-is, no second fetcher
built). Five pieces, matching the task brief's five numbered requirements:

1. **Document versioning** — already existed from M2 (`source_document_versions`, content-addressed,
   never overwritten). This task added the three parsing-pipeline columns `BLUEPRINT.md` §6.8 lists
   but M2 deliberately deferred: `parser_version`, `supersedes_version_id`, `status`. Wired
   `supersedes_version_id` into the existing `SourceIngestionService::createVersionRow()` so a
   new version automatically records which prior version (if any) of the *same* `source_document`
   it replaces — a plain lineage fact, not an effective-date inference.

2. **Structural parsing** — `Kern\Legislation\Parsing\LegislatieJustRoHtmlParser`, a real
   HTML structural parser built against **genuinely observed** `legislatie.just.ro` markup (two
   live fetches made during this task — the Romanian Constitution and a Decree — see "Sources
   consulted"), not guessed. Produces `ARTICOL`/`ALINEAT`/`LITERA`/`PUNCT` sections
   (`Kern\Legislation\Enums\SectionType`) with correct parent/child nesting, citation
   labels (`"Art. 27 alin. (1) lit. a)"`), ordinals, and per-section text excluding editorial
   notes (`S_NTA`) and collapsed-view UI placeholders (`S_LIT_SHORT`). Verified against the real
   Constitution: 614 sections, zero duplicate citation labels, zero empty leaf text.
   `Kern\Legislation\Services\LegislationSectionPersister` turns a parsed tree into
   `source_sections` rows.

3. **The temporal model (the hard part)** — `Kern\Legislation\Services\LegislationTemporalQuery`.
   See "The temporal model" section below — this is the part every later milestone depends on.

4. **Chunking** — `Kern\Legislation\Chunking\SourceSectionChunker` (pure) +
   `SourceSectionChunkAssembler` (DB-backed metadata assembly). Chunking only — no embeddings, no
   Qdrant, no vector index (Milestone 11's job, explicitly out of scope here).

5. **`fiscal_parameters`/`tax_rules` skeleton** — tables and Eloquent models only, per `BLUEPRINT.md`
   §6.8. `status` defaults to `CANDIDATE` and `Kern\Platform\Approval\ForbidsAgentApproval`
   makes `APPROVED` **unreachable from application code**, not just discouraged by convention —
   tested (`tests/Feature/TaxRomania/FiscalParameters/ForbidsAgentApprovalTest.php`).

6. **AI candidate-extraction contract** — `Kern\Legislation\CandidateExtraction\CandidateChangeSetParser`
   (strict JSON→DTO validation matching `BLUEPRINT.md` §9.2's exact shape) and
   `CandidateCitationVerifier` (DB-backed: does the cited `document_version_id`/`section_id`
   actually exist). No LLM call anywhere; no provider plumbing (ADR 0007, `apps/processing`'s job,
   still undecided).

## The temporal model — read this before touching anything date-related

**The single most important fact this task found**: `legislatie.just.ro`'s rendered page carries
**no machine-readable effective-date field**. It shows a publication line
(`"MONITORUL OFICIAL nr. 498 din 19 iulie 2010"` — genuinely parseable, see `RomanianDateParser`)
and a "Formă consolidată" UI toggle, but *when* the currently-rendered text became/stops being the
governing text is never stated as structured data on the page — only inside free-text legal
prose ("intră în vigoare la..."), which is substantive law, not metadata, and parsing it would be
guessing (`docs/AGENTS.md` §2 forbids exactly this).

**Consequence, by explicit design, not an oversight**: `LegislatieJustRoHtmlParser` never sets an
effective date. `LegislationSectionPersister::persist()` accepts `$effectiveFrom`/`$effectiveUntil`
as **caller-supplied, optional parameters that default to `null`** — it never inspects parsed text
to guess them. The resulting `source_document_versions.status`
(`Kern\Legislation\Enums\SourceDocumentVersionStatus`) is computed purely from whether
`$effectiveFrom` ended up non-null: `PARSED` if so, `REQUIRES_REVIEW` otherwise. **Every version
ingested from `legislatie.just.ro` by this milestone ends up `REQUIRES_REVIEW`, honestly, every
single time** — this is not a bug to fix later; it is the correct state until a human (or a
future, stronger signal this task didn't find) supplies the real date.

**The query side — `Kern\Legislation\Services\LegislationTemporalQuery`** — is what answers
"what was in force on date X" once dates *are* known:

- `versionInForceOn(sourceDocumentId, date)`: exactly one `source_document_versions` row whose
  `[effective_from, effective_until]` range contains `date`, or an exception. **Never a
  heuristic.** Zero matches → `NoEffectiveVersionFoundException` (a real gap: don't fall back to
  "the newest version anyway"). More than one match → `AmbiguousEffectiveVersionException` (two
  versions' ranges overlap — a data-integrity defect to fix, never something this class resolves
  by picking one). A version stuck at `REQUIRES_REVIEW` (i.e. `effective_from IS NULL`) is
  correctly invisible to this query — it simply cannot be returned, by construction.
- `sectionsInForceOn(sourceDocumentId, date, ?type)`: resolves the version above, then filters its
  sections by *their own* `effective_from`/`effective_until` (nullable — see below) intersected
  with the date, depth-first ordered.

**Proven, not asserted** — `tests/Feature/Legislation/LegislationTemporalQueryTest.php`,
test `"returns the 2023 text for a 2023 date and NEVER the 2026 text"`: two versions of the same
`source_document`, effective `2023-01-01..2025-12-31` and `2026-01-01..∞`, with **different**
synthetic section text each. Querying `2023-06-15` returns only the 2023 text and the assertion
explicitly checks the 2026 text never leaks in (and vice versa). This is the exact proof the task
brief asked for.

**Section-level `effective_from`/`effective_until` — beyond `BLUEPRINT.md`'s literal `source_sections`
column list, added deliberately** (see that migration's docblock for the full justification): a
single consolidated document snapshot can legitimately contain articles with *different* real
effective windows (a forward-dated amendment already visible in the text). A section with its own
dates set narrows visibility below its version's range; a section with `null` dates simply
inherits the version's range. This parser never populates them (same "never guess" reason as
above) — the columns exist so a human/future signal can, without a schema change.

**`supersedes_version_id`** records lineage (which version preceded this one for the same
document) — a plain database fact set automatically by `SourceIngestionService`. It does **not**
imply anything about effective dates; nothing in this codebase infers `effective_until` of the old
version from the mere existence of a newer one. That remains a human decision.

## Files created / changed

**Migrations** (`apps/api/database/migrations/2026_09_08_301000` through `301006`)
- `301000` — adds `parser_version`/`supersedes_version_id`/`status` to `source_document_versions`.
- `301001` — `source_sections`. **Read this migration's docblock**: the self-referencing
  `parent_id` foreign key is added via a *separate* `Schema::table()` call after `Schema::create()`
  — empirically required. Declaring it inline inside the same `Schema::create()` call **fails on
  real Postgres** (`"there is no unique constraint matching given keys for referenced table
  source_sections"` — the FK's `ALTER TABLE ADD CONSTRAINT` runs before the table's own `PRIMARY
  KEY` is visible to reference) while working fine on sqlite — this is exactly the kind of defect
  `docs/handoff/016-postgres-suite-stabilisation.md` warns about, caught here by actually running
  `migrate:fresh` against real Postgres before calling this done, not assumed from sqlite.
- `301002`–`301006` — `fiscal_parameters`, `fiscal_parameter_versions`, `tax_rules`,
  `tax_rule_versions`, `tax_rule_sources` (skeleton, `BLUEPRINT.md` §6.8). `fiscal_parameter_versions.source_section_id`
  and `tax_rule_sources.source_section_id` are **NOT NULL** — `BLUEPRINT.md`'s schema listing marks
  every nullable column explicitly with the word "nullable" and these two are not, which is also
  exactly `docs/AGENTS.md` rule 3 ("never introduce a tax rate/threshold without a
  `source_section_id`") enforced at the schema level, not just by convention.

**`Kern\Legislation\Enums`**
- `SectionType.php` — `ARTICOL|ALINEAT|LITERA|PUNCT`.
- `SourceDocumentVersionStatus.php` — `PENDING|PARSED|REQUIRES_REVIEW`.
- `ApprovalStatus.php` — `CANDIDATE|APPROVED|SUPERSEDED|REJECTED`, shared by both `_versions`
  tables (stable duplicated domain knowledge, per `docs/CODE-QUALITY.md` §1 — not incidental).
- `SourceRelation.php` — `PRIMARY|AMENDMENT|GUIDANCE|EXAMPLE`.
- `FiscalParameterValueType.php` — `MONEY|DECIMAL|INTEGER|DATE|JSON`.

**`Kern\Legislation\Concerns`**
- `ForbidsAgentApproval.php` — trait; registers an Eloquent `saving` guard on any model with a
  `ApprovalStatus`-cast `status` attribute, throwing
  `Exceptions\AgentApprovalNotPermittedException` if it is ever `APPROVED`. Applied to
  `FiscalParameterVersion` and `TaxRuleVersion`. **`APPROVED` is unreachable from this module's
  code, full stop** — there is no escape hatch because no human-review workflow exists yet to need
  one.

**`Kern\Legislation\Parsing`**
- `LegislatieJustRoHtmlParser.php` — the structural parser; `VERSION` constant `'1.0.0'`. Read its
  docblock in full — it documents exactly which class names were verified live vs. extrapolated
  (`S_PCT`/`PUNCT` was never observed and is a naming-convention guess flagged as such).
- `ParsedSection.php`, `ParsedLegislationDocument.php` — parser output DTOs.
- `RomanianDateParser.php` — parses `"DD <Romanian month> YYYY"` (a calendar-name lookup, not a
  fiscal fact) for the publication line only.
- `Exceptions/UnparsableLegislationDocumentException.php`.

**`Kern\Legislation\Services`**
- `LegislationSectionPersister.php` — parsed tree → `source_sections` rows; re-parsing an already-
  parsed version **replaces** its section set (transactional) rather than duplicating — this is a
  derived/recomputable index of immutable raw bytes, not a second write to the raw source itself
  (`docs/AGENTS.md` rule 17 governs the latter, untouched here). Backfills
  `source_documents.publication_date` once, never overwrites a differing value later.
- `LegislationTemporalQuery.php` — see "The temporal model" above.
- `SourceIngestionService.php` (**modified**, M2 file, in scope per this task's path list) —
  `createVersionRow()` now looks up the previous latest version for the same `source_document`
  and records it as `supersedes_version_id`.

**`Kern\Legislation\Actions`**
- `ParseLegislationDocumentVersion.php` — re-parses an already-ingested version by id (reads bytes
  back from `RawSourceObjectStore`).
- `IngestAndParseLegislationDocument.php` — the common end-to-end path: M2's
  `SourceIngestionService::ingest()` then the action above. Never sets an effective date.

**`Kern\Legislation\Chunking`**
- `ChunkableSection.php`, `SourceSectionChunk.php` — DTOs. Carry every `BLUEPRINT.md` §15.3 metadata
  field this module can honestly populate (`source_tier`, `issuer`, `document_type`,
  `document_version_id`, `section_id`, `effective_from`/`until`, `publication_date`, `page`).
  **`tax_years`/`subject_types`/`tax_types` are deliberately absent** — nothing classifies a
  section against those yet (that's `fiscal_parameter_versions`/`tax_rule_sources` linkage,
  Milestone 4/5); an always-null placeholder would be exactly the speculative field
  `docs/CODE-QUALITY.md` §2 forbids (same judgement `docs/handoff/014-source-registry-bnr.md`
  applied to `fx_rate_anomalies.resolved_at`).
- `SourceSectionChunker.php` — pure; sentence-boundary-aware greedy packing toward a max character
  budget, hard-splits an over-long single sentence, no overlap window (no consumer to tune one
  against yet).
- `SourceSectionChunkAssembler.php` — DB-backed: loads a section's full ancestry and delegates to
  the chunker above.

**`Kern\Legislation\CandidateExtraction`**
- `DataTransferObjects/{Citation,CandidateChange,CandidateChangeSet}.php`.
- `CandidateChangeSetParser.php` — `fromJson()`/`fromArray()`: strict validation of `BLUEPRINT.md`
  §9.2's exact shape. Rejects malformed JSON, a non-object top level, any missing/wrong-typed
  field, a candidate change with **zero citations**, a malformed `quote_hash` (must be 64 lowercase
  hex chars), out-of-range `confidence`, and `effective_until` before `effective_from`. Accepts an
  *empty* `candidate_changes` array — "no changes found" is a legitimate outcome, not an error.
- `CandidateCitationVerifier.php` — DB-backed: confirms `document_version_id` exists, every cited
  `section_id` exists, and each cited section actually belongs to the referenced document version.
- `Exceptions/{InvalidCandidateChangeSetException,UnknownSourceReferenceException}.php`.

**Models** — `SourceSection`, `FiscalParameter`, `FiscalParameterVersion`, `TaxRule`,
`TaxRuleVersion`, `TaxRuleSource` (all new); `SourceDocumentVersion` (**modified** — exposes the
three new columns; its docblock now explicitly distinguishes immutable identity columns from
mutable parsing-metadata columns, since this task legitimately updates the latter in place).

**Factories** — `SourceSectionFactory`, `FiscalParameterFactory`, `FiscalParameterVersionFactory`,
`TaxRuleFactory`, `TaxRuleVersionFactory`, `TaxRuleSourceFactory`.

**Tests**
- Unit (pure, no DB/HTTP): `LegislatieJustRoHtmlParserTest`, `RomanianDateParserTest`,
  `Chunking/SourceSectionChunkerTest`, `CandidateExtraction/CandidateChangeSetParserTest`.
- Feature (DB): `LegislationSectionPersisterTest`, `LegislationTemporalQueryTest`,
  `ParseLegislationDocumentVersionTest`, `ForbidsAgentApprovalTest`,
  `Chunking/SourceSectionChunkAssemblerTest`, `CandidateExtraction/CandidateCitationVerifierTest`.
- `SourceIngestionServiceTest.php` (M2 file, **modified**) — added a `supersedes_version_id`
  assertion to the existing "creates a second version when content changes" test.
- `LegislatieJustRoLiveFetchTest.php` — a **real** fetch against real `legislatie.just.ro`,
  through the real M2 fetcher and this task's real parser. Skipped unless
  `LEGISLATIE_LIVE_TEST=1`, same opt-in pattern as `OpenBaoLiveIntegrationTest`/
  `EncryptedObjectStoreLiveMinioTest`. Asserts only structural properties (non-empty sections,
  well-formed citation labels, zero duplicate labels) — never a specific article's real legal
  text, since asserting fabricated legal content would violate `docs/AGENTS.md` §2 even inside a
  test. **Run and passed for real** — see "How to verify".

## Key decisions

1. **The parser's class-name conventions are read from two genuine live fetches, not invented.**
   `Public/DetaliiDocumentAfis/47355` (Constituția României) and one Decree page, fetched
   2026-09-08. `S_TTL`/`S_CAP`/`S_SEC`/`S_ART`/`S_ALN`/`S_LIT`/`S_PAR`/`S_NTA`/`S_PUB` and their
   `_TTL`/`_DEN`/`_BDY`/`_SHORT`/`_PAR` suffixes are all confirmed real. `S_PCT` (`PUNCT`) was
   **not** observed in either fetch — it is included by extrapolation from the uniform naming
   pattern every other level follows, flagged in the parser's own docblock as unverified.
2. **Only `ARTICOL`/`ALINEAT`/`LITERA`/`PUNCT` become `source_sections` rows**, per the task's
   explicit scope, even though the real markup also has `Titlu`/`Capitol`/`Secțiune` container
   levels above `Articol`. The parser walks straight through those wrapper levels without creating
   a row for them (`findTopLevelContainers()`'s depth-first search stops at the first recognised
   container on each branch). A future task can add those levels without a parser rewrite.
3. **`S_NTA` (editorial "Notă" amendment-history commentary) and `_SHORT` (collapsed-view UI
   duplicate placeholders) are excluded from extracted text** — found live, not guessed: the first
   draft of the parser included `S_NTA`'s text in an article's own text, which silently corrupted
   what a citation's `text` represents (commentary about the law, not the law's own words). Fixed
   before this task's own smoke test even reached the test suite — see "Sources consulted".
4. **Chunking produces DTOs only; nothing is persisted to a `chunks` table.** No such table exists
   in `BLUEPRINT.md` §6.8, and there is no consumer yet (Milestone 11 builds the vector index this
   feeds) — persisting an un-consumed table would be exactly the speculative scaffolding
   `docs/CODE-QUALITY.md` §2 forbids.
5. **The AI candidate-extraction contract never persists a row.** `CandidateChangeSetParser`/
   `CandidateCitationVerifier` validate the *shape*; nothing in this task maps a validated
   `CandidateChangeSet` onto a `fiscal_parameter_versions`/`tax_rule_versions` `CANDIDATE` row,
   because that mapping (which `topic` string corresponds to which `fiscal_parameters.code`, etc.)
   is a product/business decision this task was not asked to make and risks fabricating a mapping
   that doesn't exist yet.
6. **No new Composer dependencies.** HTML parsing uses PHP's built-in `DOMDocument`/`DOMXPath`
   (`LIBXML_NONET`, matching `Domain/Fx`'s XXE-defence precedent); JSON validation uses built-in
   `json_decode`. `composer.json`/`composer.lock` untouched.
7. **The self-referencing `source_sections.parent_id` foreign key must be added via a separate
   `Schema::table()` call, never inline inside `Schema::create()`.** Empirically found by actually
   running `migrate:fresh` against real Postgres (see migration `301001`'s docblock) — sqlite
   accepted the inline form silently.

## How to verify

Host PHP is 8.3 and cannot run this dependency tree. Used a throwaway `php:8.5-cli-trixie`
container against the real, already-vendored `apps/api/vendor/`, plus a real `postgres:17-alpine`
container on a shared Docker network (both torn down after verification).

```text
$ vendor/bin/pint --test packages/legislation/src tests/Unit/Legislation \
    tests/Feature/Legislation database/migrations \
    database/factories/{SourceSection,FiscalParameter,FiscalParameterVersion,TaxRule,TaxRuleVersion,TaxRuleSource}Factory.php
  PASS .......................................................... 111 files

$ php -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G packages/legislation/src \
    tests/Unit/Legislation tests/Feature/Legislation \
    database/factories/{SourceSection,FiscalParameter,FiscalParameterVersion,TaxRule,TaxRuleVersion,TaxRuleSource}Factory.php
 [OK] No errors                                                                 (level 9, no baseline)

$ vendor/bin/deptrac analyse --config-file=deptrac.php
  Violations 0, Skipped violations 0, Uncovered 290, Allowed 85, Warnings 0, Errors 0

# Real Postgres, run 1:
$ DB_CONNECTION=pgsql DB_HOST=<pg> DB_DATABASE=kern_test DB_USERNAME=kern DB_PASSWORD=kern \
    vendor/bin/pest tests/Unit tests/Feature/Audit tests/Feature/TaxRomania/Fx \
    tests/Feature/Legislation tests/Feature/Platform
  Tests: 7 skipped, 280 passed (628 assertions)

# Real Postgres, run 2 (leak check) — identical:
  Tests: 7 skipped, 280 passed (628 assertions)

# The live legislatie.just.ro fetch, opted in explicitly, on Postgres:
$ LEGISLATIE_LIVE_TEST=1 vendor/bin/pest tests/Feature/Legislation/LegislatieJustRoLiveFetchTest.php
  Tests: 1 passed (5 assertions)   [genuinely fetched https://legislatie.just.ro/Public/DetaliiDocumentAfis/47355]
```

**Migration reversibility, on real Postgres** — `migrate:fresh` → `migrate:rollback --step=11`
(all seven of this task's migrations plus four concurrently-landed Identity ones) → `migrate`
again: all three steps completed with `DONE` on every line, no errors, including the
self-referencing `source_sections.parent_id` FK drop and the `foreignUuid`
`dropConstrainedForeignId('supersedes_version_id')` in `301000`'s `down()`.

`composer validate`/`composer audit` were not run — no Composer binary in this throwaway image
(same situation as `docs/handoff/012-encrypted-object-store.md`/`014-source-registry-bnr.md`), and
no dependency changed so there is nothing new to validate/audit.

**A pre-existing, unrelated defect found during verification, not fixed (outside this task's
scope: `apps/api/packages/platform/src/Identity/**`)**: `tests/Feature/TaxRomania/Subjects` fatals with
`Kern\Platform\Identity\Models\User and Illuminate\Auth\Authenticatable define the same property
($authPasswordName)... considered incompatible` on this PHP 8.5.10 image. Reproduced by running
`tests/Feature/TaxRomania/Subjects` in complete isolation — zero `TaxKnowledge` code involved — so this
predates and is unrelated to this task. Likely a PHP 8.5 point-release stricter-trait-property-
compatibility check hitting `Kern\Platform\Identity\Models\User`'s composition of the
`Authenticatable` trait. Not recorded in `docs/STATUS.md` per this task's explicit instruction not
to edit that file — flagged here for whoever owns `Domain/Identity` next.

## Interfaces exposed to other modules

```php
use Kern\Legislation\Actions\IngestAndParseLegislationDocument;
use Kern\Legislation\Services\LegislationTemporalQuery;
use Kern\Legislation\Chunking\SourceSectionChunkAssembler;
use Kern\Legislation\CandidateExtraction\{CandidateChangeSetParser, CandidateCitationVerifier};

// Fetch + parse in one call (never sets an effective date):
$result = app(IngestAndParseLegislationDocument::class)('LEGISLATIE', $url, 'LEGE');
// $result->sectionCount, ->status (PARSED|REQUIRES_REVIEW), ->publicationDate

// The temporal question every later milestone needs:
$version = app(LegislationTemporalQuery::class)->versionInForceOn($sourceDocumentId, $date);
$sections = app(LegislationTemporalQuery::class)->sectionsInForceOn($sourceDocumentId, $date, SectionType::ARTICOL);
// throws NoEffectiveVersionFoundException | AmbiguousEffectiveVersionException — never guesses

// Chunking for a future retrieval index (M11):
$chunks = app(SourceSectionChunkAssembler::class)->chunksFor($sectionId);

// AI candidate contract (no LLM call — apps/processing supplies the JSON string):
$set = CandidateChangeSetParser::fromJson($json); // throws InvalidCandidateChangeSetException
app(CandidateCitationVerifier::class)->verify($set); // throws UnknownSourceReferenceException
```

`fiscal_parameters`/`fiscal_parameter_versions`/`tax_rules`/`tax_rule_versions`/`tax_rule_sources`
Eloquent models exist for Milestone 4/5 to build on; nothing in this task writes to them except
tests.

## What is NOT done

- **No effective date is ever set automatically for a `legislatie.just.ro` document.** Every
  ingested version needs a human to supply `effective_from`/`effective_until` (via
  `ParseLegislationDocumentVersion`'s optional parameters, or a future review UI) before
  `LegislationTemporalQuery` can ever return it. This is Milestone 3's single largest open item and
  is a deliberate consequence of "never guess a date," not an oversight.
- **No review UI.** Explicitly out of scope per this task's brief.
- **No RAG/vector index.** `SourceSectionChunker`/`SourceSectionChunkAssembler` produce chunks;
  nothing embeds or indexes them (Milestone 11).
- **No mapping from a validated `CandidateChangeSet` to a `fiscal_parameter_versions`/
  `tax_rule_versions` `CANDIDATE` row.** The contract validates; nothing persists (see "Key
  decisions" #5).
- **No LLM provider wiring.** ADR 0007, provider undecided, `apps/processing`'s job.
- **`Titlu`/`Capitol`/`Secțiune` are not modelled as their own `source_sections` rows**, even
  though the real markup has them — out of this task's explicit `SectionType` scope.
- **PDF-based sources (ANAF instructions) are not parsed.** `page_start`/`page_end` exist on
  `source_sections` for that future work; nothing populates them yet.
- **No scheduling.** Nothing calls `IngestAndParseLegislationDocument` on a cron; this task builds
  the capability, not the job.
- Did not touch `docs/STATUS.md` (explicit instruction for this task) or any file under
  `packages/tax-romania/src/{Identity,Subjects,Audit}/**`, `apps/processing/**`, or the review-UI/RAG-index
  layers.

## Open questions

- **Should the AI candidate-extraction contract carry the quoted source text alongside
  `quote_hash`, not just the hash?** `BLUEPRINT.md` §9.2's own JSON example never includes it, so
  `CandidateCitationVerifier` can confirm a `section_id` exists but can never verify a `quote_hash`
  actually matches a real substring of that section's text — there is nothing to hash and compare.
  Not decided here; would need a blueprint change or an explicit product decision once a real
  provider exists.
- **How does a genuine human-approval workflow eventually flip `status` to `APPROVED`?**
  `ForbidsAgentApproval` currently makes it unreachable from *any* application code path, on
  purpose, because no such workflow exists yet. Whoever builds it must decide: a narrowly-scoped,
  audited service excluded from this guard, a privileged raw query outside Eloquent, or something
  else — not decided here.
- **Is there any better effective-date signal on `legislatie.just.ro` this task didn't find** — a
  different endpoint, a `?d=YYYY-MM-DD` "forma la data" query parameter, an API separate from the
  rendered HTML page? The "Formă consolidată" radio control observed in the page (see "Sources
  consulted") strongly suggests such a parameter exists, but guessing its name/shape would violate
  `docs/AGENTS.md` §1.1 ("never invent an endpoint") — needs a human to observe the real portal
  interaction (e.g. via the project's own Playwright worker, the way `docs/reference/bnr-fx-endpoints.md`'s
  `curs.bnr.ro` replacement was found) before this parser can ever set a real `effective_from`.

## Sources consulted

- `BLUEPRINT.md` §6.8 (schemas, in full), §8 (source hierarchy), §9 (ingestion pipeline, in full,
  including §9.1 "never overwrite" and §9.2's exact JSON schema), §15.3 (chunk metadata).
- `docs/AGENTS.md` — read in full; §1 rules 2/3/17 ("never invent... a date", "never introduce a
  rate without a source_section_id", "never overwrite a raw official source") and §9 ("no agent
  may set APPROVED") governed most of this task's design decisions directly.
- `docs/CODE-QUALITY.md`, `docs/DEPENDENCY-POLICY.md` — read in full before writing code.
- `docs/handoff/014-source-registry-bnr.md` — read in full; the fetcher/storage/ingestion service
  this task reuses, and the precedent for "how much of `BLUEPRINT.md`'s literal schema to defer, with
  a docblock explaining why."
- `docs/handoff/012-encrypted-object-store.md` — read for the "raw official documents are public,
  not PII, and don't go through the subject-scoped encrypted store" precedent, reused unchanged.
- `docs/handoff/016-postgres-suite-stabilisation.md` — read in full before writing a single
  migration; its standing lesson ("run the suite against Postgres before claiming done") is exactly
  what caught this task's own self-referencing-FK defect (see "Key decisions" #7).
- `docs/reference/anaf-spv-integration.md` — the `legislatie.just.ro` 403-without-UA /
  200-with-UA finding, confirmed independently in this task (`curl` with and without a browser
  User-Agent, 2026-09-08) before writing any ingestion code.
- **Two real, live fetches of `https://legislatie.just.ro`**, made explicitly to ground the parser
  in genuine structure rather than guessing (permitted by this task's brief):
  `Public/DetaliiDocument/120725` (a Decree, incidentally — a document id was not searched for
  deliberately, it happened to resolve to a real but unrelated document, which is itself the
  reason a second, deliberately-searched URL was used) and
  `Public/DetaliiDocumentAfis/47355` (Constituția României, referenced from within the first
  document's own text, not guessed) — retrieved 2026-09-08. Both retrieved for
  structural/markup inspection only; no legal text from either is asserted as real content
  anywhere in this codebase's tests (only structural properties are asserted).
- Laravel 13 framework source read directly for the Postgres self-referencing-FK defect: observed
  behaviourally (via a real `migrate:fresh` failure and fix), not assumed from documentation.
