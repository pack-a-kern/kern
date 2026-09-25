<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `source_document_versions` (`BLUEPRINT.md` §6.8/§9.1) — one immutable row per distinct byte
 * sequence ever fetched for a `source_document`. **Never updated, never deleted** by application
 * code (`docs/AGENTS.md` rule 17, "overwrite a raw official source" — forbidden).
 *
 * `content_sha256` is the identity of the content, not just an integrity check: it names the
 * object's path in `Kern\Legislation\Storage\RawSourceObjectStore` (content-addressed
 * storage), and the unique index below on `(source_document_id, content_sha256)` is what makes
 * re-ingesting byte-identical content a no-op rather than a duplicate row — BLUEPRINT.md §7.2's
 * "idempotent" requirement and this milestone's own "importing the same document twice changes
 * nothing" test.
 *
 * `parser_version`/`supersedes_version_id`/`status` from `BLUEPRINT.md` §6.8's fuller schema are
 * deliberately not included here: those describe the legislation *parsing* pipeline (Milestone 3
 * — structural parsing, chunking, candidate extraction), which does not exist yet. Adding them
 * now, unused, would be exactly the "speculative scaffolding" `docs/CODE-QUALITY.md` §2 forbids.
 * Whoever builds Milestone 3 adds them in a new migration when the parsing pipeline actually
 * needs them.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('source_document_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('source_document_id')->constrained('source_documents');
            $table->timestampTz('retrieved_at');
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->char('content_sha256', 64);
            $table->string('raw_object_path');
            $table->unsignedBigInteger('size_bytes');
            $table->string('content_type')->nullable();

            $table->timestampsTz();

            $table->unique(['source_document_id', 'content_sha256'], 'source_document_versions_content_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_document_versions');
    }
};
