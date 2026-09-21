<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A law's table of contents, and embeddings that outlive a re-parse.
 *
 * - `ext_source_outline` is one row per heading of a version — Titlul IV, Capitolul IV, Secțiunea
 *   1 — with its name, and `ext_source_sections.outline_id` says which heading an article or annex
 *   stands under. It is what leads from a kind of taxpayer to its part of the law: Title IV is the
 *   income tax of natural persons, its chapters the categories of income.
 * - `ext_chunk_embeddings` keeps each text's vector by the text's hash and the model. Re-parsing a
 *   version recreates its chunks, which used to throw away every vector and send the whole law back
 *   to the model; now a chunk whose text was ever embedded takes its vector from here.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ext_source_outline', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_version_id')->constrained('ext_source_document_versions')->cascadeOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->string('level');
            // "Titlul IV", and its name — "Impozitul pe venit".
            $table->string('label');
            $table->text('name');
            // The labels from the top — "Titlul IV Capitolul IV" — the same in every consolidation, so
            // a chapter can be followed from one to the next.
            $table->string('path');
            // Among its siblings, and in the whole table of contents: reading order.
            $table->unsignedInteger('ordinal');
            $table->unsignedInteger('position');
            $table->timestampsTz();

            $table->index(['document_version_id', 'path'], 'source_outline_path');
        });

        Schema::table('ext_source_outline', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('ext_source_outline')->cascadeOnDelete();
        });

        Schema::table('ext_source_sections', function (Blueprint $table): void {
            $table->foreignUuid('outline_id')->nullable()->constrained('ext_source_outline')->nullOnDelete();
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // `vector` without a dimension: the cache holds whatever model produced a vector, and is looked
        // up by hash, never searched by similarity, so it needs no index of its own kind.
        DB::unprepared(<<<'SQL'
            CREATE TABLE ext_chunk_embeddings (
                text_hash char(64) NOT NULL,
                embedding_model varchar(255) NOT NULL,
                embedding_dim integer NOT NULL,
                embedding vector NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (text_hash, embedding_model)
            )
            SQL);

        DB::unprepared(<<<'SQL'
            INSERT INTO ext_chunk_embeddings (text_hash, embedding_model, embedding_dim, embedding)
            SELECT DISTINCT ON (text_hash, embedding_model) text_hash, embedding_model, embedding_dim, embedding
            FROM ext_source_section_chunks
            WHERE embedded_at IS NOT NULL AND embedding IS NOT NULL AND embedding_model IS NOT NULL
            ORDER BY text_hash, embedding_model, embedded_at DESC
            SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TABLE IF EXISTS ext_chunk_embeddings');
        }

        Schema::table('ext_source_sections', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('outline_id');
        });

        Schema::dropIfExists('ext_source_outline');
    }
};
