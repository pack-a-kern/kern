<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `source_documents` (`BLUEPRINT.md` §6.8) — one row per logical document a source publishes (e.g.
 * "BNR daily FX rates feed", "BNR yearly FX archive for 2025", a specific ANAF instruction PDF).
 * Content changes over time are versions (`source_document_versions`), never a new row here.
 *
 * `document_type` is a free-form code owned by whichever module ingests it (`FX_DAILY`/
 * `FX_YEARLY` for this milestone's BNR importer; `Kern\Legislation` intentionally does not
 * constrain it to an enum — Milestone 3's legislation ingestion will add its own values, and a
 * DB-level enum would force every future document type through this migration's file).
 *
 * `external_identifier` disambiguates multiple documents from the same source and type that would
 * otherwise collide on `canonical_url` alone changing over time (e.g. the yearly archive's
 * identifier is the year itself, `"2025"`, `"2026"`, ... — the URL for a given year may even
 * change if BNR restructures its site, but the identifier does not).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('source_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('official_source_id')->constrained('official_sources');
            $table->string('document_type');
            $table->string('external_identifier')->nullable();
            $table->text('canonical_url');
            $table->string('title')->nullable();
            $table->string('issuer')->nullable();
            $table->date('publication_date')->nullable();

            $table->timestampsTz();

            $table->unique(['official_source_id', 'document_type', 'external_identifier'], 'source_documents_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_documents');
    }
};
