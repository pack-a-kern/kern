<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `document_derivatives` (`BLUEPRINT.md` §6.6) — a binary/text artefact produced *from* a document
 * (its extracted text, a normalized PDF, a thumbnail, ...), stored in the encrypted object store
 * under its own `App\Support\ObjectStore\ObjectKey`. `kind` reuses
 * `App\Support\ObjectStore\DocumentObjectKind` verbatim (cast via `Enums\DocumentDerivativeKind`
 * is deliberately *not* a second enum — `docs/CODE-QUALITY.md` §1 forbids duplicating stable
 * domain knowledge, and the object-store module already owns the closed set of derivative kinds
 * an object key may name). Only `THUMBNAIL|PDF_NORMALIZED|OCR|EXTRACTED_TEXT|STRUCTURED_JSON` are
 * ever written here in this task's code — `ORIGINAL`/`PREVIEW` name the document's own primary
 * content (`documents.object_path`), never a row in this table.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('document_derivatives', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('document_id');
            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();

            $table->string('kind');
            $table->string('object_path');
            $table->char('sha256', 64);
            $table->unsignedInteger('encryption_version');

            $table->timestampsTz();

            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_derivatives');
    }
};
