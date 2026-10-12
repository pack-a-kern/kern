<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One vector per distinct text, instead of one per chunk row.
 *
 * `ext_chunk_embeddings` has keyed every vector by its text's hash since
 * `2026_09_22_200000_add_outline_and_embedding_cache_to_legislation.php`, so the embedding model is
 * already never called twice for the same sentence. What this migration removes is the *second*
 * copy: `EmbedIndexedChunks` looked the vector up by hash and then wrote it onto the chunk row as
 * well, which on this corpus meant 583,082 copies of 15,582 distinct vectors.
 *
 * The duplication is not an accident of ingestion — it is what a consolidated law is. Legea
 * 227/2015 has 91 consolidations; a consolidation differs from the one before it in a handful of
 * provisions and repeats the other ~7,000 unchanged. Every version holds its own section rows,
 * which it must: a section carries that version's dates and its place in that version's outline.
 * The *text* of an unchanged provision, and therefore its meaning, is the same object in all 91.
 *
 * Measured before writing this: 1,743 MB of chunk table and indexes, against 35 MB for the vectors
 * themselves. Every chunk hash already had a cache row (0 missing), so nothing is re-embedded —
 * the column being dropped holds no information the cache does not.
 *
 * **The cache narrows to one row per text.** Its key was `(text_hash, embedding_model)`, which
 * allowed two models' vectors to sit side by side. That is given up deliberately: a model change
 * moves every vector to a new space and means re-embedding the corpus (see
 * `config/ai/embeddings.php`), so a row per text that a re-embed overwrites is the honest shape,
 * and it is what lets the search join a chunk to its vector and get exactly one.
 *
 * It also repairs a split this project caused itself. `HttpEmbeddingProvider::modelIdentifier()`
 * fell back to the configured `EMBEDDING_MODEL_ID` before the service had answered, and the service
 * reports `model_id@revision` — so the first batch of each worker process stored
 * `intfloat/multilingual-e5-small` and every later batch stored
 * `intfloat/multilingual-e5-small@main`. One model, two names, 1,044 texts cached under both. The
 * two copies of those texts' vectors agree to a cosine similarity of 0.9935–0.9988 (int8 ONNX
 * inference is not bit-reproducible across batch sizes), so the surplus row is dropped rather than
 * kept: it is the same vector, measured twice.
 *
 * Postgres-only, like every migration touching a `vector` column — the sqlite lane has no pgvector
 * and skips the whole retrieval suite (`docs/handoff/029-hybrid-retrieval.md`).
 *
 * Reversible: `down()` puts the four columns back and refills them from the cache, which remains
 * the authority either way. What it cannot restore is the second name for one model, and there is
 * nothing to restore — it was never a second model.
 *
 * Note that dropping a column does not return its disk. The ~900 MB the copied vectors occupy
 * (770 MB of out-of-line storage plus a 160 MB HNSW index over it) is freed by the index drop and
 * then by a table rewrite — `VACUUM FULL ext_source_section_chunks`, which takes an exclusive lock
 * and is left to an operator rather than run inside a migration.
 */
return new class () extends Migration {
    /** 384 as a literal, for the reason `2026_09_09_600000_widen_source_section_chunk_embeddings.php` states. */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Deterministic by `created_at` then name, rather than whichever row the heap returns
        // first: the kept row should be the later one, which is the name 93% of chunks carry.
        DB::unprepared(
            'DELETE FROM ext_chunk_embeddings
              WHERE ctid IN (
                    SELECT ctid FROM (
                        SELECT ctid, row_number() OVER (
                            PARTITION BY text_hash ORDER BY created_at DESC, embedding_model DESC
                        ) AS rn
                        FROM ext_chunk_embeddings
                    ) ranked
                     WHERE rn > 1
              )',
        );

        DB::unprepared('ALTER TABLE ext_chunk_embeddings DROP CONSTRAINT ext_chunk_embeddings_pkey');
        DB::unprepared('ALTER TABLE ext_chunk_embeddings ADD PRIMARY KEY (text_hash)');

        // The column was declared `vector` with no width, which cannot carry an ANN index at all —
        // pgvector builds its graph for one fixed dimension. Widthless was harmless while the
        // table was only ever read by hash; it is the whole point now.
        DB::unprepared('ALTER TABLE ext_chunk_embeddings ALTER COLUMN embedding TYPE vector(384)');
        DB::unprepared(
            'CREATE INDEX ext_chunk_embeddings_embedding_hnsw
                 ON ext_chunk_embeddings USING hnsw (embedding vector_cosine_ops)',
        );

        // The join from a chunk to its vector, and from a vector back to the chunks that share it.
        DB::unprepared('CREATE INDEX ext_source_section_chunks_text_hash ON ext_source_section_chunks (text_hash)');

        // Drops the HNSW index over the copies with it.
        DB::unprepared(
            'ALTER TABLE ext_source_section_chunks
                 DROP COLUMN embedding,
                 DROP COLUMN embedding_model,
                 DROP COLUMN embedding_dim,
                 DROP COLUMN embedded_at',
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(
            'ALTER TABLE ext_source_section_chunks
                 ADD COLUMN embedding vector(384),
                 ADD COLUMN embedding_model varchar(255),
                 ADD COLUMN embedding_dim integer,
                 ADD COLUMN embedded_at timestamp(0) with time zone',
        );

        DB::unprepared(
            'UPDATE ext_source_section_chunks AS c
                SET embedding = e.embedding,
                    embedding_model = e.embedding_model,
                    embedding_dim = e.embedding_dim,
                    embedded_at = e.created_at
               FROM ext_chunk_embeddings AS e
              WHERE e.text_hash = c.text_hash',
        );

        DB::unprepared(
            'CREATE INDEX ext_source_section_chunks_embedding_hnsw
                 ON ext_source_section_chunks USING hnsw (embedding vector_cosine_ops)',
        );

        DB::unprepared('DROP INDEX ext_source_section_chunks_text_hash');
        DB::unprepared('DROP INDEX ext_chunk_embeddings_embedding_hnsw');
        DB::unprepared('ALTER TABLE ext_chunk_embeddings ALTER COLUMN embedding TYPE vector');
        DB::unprepared('ALTER TABLE ext_chunk_embeddings DROP CONSTRAINT ext_chunk_embeddings_pkey');
        DB::unprepared('ALTER TABLE ext_chunk_embeddings ADD PRIMARY KEY (text_hash, embedding_model)');
    }
};
