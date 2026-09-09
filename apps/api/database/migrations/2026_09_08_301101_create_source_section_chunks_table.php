<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `source_section_chunks` — Milestone 11's retrieval index (`docs/handoff/029-hybrid-retrieval.md`,
 * `BLUEPRINT.md` §15.1: "Qdrant is derivative index... can be rebuilt completely" — this table is
 * that derivative index's storage, now living in Postgres per `docs/adr/0006-pgvector-over-qdrant.md`
 * rather than a second service). One row per
 * {@see \App\Domain\TaxKnowledge\Chunking\SourceSectionChunk} DTO — M3
 * (`docs/handoff/018-legislation-ingestion.md` "Key decisions" #4) deliberately left chunking
 * DTO-only with "no consumer yet"; this table is that consumer.
 *
 * **Not denormalized.** `document_version_id`/`source_document_id`/`trust_tier`/effective dates
 * are *not* copied onto this table — they are read via `JOIN` back to `source_sections` /
 * `source_document_versions` / `source_documents` / `official_sources` at query time
 * ({@see \App\Domain\TaxKnowledge\Retrieval\Query\HybridLegislationSearch}). `BLUEPRINT.md` §15.1
 * already commits this index to being a *rebuildable derivative*, and a section's own
 * `effective_from`/`effective_until` can be supplied later by a human reviewer
 * (`docs/reference/legislation-effective-dates.md`) without this table's rows needing to change —
 * denormalizing those columns here would silently go stale the moment that human review happens,
 * for a lookup that a `JOIN` already answers correctly and is cheap at the 10k-100k-chunk scale
 * ADR 0006 sizes this for. This is also why `section_id` cascades on delete: when
 * `App\Domain\TaxKnowledge\Services\LegislationSectionPersister` replaces a version's whole section
 * set (a hard delete-and-recreate, not an update), this table's rows for the deleted sections must
 * not become orphans pointing at nothing.
 *
 * **`embedding` and `text_search` are added via raw SQL below, guarded to `pgsql`, after the
 * portable `Schema::create()`** — the same shape
 * `2026_09_08_100001_create_audit_events_table.php`'s docblock explains for its trigger: neither
 * `vector(N)` nor a generated `tsvector` column exists in Laravel's schema builder, and the base
 * table must still exist under any other driver for a test run that migrates everything.
 *
 * **Embedding dimension is fixed at 64 — an explicit placeholder, not a modelled decision.**
 * `docs/STATUS.md` blocked item #3 (external AI provider) is still open, so no real embedding
 * model, and therefore no real dimension, has been chosen. 64 is small enough to make clear it is
 * a wiring/testing stand-in (real embedding models are typically 256-3072 dimensions), not a
 * guess at a genuine provider's output size. **Swapping in a real provider whose native dimension
 * differs requires a migration to `ALTER COLUMN embedding TYPE vector(N)`, drop and rebuild the
 * HNSW index, and re-embed every row** — pgvector requires one fixed dimension per column to
 * build an ANN index at all. This is a known, unavoidable pgvector constraint (not a gap in this
 * task) and is recorded in the handoff note's "what plugging in a real provider requires" section.
 *
 * `embedding_model`/`embedding_dim`/`embedded_at` are per-row provenance (`embedding_model` names
 * whichever `App\Domain\TaxKnowledge\Retrieval\Embedding\EmbeddingProvider::modelIdentifier()`
 * produced this row's vector) so that rows embedded under different models/providers over the
 * project's life are distinguishable — mirroring `document_extractions.model_provider`/
 * `.model_name`'s exact precedent (`BLUEPRINT.md` §6.6, ADR 0007).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('source_section_chunks', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('section_id')->constrained('source_sections')->cascadeOnDelete();

            $table->unsignedInteger('chunk_index');
            $table->unsignedInteger('chunk_count');

            $table->text('text');
            $table->char('text_hash', 64);

            $table->string('embedding_model')->nullable();
            $table->unsignedInteger('embedding_dim')->nullable();
            $table->timestampTz('embedded_at')->nullable();

            $table->timestampsTz();

            $table->unique(['section_id', 'chunk_index'], 'source_section_chunks_section_chunk_unique');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('ALTER TABLE source_section_chunks ADD COLUMN embedding vector(64)');

        // `plainto_tsquery`/`ts_rank_cd` on the query side must apply the identical
        // taxos_unaccent() transform for the diacritic-insensitivity to hold — see
        // App\Domain\TaxKnowledge\Retrieval\Query\HybridLegislationSearch.
        DB::unprepared(<<<'SQL'
            ALTER TABLE source_section_chunks
                ADD COLUMN text_search tsvector
                GENERATED ALWAYS AS (to_tsvector('romanian', taxos_unaccent(text))) STORED
            SQL);

        DB::unprepared('CREATE INDEX source_section_chunks_text_search_gin ON source_section_chunks USING gin (text_search)');
        DB::unprepared('CREATE INDEX source_section_chunks_embedding_hnsw ON source_section_chunks USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('source_section_chunks');
    }
};
