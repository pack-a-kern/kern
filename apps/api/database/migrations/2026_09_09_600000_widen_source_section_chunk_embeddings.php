<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widens `source_section_chunks.embedding` from the `vector(64)` placeholder to `vector(384)`, the
 * width `BAAI/bge-m3` emits.
 *
 * `2026_09_08_301101_create_source_section_chunks_table.php` created the column at 64 deliberately,
 * as "a wiring/testing stand-in (real embedding models are typically 256-3072 dimensions)", and
 * recorded that choosing a model "requires a migration to `ALTER COLUMN embedding TYPE vector(N)`,
 * drop and rebuild the index". This is that migration.
 *
 * **Every stored embedding is discarded.** pgvector cannot reinterpret a 64-dimension vector as a
 * 1024-dimension one, and there is nothing to preserve: no real provider ever ran, so every
 * `embedding` is null. `embedded_at`/`embedding_model` are cleared for the same reason a widened
 * column is — a row claiming to be embedded in a vector space that no longer exists would be read
 * as usable by `EmbedIndexedChunks`, which only ever looks for rows *without* an embedding.
 *
 * The HNSW index is dropped and rebuilt rather than altered: its internal graph is built for a
 * fixed dimension, and Postgres will not carry it across a type change.
 *
 * Postgres-only, like the columns it alters — `vector` is a pgvector type and the sqlite suite
 * never created the column at all.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->retype(toModelWidth: true);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->retype(toModelWidth: false);
    }

    private function retype(bool $toModelWidth): void
    {
        // Two literal statements rather than one interpolated: `unprepared()` takes a
        // literal-string, and writing both out keeps the dimension impossible to influence from
        // anywhere. 384 is what `intfloat/multilingual-e5-small` emits; 64 is the placeholder this replaces.
        $alter = $toModelWidth
            ? 'ALTER TABLE source_section_chunks ALTER COLUMN embedding TYPE vector(384)'
            : 'ALTER TABLE source_section_chunks ALTER COLUMN embedding TYPE vector(64)';

        DB::unprepared('DROP INDEX IF EXISTS source_section_chunks_embedding_hnsw');

        // Cleared before the type change, not after: the column cannot hold its old values across
        // it, and a row whose `embedded_at` outlived its vector would be invisible to the backfill
        // that is supposed to redo it.
        DB::unprepared(
            'UPDATE source_section_chunks
                SET embedding = NULL, embedded_at = NULL, embedding_model = NULL, embedding_dim = NULL
              WHERE embedding IS NOT NULL OR embedded_at IS NOT NULL',
        );

        DB::unprepared($alter);

        DB::unprepared(
            'CREATE INDEX source_section_chunks_embedding_hnsw
                 ON source_section_chunks USING hnsw (embedding vector_cosine_ops)',
        );
    }
};
