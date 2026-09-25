# 014 — Source registry + BNR FX importer

**Milestone:** M2   **Status:** partial (engine complete and tested; live BNR import blocked)
**Date:** 2026-09-08

## What was built

The official source registry (`BLUEPRINT.md` §8.2) and the BNR exchange-rate importer (§7), as two
new bounded contexts: `Kern\Legislation` (source registry, allowlisted fetching, immutable
raw storage, document/version bookkeeping) and `App\Domain\Fx` (FX sources, rates, anomalies,
period averages, the BNR-specific pipeline).

**The hard blocker, unchanged from before this task:** every documented BNR XML URL still
302-redirects to the homepage (`docs/reference/bnr-fx-endpoints.md`). No endpoint was invented.
`BNR_DAILY_XML_URL` / `BNR_YEARLY_XML_URL_TEMPLATE` are both `null` by default in
`config/sources.php` — the importer throws `App\Domain\Fx\Exceptions\BnrEndpointNotConfiguredException`
rather than guessing. **Everything else asked for — registry, fetcher, storage, parser,
idempotency, anomaly detection, monthly/annual averaging — is built and tested against fixtures.**

### Entry points

- `Kern\Legislation\Services\SourceIngestionService::ingest($sourceCode, $url, $documentType, $externalIdentifier = null, $expectXml = false): IngestedSourceDocument`
  — fetch through the allowlist, sanity-check, store raw bytes immutably, record a
  `source_documents`/`source_document_versions` row (only if content actually changed). The single
  entry point other modules (and `Domain/Fx`) use — never touch
  `Kern\Legislation\Models\*` directly from outside this module.
- `App\Domain\Fx\Actions\ImportBnrDailyRates` (`__invoke(): FxImportSummary`) and
  `App\Domain\Fx\Actions\BackfillBnrYear` (`__invoke(int $year): FxImportSummary`) — the two named
  jobs from `BLUEPRINT.md` §7.2/§7.3. Thin `ShouldQueue` wrappers exist at
  `App\Domain\Fx\Jobs\FetchBnrDailyRates` / `BackfillBnrYear` for naming parity, but nothing
  schedules them yet (see "What is NOT done").
- `App\Domain\Fx\Services\FxRateQuery::rate($currencyCode, DateTimeImmutable $date, $sourceCode = 'BNR'): DecimalRate`
  — **this is how another module asks for a rate on a date.** Exact match only, no
  interpolation/fallback; throws `FxRateNotFoundException` on a miss. `rateWithProvenance(...)`
  returns the same rate plus the raw document's hash, storage path, and fetch time
  (`FxRateWithProvenance` DTO) — BLUEPRINT.md §26 DoD F / §39.
- `App\Domain\Fx\Services\FxPeriodAverageCalculator::calculateMonthly/calculateAnnual/recordOfficialPublishedValue`.

## Files created / changed

**Config**
- `apps/api/config/sources.php` — the versioned, code-reviewed registry (§8.2): per-source
  `trust_tier`, `base_url`, `allowed_hosts`, `user_agent`, `enabled`; BNR's two endpoints
  (both `null` by default); fetch limits; storage disk/prefix; FX decimal scale and verification
  tolerance. This file, not the `official_sources` table, is authoritative — see that migration's
  docblock.

**`Kern\Legislation`** (`apps/api/packages/legislation/src/`)
- `Enums/TrustTier.php` — A/B/C/D (§8.1).
- `DataTransferObjects/SourceDefinition.php`, `IngestedSourceDocument.php`,
  `SourceDocumentVersionDetails.php` — the module's cross-boundary DTOs (never an Eloquent model
  crosses out of this module).
- `Config/OfficialSourceRegistry.php` — reads/validates `config/sources.php`, pure (no DB/HTTP).
- `Fetching/AllowlistedHttpFetcher.php` — the one place this app makes an outbound request to an
  official source. Checks the host allowlist before the initial request *and* before following
  each redirect hop (never follows a redirect off the allowlist); enforces max redirects, a
  declared-`Content-Length` check and an actual-byte-count check, and sends the source's
  configured `User-Agent`.
