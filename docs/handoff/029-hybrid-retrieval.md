# 029 — Hybrid retrieval (Milestone 11's retrieval layer)

**Milestone:** M11   **Status:** complete (retrieval + citation only — answer generation deliberately out of scope)
**Date:** 2026-09-08

## What was built

The retrieval half of Milestone 11 — hybrid lexical + semantic + temporal/tier search over the
`source_sections` chunks Milestone 3 already produces (`docs/handoff/018-legislation-ingestion.md`),
per `BLUEPRINT.md` §15.2–§15.4 and `docs/adr/0006-pgvector-over-qdrant.md`. Extends
`Kern\Legislation` — retrieval belongs with the corpus, not a new module.

Five pieces:

1. **Romanian lexical search** — diacritic-insensitive (both comma-below `ș`/`ț` and legacy
   cedilla `ş`/`ţ` encodings) full-text search using the built-in `romanian` text-search
   configuration, plus `pg_trgm` fuzzy matching for citation labels ("art. 170 alin. 2" → "Art.
   170 alin. (2)").
2. **An indexing path that persists `SourceSectionChunker`'s DTOs** (M3 left chunking DTO-only
   with "no consumer yet" — this task is that consumer) into a new `source_section_chunks` table,
   with a **pgvector column and HNSW index** for semantic similarity.
3. **The embedding call as a seam, not a real integration** — no AI provider is configured
   (`docs/STATUS.md` blocked item #3), so nothing in this task calls an external service. A
   deterministic fake backs the tests.
4. **Hybrid retrieval in one SQL statement** — lexical (`tsvector`/`ts_rank_cd`), fuzzy
   (`pg_trgm`/`similarity`), semantic (`pgvector`/`<=>`), and temporal/tier filtering, all in a
   single query against the same rows — the entire point of `ADR 0006`'s pgvector-over-Qdrant
   decision.
5. **Citation enforcement and the unknown-date rule** — every result carries a complete citation;
   a result whose effective dates are genuinely unknown is never silently included or excluded.

**Not built, deliberately**: no LLM call, no chat/answer-generation endpoint, no prompt template.
`BLUEPRINT.md` §15.5 — this layer retrieves and cites; it computes nothing that could be mistaken for
a fiscal figure. That is a separate, later task once an AI provider is chosen (`docs/STATUS.md`
blocked item #3, ADR 0007).

## Files created / changed

**Migrations** (`apps/api/database/migrations/2026_09_08_301100`–`301102`)
- `301100_create_kern_unaccent_function.php` — `CREATE EXTENSION IF NOT EXISTS vector/unaccent/
  pg_trgm` (see "A latent CI gap this task fixed" below) and `kern_unaccent(text) RETURNS text`,
  an `IMMUTABLE` wrapper around contrib `unaccent()` (which is `STABLE`, confirmed by querying
  `pg_proc.provolatile` directly — not assumed). Read this migration's docblock in full: getting
  this one function right took three failed attempts against real Postgres, each with a different,
  non-obvious error (`function unaccent(unknown, text) does not exist` when SQL-inlined into an
  index expression; `text search dictionary "unaccent" does not exist` and then `function
  unaccent(regdictionary, text) does not exist` once the dictionary argument was schema-qualified
  but the *function itself* wasn't, because `CREATE INDEX`'s immutability check runs the
  expression under a restricted search path). The working form is `LANGUAGE plpgsql` (never
  inlined) calling `public.unaccent('public.unaccent'::regdictionary, input)` (both the function
  and its dictionary argument schema-qualified).
- `301101_create_source_section_chunks_table.php` — `source_section_chunks`: portable columns via
  `Schema::create()`, then `embedding vector(64)` and a generated `tsvector` column
  (`GENERATED ALWAYS AS (to_tsvector('romanian', kern_unaccent(text))) STORED`) added via raw SQL,
  guarded to `pgsql` — the same shape `2026_09_08_100001_create_audit_events_table.php`'s trigger
  already established for "Postgres-only enhancement, portable base table." Indexes: GIN on the
  tsvector, HNSW (`vector_cosine_ops`) on the embedding.
- `301102_add_citation_trigram_index_to_source_sections_table.php` — GIN trigram index on
  `kern_unaccent(lower(citation_label))` for fuzzy article lookup.

**`Kern\Legislation\Retrieval\Embedding`** (the seam, mirroring
`Kern\Documents\ExternalAi` exactly)
- `EmbeddingProvider.php` — interface: `embed(list<string> $texts): list<EmbeddingVector>`,
  `modelIdentifier(): string`.
- `EmbeddingVector.php` — value object: non-empty `list<float>`, validates every value is finite,
  formats the pgvector text literal (`[v1,v2,...]`) for a raw write.
- `UnconfiguredEmbeddingProvider.php` — the container's default binding; throws
  `EmbeddingProviderNotConfiguredException` on every call. Never calls a network.
- `Exceptions/EmbeddingProviderNotConfiguredException.php`.

**`Kern\Legislation\Retrieval\Models`**
- `IndexedChunk.php` — Eloquent model for `source_section_chunks` (`$table` explicitly set — the
  default `indexed_chunks` inference is wrong for this table name, caught by actually running the
  tests, not assumed). Deliberately named differently from
  `Kern\Legislation\Chunking\SourceSectionChunk` (a pure, transient DTO) to avoid confusing
  the two. `embedding` is not in `$fillable`/casts — nothing in PHP ever reads a vector back as
  floats; all vector arithmetic happens in SQL (§15.5).

**`Kern\Legislation\Retrieval\Indexing`**
- `IndexSourceSectionForRetrieval.php` — one section in, chunk rows out (text/hash/metadata only,
  no embedding). Delete-and-replace on re-index, matching `LegislationSectionPersister`'s own
  precedent. Uses M3's existing `SourceSectionChunkAssembler` unchanged.
- `EmbedIndexedChunks.php` — the one and only caller of `EmbeddingProvider::embed()`. Batches
  chunks with `embedded_at IS NULL` (optionally scoped to one document version), writes the vector
  via the raw query builder (`EmbeddingVector::toPgvectorLiteral()` — Eloquent has no `vector`
  cast). Throws if the provider is unconfigured or violates its own contract (wrong vector count).

**`Kern\Legislation\Retrieval\Query`**
- `TemporalConfidence.php` — enum: `CONFIRMED` | `DATE_UNKNOWN` (see "The unknown-date rule").
- `HybridSearchQuery.php` — input DTO. `$asOf` is **required**, never defaulted to "today" —
  matching `LegislationTemporalQuery::versionInForceOn()`'s own precedent of never assuming a
  date. Optional filters: `sourceCodes`, `trustTiers`, `sectionType`, `limit`.
- `RetrievalResult.php` — the citation itself (§15.4): document version id, section id, source
  code/tier, citation label, page (nullable — HTML sources have none), effective dates (nullable
  — see below), plus the three raw scores and the combined score.
- `HybridSearchResult.php` — `{confirmed: list<RetrievalResult>, dateUnknown: list<RetrievalResult>}`.
  Two separate lists, not one list with a flag — see "The unknown-date rule."
- `HybridLegislationSearch.php` — the whole thing: one SQL statement (`WITH params AS (...), scored
  AS (...) SELECT ... FROM scored WHERE ...`), a query-embedding attempt that degrades to
  lexical/fuzzy-only on `EmbeddingProviderNotConfiguredException`, ranking, and result hydration.

**`Kern\Legislation\TaxKnowledgeServiceProvider.php`** (modified) — binds
`EmbeddingProvider::class` to `UnconfiguredEmbeddingProvider::class` as a singleton.

**Factory**: `database/factories/IndexedChunkFactory.php`.

**Tests**
- Unit (pure): `tests/Unit/Domain/TaxKnowledge/Retrieval/Embedding/EmbeddingVectorTest.php`.
- Feature (real Postgres, per `docs/AGENTS.md` §7b):
  `tests/Feature/Domain/TaxKnowledge/Retrieval/{IndexSourceSectionForRetrievalTest,
  EmbedIndexedChunksTest,HybridLegislationSearchTest}.php`.
- `tests/Fakes/DeterministicHashEmbeddingProvider.php` — the test-only embedding seam
  implementation: a hashed bag-of-words vector (deterministic, never random, never networked).
  Same text always embeds to the same vector; texts sharing words produce measurably higher cosine
  similarity than texts sharing none — a real, if crude, embedding behaviour, not a canned number,
  so the ranking test exercises `HybridLegislationSearch`'s actual SQL arithmetic.

## Key decisions

1. **The embedding dimension (64) is an explicit placeholder, not a modelled decision.** No
   provider is chosen (`docs/STATUS.md` blocked item #3), so there is no real dimension to encode.
   64 is deliberately small — clearly a wiring/testing stand-in, not a guess at a real provider's
   output size (which run 256–3072 in practice). See "What plugging in a real provider requires"
   below for the concrete migration cost of changing it.
2. **`source_section_chunks` is not denormalized.** `document_version_id`, tier, and effective
   dates are read via `JOIN` at query time, never copied onto the chunk row. `BLUEPRINT.md` §15.1
   already commits this index to being a rebuildable derivative, and a human can supply a
   section's real effective dates later (`docs/reference/legislation-effective-dates.md`) without
   this table's rows needing to change. `section_id` cascades on delete so a re-parsed version's
   deleted sections never leave orphan chunks.
3. **Chunking and embedding are two separate actions**, not one. `IndexSourceSectionForRetrieval`
   never touches `EmbeddingProvider` — lexical and fuzzy retrieval work today, with no provider
   configured, for every indexed section. `EmbedIndexedChunks` is the one explicit, separately
   callable step that would call a real provider once one exists. Nothing schedules either on a
   cron (same "build the capability, not the job" precedent M3 set for `IngestAndParseLegislation
   Document`).
4. **The unknown-date rule**: a chunk whose resolved effective range (its own dates, falling back
   to its document version's) is unknown — both are `NULL`, the honest `REQUIRES_REVIEW` state
   `docs/reference/legislation-effective-dates.md` says is the common case — is never silently
   included as "this applies" and never silently dropped as "this doesn't." It is returned in
   `HybridSearchResult::$dateUnknown`, a structurally separate list from `$confirmed`, each entry
   labelled `TemporalConfidence::DATE_UNKNOWN`. A chunk whose *known* dates place it outside the
   requested date is excluded entirely — that is a confirmed non-match, not an unknown one, and
   surfacing it as "unknown" would be its own kind of lie.
   - This is computed once, per row, inside the same SQL statement (a `CASE` over
     `COALESCE(section.effective_from, version.effective_from)`/`..._until` compared to the bound
     `as_of` parameter) — never a second query, never a PHP-side re-check.
5. **Semantic ranking degrades gracefully to lexical/fuzzy-only when no embedding provider is
   configured** — `HybridLegislationSearch::search()` catches
   `EmbeddingProviderNotConfiguredException` from its own query-embedding attempt (a narrowly
   typed catch of one documented exception, not a catch-all — the same shape
   `ProcessScannedDocument` already uses for `ExternalDocumentAiProvider`). This is a different
   axis from the unknown-date rule: it is a known, accepted, visible product limitation ("no
   provider yet"), not a fact about any particular row's data.
6. **`kern_unaccent()` needed no custom Romanian-letter translation table.** Before assuming
   contrib `unaccent`'s dictionary might not handle the legacy cedilla forms (ş U+015F, ţ U+0163)
   the same as the correct comma-below forms (ș U+0219, ț U+021B), this was checked directly
   against the real `pgvector/pgvector:pg18-trixie` image: both already fold to plain `s`/`t`.
   `kern_unaccent()` is a thin `IMMUTABLE` wrapper for index/generated-column compatibility only
   — see migration `301100`'s docblock for the three real failures fixing that wrapper required.
7. **A latent CI gap this task fixes as a side effect**: `.github/workflows/ci.yml`'s `postgres:`
   service starts `pgvector/pgvector:pg18-trixie` directly with no init-script mount, so
   `docker/postgres/init/001-extensions.sql` (which creates `vector`/`unaccent`/`pg_trgm` on the
   dev compose stack) never ran in CI. Nothing needed these extensions before this task, so the
   gap was latent. Migration `301100` now runs `CREATE EXTENSION IF NOT EXISTS` for all three
   itself, idempotently — safe to run again where the init script already created them (dev
   compose), and now also correct where it doesn't (CI, and any ad hoc test database per
   `docs/AGENTS.md` §7b).
8. **Ranking weights and thresholds are named constants on `HybridLegislationSearch`**
   (`WEIGHT_SEMANTIC = 0.5`, `WEIGHT_LEXICAL = 0.3`, `WEIGHT_CITATION = 0.2`,
   `CITATION_SIMILARITY_THRESHOLD`/`SEMANTIC_SIMILARITY_THRESHOLD = 0.3`), not a fiscal parameter —
   §15.5 forbids fiscal calculation here, but ranking weights are a retrieval-quality knob, not a
   tax rule, so they carry no `source_section_id` and are not gated by `docs/AGENTS.md` rule 3.
   They are a starting point, not empirically tuned (no real corpus/provider to tune against yet).
9. **No Composer dependency added.** The vector write is a plain string bound through PDO
   (`EmbeddingVector::toPgvectorLiteral()`) — verified empirically that Postgres resolves an
   untyped bound parameter against the target `vector` column/cast correctly, with no client-side
   pgvector library needed for this one-directional write.

## The exact SQL shape (`HybridLegislationSearch::search()`)

```sql
WITH params AS (
    SELECT ?::text AS query_text, ?::date AS as_of, ?::vector AS query_embedding
),
scored AS (
    SELECT
        c.id AS chunk_id, c.chunk_index, c.chunk_count, c.text,
        s.id AS section_id, s.section_type, s.citation_label, s.page_start, s.page_end,
        COALESCE(s.effective_from, dv.effective_from) AS effective_from,
        COALESCE(s.effective_until, dv.effective_until) AS effective_until,
        dv.id AS document_version_id, sd.id AS source_document_id,
        sd.issuer, sd.document_type, sd.publication_date,
        os.code AS source_code, os.trust_tier,
        ts_rank_cd(c.text_search, plainto_tsquery('romanian', kern_unaccent(p.query_text)), 32) AS lexical_rank,
        similarity(kern_unaccent(lower(s.citation_label)), kern_unaccent(lower(p.query_text))) AS citation_similarity,
        CASE WHEN c.embedding IS NOT NULL AND p.query_embedding IS NOT NULL
             THEN 1 - (c.embedding <=> p.query_embedding) ELSE NULL END AS semantic_similarity,
        CASE
            WHEN COALESCE(s.effective_from, dv.effective_from) IS NULL THEN 'DATE_UNKNOWN'
            WHEN COALESCE(s.effective_from, dv.effective_from) <= p.as_of
             AND (COALESCE(s.effective_until, dv.effective_until) IS NULL OR COALESCE(s.effective_until, dv.effective_until) >= p.as_of)
            THEN 'CONFIRMED'
            ELSE 'OUT_OF_RANGE'
        END AS temporal_status
    FROM source_section_chunks c
    CROSS JOIN params p
    JOIN source_sections s ON s.id = c.section_id
    JOIN source_document_versions dv ON dv.id = s.document_version_id
    JOIN source_documents sd ON sd.id = dv.source_document_id
    JOIN official_sources os ON os.id = sd.official_source_id
    WHERE 1 = 1 [AND os.code = ANY(?::text[])] [AND os.trust_tier = ANY(?::text[])] [AND s.section_type = ?]
)
SELECT chunk_id, ..., temporal_status,
    (COALESCE(semantic_similarity, 0) * ? + LEAST(lexical_rank, 1) * ? + citation_similarity * ?) AS combined_score
FROM scored
WHERE temporal_status <> 'OUT_OF_RANGE'
  AND (lexical_rank > 0 OR citation_similarity > ? OR (semantic_similarity IS NOT NULL AND semantic_similarity > ?))
ORDER BY combined_score DESC, chunk_id ASC
LIMIT ?
```

PHP splits the result rows into `confirmed`/`dateUnknown` by `temporal_status` after the single
round trip — the SQL computes everything (lexical, fuzzy, semantic, temporal) together; PHP only
buckets what already came back.

## What plugging in a real embedding provider requires

1. Implement `Kern\Legislation\Retrieval\Embedding\EmbeddingProvider` — per ADR 0007, an
   OpenAI-compatible-protocol HTTP adapter, config-driven (`AI_PROVIDER`/`AI_BASE_URL`/
   `AI_MODEL`/`AI_API_KEY`), calling the provider's `/embeddings` endpoint. Not built here — no
   provider decision exists yet to build it against (`docs/STATUS.md` blocked item #3).
2. Rebind `EmbeddingProvider::class` to that adapter in `TaxKnowledgeServiceProvider` (currently
   bound to `UnconfiguredEmbeddingProvider`).
3. **If the real model's dimension differs from 64** (near-certain — real models run
   256–3072-dimensional): a migration must `DROP INDEX source_section_chunks_embedding_hnsw`,
   `ALTER TABLE source_section_chunks ALTER COLUMN embedding TYPE vector(N)`, then recreate the
   HNSW index. This is a genuine pgvector constraint (one fixed dimension per column to build an
   ANN index), not a gap in this task.
4. Re-run `EmbedIndexedChunks` for every existing chunk (all have `embedded_at IS NULL` again
   after the dimension change, or can be explicitly reset) to backfill real vectors.
5. Per the ADR 0006/0005 asymmetry this task's brief calls out: this corpus is public legislation,
   not PII, so sending it to an external embedding provider is materially lower-risk than ADR
   0005's user-document path — but the provider/region/DPA decision (`docs/STATUS.md` blocked item
   #3) still has to be made once, for whichever module needs it first.

## How to verify

Host PHP is 8.3 (cannot run this dependency tree — Pest 5 needs `^8.4`). Used the project's own
`docker/php/Dockerfile`'s `ext-builder` stage (PHP 8.5 + `pdo_pgsql`/`bcmath`/`intl`/`pcntl`,
already built and cached by a prior task) as a throwaway runner, bind-mounting the real,
already-vendored `apps/api/`, against a dedicated Postgres database
(`docs/AGENTS.md` §7b — another agent was concurrently active):

```sh
docker exec anaf-postgres-1 psql -U kern -d postgres -c 'CREATE DATABASE kern_rag;'
docker exec anaf-postgres-1 psql -U kern -d kern_rag \
  -c 'CREATE EXTENSION IF NOT EXISTS vector; CREATE EXTENSION IF NOT EXISTS unaccent; CREATE EXTENSION IF NOT EXISTS pg_trgm;'
```

```text
$ vendor/bin/pint --test                      # full repo
  PASS .......................................................... 700 files

$ php -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G   # full repo, level 9, no baseline
 [OK] No errors

$ vendor/bin/deptrac analyse --config-file=deptrac.php
  Violations 0, Skipped violations 0, Uncovered 558, Allowed 265, Warnings 0, Errors 0

# Real Postgres (kern_rag), run 1:
$ DB_CONNECTION=pgsql DB_HOST=postgres DB_DATABASE=kern_rag DB_USERNAME=kern DB_PASSWORD=*** \
    vendor/bin/pest tests/Unit/Domain/TaxKnowledge tests/Feature/Domain/TaxKnowledge --no-coverage
  Tests: 1 skipped, 118 passed (328 assertions)

# Real Postgres (kern_rag), run 2 (leak check) — identical:
  Tests: 1 skipped, 118 passed (328 assertions)

# Whole tests/Unit tree (all domains, sqlite default — pure tests, no DB needed):
$ vendor/bin/pest tests/Unit --no-coverage
  Tests: 3 warnings, 299 passed (664 assertions)
```

Migration reversibility, on real Postgres: `migrate` (301100→301102) → `migrate:rollback --step=3`
→ `migrate` again — all three steps `DONE`, no errors, confirmed by directly inspecting
`\d source_section_chunks` and `\di` before and after.

`composer validate`/`composer audit` were not run — no Composer binary in this throwaway image
(same situation as `docs/handoff/018-legislation-ingestion.md`), and this task added zero
Composer dependencies (`composer.json`/`composer.lock` untouched — confirmed unmodified).

**A concurrent-agent issue found, not fixed, outside this task's scope**
(`apps/api/app/Domain/Declarations/**`, explicitly another agent's territory per this task's
brief): `database/migrations/2026_09_08_900002_create_declarations_table.php` declares its
self-referencing `supersedes_declaration_id` foreign key **inline inside the same
`Schema::create()` call**, which fails on real Postgres with `SQLSTATE[42830]: there is no unique
constraint matching given keys for referenced table "declarations"` — the exact same defect
`docs/handoff/018-legislation-ingestion.md` already documents and fixed for `source_sections.
parent_id` (the FK must be added via a separate `Schema::table()` call after `Schema::create()`).
This blocks a full, unscoped `php artisan migrate` for anyone right now. Per `docs/AGENTS.md` §10
("record, do not silently fix") this was not touched, and per this task's explicit instruction
`docs/STATUS.md` was not edited either — recording it here for whoever owns `Domain/Declarations`
next, and for the next agent who hits the same wall. This task's own verification worked around it
by migrating only the specific files it needed (`--path=...` per file, all in the `30xxxx`
prefix range, none depending on `declarations`) rather than a full unscoped `migrate`.

## Interfaces exposed to other modules

```php
use Kern\Legislation\Retrieval\Indexing\{IndexSourceSectionForRetrieval, EmbedIndexedChunks};
use Kern\Legislation\Retrieval\Query\{HybridLegislationSearch, HybridSearchQuery};
use Kern\Legislation\Retrieval\Embedding\EmbeddingProvider; // implement this once a provider is chosen

// Index one section's chunks (text/metadata only — no provider needed):
$chunkCount = app(IndexSourceSectionForRetrieval::class)($sectionId);

// Embed up to $limit pending chunks (throws EmbeddingProviderNotConfiguredException today):
$embedded = app(EmbedIndexedChunks::class)($documentVersionId = null, $limit = 500);

// The retrieval question a future answer-generation task (once a provider exists) will call:
$result = app(HybridLegislationSearch::class)->search(new HybridSearchQuery(
    queryText: 'sănătate contribuție',
    asOf: new DateTimeImmutable('2024-01-01'),
));
// $result->confirmed: list<RetrievalResult> — safe to cite as "applied on this date"
// $result->dateUnknown: list<RetrievalResult> — matched, but applicability for this date is unverifiable
```

## What is NOT done

- **No LLM answer generation, no chat endpoint, no prompt template.** Explicitly out of scope
  per this task's brief and `BLUEPRINT.md` §15.5 — retrieval and citation only.
- **No real embedding provider.** `docs/STATUS.md` blocked item #3 is unresolved; see "What
  plugging in a real provider requires" above.
- **No scheduled/batch indexing job.** `IndexSourceSectionForRetrieval`/`EmbedIndexedChunks`
  operate per-section / per-batch; nothing calls either on a cron or loops them over every section
  of a freshly-parsed document version automatically — matching M3's own "build the capability,
  not the job" precedent.
- **No HTTP/API endpoint for retrieval.** `HybridLegislationSearch` is a plain application service;
  wiring a controller/route is a product decision this task was not asked to make (and would be
  premature before an answer-generation consumer exists to call it).
- **`tax_years`/`subject_types`/`tax_types` filters are not supported** — `ChunkableSection` (M3)
  never carried this classification (it's Milestone 4/5's job), so `HybridSearchQuery` cannot
  filter by them either. `sourceCodes`/`trustTiers`/`sectionType` are what the corpus currently
  supports classifying by.
- **No re-ranking model, no cross-encoder.** `combined_score` is a fixed linear weighting over
  three signals — reasonable as a first pass, not tuned against any real corpus or real embedding
  provider (there is neither yet).
- The concurrent `Domain/Declarations` migration bug above (not this task's file, not fixed).

## Open questions

- **Should `combined_score`'s weights be configurable** (env/config) rather than class constants,
  once real retrieval-quality feedback exists to tune them against? Not decided here — no data to
  tune against yet, and CODE-QUALITY §2 forbids adding a config knob nothing currently reads
  differently.
- **Should chunk-level `tax_years`/`subject_types`/`tax_types` classification be added to
  `ChunkableSection`/`SourceSectionChunk`/`source_section_chunks` when Milestone 4/5's
  `fiscal_parameter_versions`/`tax_rule_sources` linkage exists?** Likely yes, but that's a
  decision for whoever builds that linkage, not this task.
- **Embedding dimension (64) will need to change** the moment a real provider is chosen — see
  "What plugging in a real provider requires" #3. Flagging here so it isn't rediscovered as a
  surprise.

## Sources consulted

- `BLUEPRINT.md` §15 (in full — RAG architecture, hybrid retrieval, required metadata, citation
  enforcement, "no RAG calculations").
- `docs/AGENTS.md` — read in full; §7b (own-database-for-testing) and rule 3 ("never introduce a
  rate/threshold without a source_section_id" — informed the decision that ranking weights are
  *not* fiscal parameters) governed this task directly.
- `docs/handoff/018-legislation-ingestion.md` — the temporal model, chunking DTOs this task
  persists, and the self-referencing-FK-on-Postgres precedent (which recurred, unfixed, in a
  concurrent agent's migration — see "How to verify").
- `docs/reference/legislation-effective-dates.md` — read completely; the reason most versions are
  `REQUIRES_REVIEW` and the basis for the unknown-date rule's design.
- `docs/adr/0006-pgvector-over-qdrant.md` — the single-query hybrid-retrieval requirement this
  task's SQL directly implements; `docs/reference/verified-versions.md`'s `pgvector/pgvector:
  pg18-trixie` (Postgres 18 + pgvector 0.8.6) version pin.
- `docs/adr/0007-provider-agnostic-ai.md` — the OpenAI-compatible-protocol shape a real
  `EmbeddingProvider` implementation would follow; not built here (no provider decision to build
  it against).
- `Kern\Documents\ExternalAi\{ExternalDocumentAiProvider,NullDocumentAiProvider,
  RequestExternalDocumentExtraction}` — the exact precedent `EmbeddingProvider`/
  `UnconfiguredEmbeddingProvider`/the graceful-degrade catch in `HybridLegislationSearch` mirror.
- Direct empirical verification against the real `pgvector/pgvector:pg18-trixie` image (not
  assumed from documentation) for: `unaccent()`'s volatility (`pg_proc.provolatile = 's'`); both
  Romanian diacritic encodings already folding correctly through contrib `unaccent`'s default
  dictionary; the three real failure modes fixing an `IMMUTABLE` `unaccent` wrapper for
  `CREATE INDEX`/generated-column use required (see migration `301100`'s docblock); a plain PDO
  string parameter correctly resolving against a `vector` column with no explicit cast (works) vs.
  an explicitly `text`-typed prepared parameter against the same column with no cast in the SQL
  (fails) — the basis for `EmbeddingVector::toPgvectorLiteral()` needing no client-side pgvector
  library.