- `Fetching/Exceptions/{HostNotAllowedException,TooManyRedirectsException,FetchSizeExceededException,UnexpectedContentTypeException}.php`.
- `Storage/RawSourceObjectStore.php` — immutable, **content-addressed** raw storage
  (`{prefix}/{source_code}/{sha256}`), on the same Flysystem disk `App\Support\ObjectStore` uses,
  but *not* that module (see "Key decisions" #1 for why).
- `Storage/Exceptions/RawObjectNotFoundException.php`.
- `Models/{OfficialSource,SourceDocument,SourceDocumentVersion}.php` — BLUEPRINT.md §6.8, minus the
  legislation-parsing-pipeline columns Milestone 3 doesn't exist yet to populate (see each
  migration's docblock).
- `Repositories/OfficialSourceRepository.php` (config→DB mirror, plus the scalar-id read surface
  `Domain/Fx` uses) and `Repositories/SourceDocumentVersionRepository.php` (provenance lookup).
- `Services/SourceIngestionService.php` — the pipeline described above.
- `Exceptions/{UnknownSourceException,SourceDisabledException,UnknownSourceDocumentVersionException}.php`.
- `TaxKnowledgeServiceProvider.php` — binds `AllowlistedHttpFetcher` (see "Key decisions" #2 for
  why `bind()`, not `singleton()`) and `RawSourceObjectStore`.

**`App\Domain\Fx`** (`apps/api/app/Domain/Fx/`)
- `Enums/{PeriodType,VerificationStatus,FxRateImportOutcome}.php`.
- `ValueObjects/DecimalRate.php` — the money-precision decision (see "Key decisions" #3): a
  validated, non-negative, BCMath-only numeric string. No float touches this module anywhere.
- `Exceptions/InvalidDecimalRateException.php`.
- `DataTransferObjects/{ParsedFxRate,ParsedFxDay,FxImportSummary,FxRateWithProvenance}.php`.
- `Parsing/BnrXmlParser.php` — namespace-agnostic (`local-name()` XPath), validates currency code
  shape, multiplier (positive integer, defaults to 1), rate value shape, rejects duplicate
  dates/currencies. **Schema is unverified — see "What is fixture-verified only" below.**
- `Parsing/Exceptions/InvalidBnrXmlException.php`.
- `Models/{FxSource,FxRate,FxRateAnomaly,FxPeriodAverage}.php`.
- `Repositories/FxSourceRepository.php` — find-or-create an `fx_sources` row, linking it to
  `TaxKnowledge`'s `official_sources` via the scalar-id surface only.
- `Services/FxRateImporter.php` — the idempotent, never-overwriting upsert (§7.2 steps 7-9).
- `Services/FxPeriodAverageCalculator.php` — monthly/annual averages (§7.4).
- `Services/FxRateQuery.php` — the public read interface (see above).
- `Actions/{ImportBnrDailyRates,BackfillBnrYear}.php`, `Jobs/{FetchBnrDailyRates,BackfillBnrYear}.php`.
- `Exceptions/{BnrEndpointNotConfiguredException,FxRateNotFoundException,NoDailyRatesForPeriodException,IncompleteMonthlyAveragesException}.php`.
- `FxServiceProvider.php` — binds `FxPeriodAverageCalculator` (reads `sources.fx.*`).

**Migrations** (`apps/api/database/migrations/2026_09_08_300000` through `300006`)
- `official_sources`, `source_documents`, `source_document_versions` (§6.8).
- `fx_sources`, `fx_rates`, `fx_period_averages` (§6.9), plus **`fx_rate_anomalies`** — not named
  in BLUEPRINT.md's literal column list, but required by its own §7.2 step 9 narrative and by this
  task's explicit, tested requirement; see that migration's docblock for the justification.
- **Every rate/average column is `string`, not `decimal(20,6)`/`NUMERIC`** — see "Key decisions"
  #4, an empirically-verified finding, not a style preference.

**Factories** — `OfficialSourceFactory`, `SourceDocumentFactory`, `SourceDocumentVersionFactory`,
`FxSourceFactory` (`apps/api/database/factories/`).

**Tests**
- `tests/Unit/Domain/Fx/{DecimalRateTest,BnrXmlParserTest}.php` — pure, no DB/HTTP.
- `tests/Unit/Domain/TaxKnowledge/OfficialSourceRegistryTest.php` — pure.
- `tests/Feature/Domain/TaxKnowledge/{AllowlistedHttpFetcherTest,SourceIngestionServiceTest}.php`.
- `tests/Feature/Domain/Fx/{FxRateImporterTest,FxPeriodAverageCalculatorTest,FxRateQueryTest,ImportBnrDailyRatesActionTest,BackfillBnrYearActionTest}.php`.
- `tests/Fixtures/Fx/Bnr/*.xml` — five fixtures, every one headed
  `SYNTHETIC FIXTURE — NOT CAPTURED FROM BNR` in an XML comment (see "What is fixture-verified
  only").

**Wiring outside the strict path list** (small, additive, same justification pattern as handoffs
005/012):
- `apps/api/bootstrap/providers.php` — registered `TaxKnowledgeServiceProvider`, `FxServiceProvider`.
- `apps/api/deptrac.php` — added `TaxKnowledge` and `Fx` layers; `Fx` is allowed to access
  `TaxKnowledge` (application-service/DTO shape only, never a model import).

## Key decisions

1. **Raw source documents do NOT go through `App\Support\ObjectStore\EncryptedObjectStore`.**
   That module's `ObjectKey::forDocument()` is constructed only from a `tax_subject_id`, a
   `document_id`, and a closed `DocumentObjectKind` enum, and `put()`/`get()` unwrap a
   *per-subject* DEK for every operation. An official source document (BNR's daily feed, an ANAF
   PDF) belongs to no `TaxSubject` — it is public, not PII. Forcing it through that API would mean
   either inventing a fake subject id to satisfy a type contract built narrower than that on
   purpose, or changing `app/Support/ObjectStore/**`, which is outside this task's scope. Built
   `Kern\Legislation\Storage\RawSourceObjectStore` instead: unencrypted (deliberately — the
   content is public), content-addressed (`sources/{code}/{sha256}`) on the *same* Flysystem disk,
   so "immutable, never overwritten" is a property of the key scheme, not a separately-enforced
   rule. Flagged as an open question below for whoever owns `Support/ObjectStore` about whether to
   unify later with a "system" (non-subject) encryption path.

2. **`AllowlistedHttpFetcher` is bound `bind()`, not `singleton()`.** Found empirically, not
   guessed: a singleton captures `Illuminate\Http\Client\Factory` once, and `Http::fake()` re-binds
   that class in the container (`Facade::swap()`) — a singleton constructed before a *second*
   `Http::fake()` call in the same test kept talking to the first fake and silently missed the
   second. This surfaced while writing the anomaly-detection tests (two fetches of different
   content in one test). `bind()` costs nothing (the constructor does no expensive work) and is
   simply more correct generally, not just under test.

3. **A related but distinct finding: `Http::fake()` called twice for the *same* URL does not
   replace the first stub — Laravel merges (`Collection::merge`) stub registrations, and resolution
   takes the *first* matching one** (`PendingRequest::buildStubHandler()`:
   `->map->__invoke(...)->filter()->first()`). Every test simulating "day 1 fetch, then day 2 with
   different content" therefore uses **one** `Http::fake()` call with `Http::sequence()->push(...)->push(...)`
   for that URL, never two separate `Http::fake()` calls. Documented inline in the three tests that
   need it (`SourceIngestionServiceTest`, `ImportBnrDailyRatesActionTest`, `BackfillBnrYearActionTest`)
   so a future agent doesn't rediscover this the hard way.

4. **Every rate/average column is `string`, not `decimal(20,6)`/`NUMERIC` — empirically verified,
   not a style choice.** A throwaway PDO script: `INSERT INTO t(v) VALUES ("123456789012.123456")`
   into a `NUMERIC` column, read back via `pdo_sqlite`, produced `float(123456789012.12346)` —
   already wrong at the 12th significant digit. The same value in a `TEXT` column round-trips
   byte-for-byte on both `pdo_sqlite` and `pdo_pgsql`. Laravel's own SQLite grammar maps
   `decimal()` to `numeric` (`SQLiteGrammar::typeDecimal()`), so this is exactly what
   `$table->decimal('rate_decimal', 20, 6)` would have produced — not a hypothetical edge case.
   Since this project's test connection is SQLite (`phpunit.xml` `DB_CONNECTION=sqlite`), a
   `NUMERIC` column would have made every rate silently float-lossy **in the very tests meant to
   prove "no float for money"**. `string(32)` behaves identically — exact, no float, ever — on
   every engine. No Eloquent cast is applied to these columns; every reader goes through
   `App\Domain\Fx\ValueObjects\DecimalRate::fromString()`, which validates format at read time.

5. **`FxPeriodAverageCalculator::upsertCalculated()` unconditionally calls `$model->refresh()`
   before returning.** `updateOrCreate()`'s create path leaves `verification_status` as PHP `null`
   in the in-memory model even though the DB row already has `'PENDING'` (its column DEFAULT) —
   Eloquent never syncs a DB-level default back after `INSERT` unless you ask. Found by a failing
   test (`expect($average->verification_status)->toBe(VerificationStatus::PENDING)` — actual was
   `null`), not assumed. `refresh()` guarantees the returned model always exactly matches the
   persisted row on both the create and update path.

6. **`fx_rates.rate_decimal` stores the value exactly as BNR published it — for `multiplier`
   units, never pre-divided.** This is the explicit defence against the multiplier trap
   (`multiplier` and `rate_decimal` are always read together, divided fresh on every read by
   `FxRateQuery`/`FxPeriodAverageCalculator`, never from a cached per-unit quotient).

7. **Idempotency at the document layer is structural, not a separate check**: content-addressed
   storage (decision #4) plus a unique index on `(source_document_id, content_sha256)` means
   re-ingesting byte-identical content resolves to the same path and the same existing row by
   construction — `SourceIngestionService::ingest()` doesn't need a "did anything change" branch
   beyond "does a row with this hash already exist."

8. **`SourceIngestionService::ingest()`'s `$expectXml` parameter defaults to `false` and performs
   no content-shape assertion at all when omitted**, deliberately, so this same service stays
   usable by Milestone 3's legislation ingestion (HTML/PDF) without this milestone hardcoding an
   XML-only check that would reject every one of that milestone's fetches. `Domain/Fx` always
   passes `expectXml: true`.

9. **`fx_rate_anomalies` has no `resolved_at`/resolution workflow.** Nothing in this milestone sets
   one — building a resolution UI/workflow wasn't asked for, and an unused column would be
   speculative scaffolding. A future agent adds it in a new migration when it exists to populate.

10. **No new Composer dependencies.** XML parsing uses PHP's built-in `DOMDocument`/`DOMXPath`
    (`LIBXML_NONET` — no external entity/network resolution, defence in depth against XXE even
    though PHP 8's libxml defaults already disable it); decimal math uses `ext-bcmath` (already
    required). `composer.json`/`composer.lock` untouched.

## How to verify

Host PHP is 8.3 and cannot run this dependency tree (Pest needs PHP ^8.4). Used the same approach
as prior handoffs: a throwaway `php:8.5-cli-trixie` container (`apt-get install -y libicu-dev
libpq-dev libzip-dev`, `docker-php-ext-install intl pdo_pgsql pcntl bcmath`) against the real,
already-vendored `apps/api/vendor/`. All commands below were actually run; output is real.

```text
$ vendor/bin/pint --test packages/legislation/src app/Domain/Fx tests/Unit/Domain/Fx \
    tests/Unit/Domain/TaxKnowledge tests/Feature/Domain/TaxKnowledge tests/Feature/Domain/Fx \
    database/migrations database/factories/{OfficialSource,SourceDocument,SourceDocumentVersion,FxSource}Factory.php \
    config/sources.php bootstrap/providers.php deptrac.php
  PASS .......................................................... 86 files

$ php -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G packages/legislation/src \
    app/Domain/Fx tests/Unit/Domain/Fx tests/Unit/Domain/TaxKnowledge \
    tests/Feature/Domain/TaxKnowledge tests/Feature/Domain/Fx \
    database/factories/{OfficialSource,SourceDocument,SourceDocumentVersion,FxSource}Factory.php \
    config/sources.php bootstrap/providers.php
 [OK] No errors                                                                 (level 9, no baseline)

$ vendor/bin/pest tests/Unit/Domain/Fx tests/Unit/Domain/TaxKnowledge \
    tests/Feature/Domain/TaxKnowledge tests/Feature/Domain/Fx
  Tests:    76 passed (180 assertions)

$ vendor/bin/deptrac analyse --config-file=deptrac.php
  Violations 0, Skipped violations 0, Uncovered 145, Allowed 46, Warnings 0, Errors 0
```

**Whole-suite run** (`vendor/bin/pest`, real `APP_KEY`): **228 passed, 20 skipped, 0 failed.** The
20 skipped are all pre-existing live-integration tests requiring a real Postgres/OpenBao/MinIO
(`SubjectMembershipUniquenessTest`, `OpenBaoLiveIntegrationTest`, `EncryptedObjectStoreLiveMinioTest`)
— unrelated to this task, unaffected by it.

`composer validate`/`composer audit` were not run — no Composer binary in this throwaway image
(same situation as `docs/handoff/012-encrypted-object-store.md`), and no dependency changed
(decision #10) so there is nothing new to validate/audit.

## What is fixture-verified only — re-verify before pointing at a real BNR URL

- **The entire `DataSet/Body/Cube/Rate` XML shape, the `multiplier` attribute, and any namespace**
  are unverified. `BnrXmlParser`'s own docblock states this plainly. Every fixture in
  `tests/Fixtures/Fx/Bnr/` is headed `SYNTHETIC FIXTURE — NOT CAPTURED FROM BNR` and was built from
  `BLUEPRINT.md` §7's narrative description plus the widely publicly documented historical shape of
  BNR's feed — not a captured response. The parser is deliberately namespace-agnostic
  (`local-name()` XPath) specifically because the namespace is one of the unverified details.
  **Before ever running this against a live URL: capture one real response, diff its actual
  element/attribute names against what this parser expects, and update both the parser and these
  fixtures if they differ.**
- **Whether BNR ever publishes a non-integer multiplier, a currency code shorter/longer than 3
  letters, or more than one `<Rate>` per currency per day** — all unverified assumptions the parser
  currently rejects as errors. If real data violates any of these, that is new information to
  react to, not evidence the parser is wrong.
- **The daily-feed-is-exactly-one-`<Cube>` assumption** in `ImportBnrDailyRates` (it throws if a
  fetch parses to anything other than exactly one day) — reasonable for "today's rates," unverified
  against a real response.

## Interfaces exposed to other modules

```php
use App\Domain\Fx\Services\FxRateQuery;

$rate = app(FxRateQuery::class)->rate('EUR', new DateTimeImmutable('2026-01-15')); // DecimalRate, BNR by default
$withProvenance = app(FxRateQuery::class)->rateWithProvenance('EUR', new DateTimeImmutable('2026-01-15'));
// $withProvenance->contentSha256, ->rawObjectPath, ->retrievedAt, ->sourceDocumentVersionId
```

- `FxRateQuery::rate()`/`rateWithProvenance()` throw `App\Domain\Fx\Exceptions\FxRateNotFoundException`
  on an exact miss — never interpolates or falls back to an adjacent day.
- `Kern\Legislation\Services\SourceIngestionService::ingest()` is the only sanctioned way
  any future module (Milestone 3's legislation ingestion) fetches+stores an official source
  document — never construct `RawSourceObjectStore`/`AllowlistedHttpFetcher` directly from outside
  `Domain/TaxKnowledge`.

## What is NOT done

- **Live BNR import** — blocked on a confirmed URL (`docs/reference/bnr-fx-endpoints.md`). Nothing
  else in this milestone is blocked by this.
- **Scheduling** — `App\Domain\Fx\Jobs\{FetchBnrDailyRates,BackfillBnrYear}` exist as thin
  `ShouldQueue` wrappers but nothing calls `Schedule::job(...)` for them in `routes/console.php`.
  Not done because there is no live endpoint to schedule against yet; wiring a schedule for a job
  that would immediately throw `BnrEndpointNotConfiguredException` on every run seemed like the
  wrong kind of "done."
- **BNR monthly bulletin ingestion** — `recordOfficialPublishedValue()` exists and is tested, but
  nothing yet fetches BNR's own published monthly/annual averages to feed it. Every
  `fx_period_averages` row this milestone can produce end-to-end sits at `verification_status =
  PENDING` until that exists.
- **Milestone 3's `source_document_versions` columns** (`parser_version`, `supersedes_version_id`,
  processing `status`) — not added; see that migration's docblock.
- **A ruleset-visible FX conversion rule** (which method — spot/monthly/annual — a given tax rule
  should use, per `BLUEPRINT.md` §6.9's closing note) — `FxRateQuery` provides the primitives; no
  `TaxRule` consumes them yet (Milestone 5).
- Did not touch `docs/STATUS.md` (explicit instruction for this task) or any file under
  `app/Domain/{Identity,Subjects,Audit}/**` or `app/Support/**`.

## Open questions

- Should `App\Support\ObjectStore` grow a "system"/non-subject encryption path so public source
  documents could be stored uniformly with subject documents (encrypted at rest for
  defense-in-depth even though not required for confidentiality)? Not decided here — that module's
  contract is out of this task's scope; `RawSourceObjectStore` is the narrower alternative built
  instead (decision #1).
- Whether `fx_rates`/`fx_period_averages`/`fx_rate_anomalies` should carry a real DB `CHECK`
  constraint validating the `string` rate columns' numeric-decimal shape, for defence in depth
  beyond `DecimalRate::fromString()`'s application-level validation — skipped for portability
  (SQLite's `CHECK` constraint support via Laravel's schema builder is inconsistent) and because
  every write path in this codebase goes through `DecimalRate` already; worth reconsidering once a
  bulk-import path that bypasses application code exists (it doesn't yet).

## Sources consulted

- `BLUEPRINT.md` §7 (BNR, in full), §7.1–§7.5, §8 (source hierarchy, §8.1/§8.2), §6.8/§6.9 (schemas),
  §26 (Milestone 2 DoD), §39 (reproducibility principle).
- `docs/AGENTS.md` §1.1/§2 (never invent an endpoint), §6 (module boundaries), §8 (security rules).
- `docs/reference/bnr-fx-endpoints.md` — read in full before writing any code; the single most
  important constraint on this task. Re-read again after the first draft to confirm nothing here
  contradicts it.
- `docs/reference/anaf-spv-integration.md` — the `legislatie.just.ro` User-Agent finding, applied
  to that source's `config/sources.php` entry.
- `docs/handoff/012-encrypted-object-store.md` — read in full; reused its AAD/manifest concepts
  conceptually but not its code (decision #1); reused its "bind, not singleton, for a
  container-swappable dependency" precedent (decision #2, independently re-derived and then
  recognized as the same shape).
- `docs/handoff/006-audit-chain.md`, `docs/handoff/005-crypto-core.md` — read for module-boundary
  and value-object precedent (`HasUuidPrimaryKey` reuse); no audit events were added in this task
  (no fiscal/security-sensitive action exists yet in this module that §4.8 requires auditing —
  fetching a public XML feed is not a security event in the sense §4.8/Audit's own scope covers).
- Laravel 13 framework source read directly, not assumed: `Illuminate\Http\Client\Factory::fake()`/
  `PendingRequest::buildStubHandler()` (decision #3's merge-not-replace behaviour),
  `Illuminate\Database\Schema\Grammars\SQLiteGrammar::typeDecimal()` (decision #4).
- A throwaway PDO script against `sqlite::memory:` — the empirical basis for decision #4, not
  assumed from documentation.
