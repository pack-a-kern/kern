<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `document_parts` — the files a document is made of.
 *
 * Until now the file *was* the document: `documents` carried the object key, the sniffed MIME, the
 * size and the content hash directly, so one upload could only ever mean one thing on screen. An
 * invoice photographed across four pages arrived as four unrelated documents, each sent to be read
 * on its own, each proposing its own quarter of an answer — and the person was left to reconcile
 * four partial readings of one piece of paper.
 *
 * A document is now an *ordered list of files*. The common case is unchanged: one PDF is one
 * document with one part. The columns that describe bytes live here, because they describe a file;
 * the columns that describe meaning stay on `documents`, because they describe the document.
 *
 * `position` is what makes it a document rather than a bag: page 2 of an invoice follows page 1,
 * and the extractor concatenates in this order. Unique per document so two parts cannot claim the
 * same place — a silent tie would reorder a person's invoice differently on every read.
 *
 * `status` is per part because scanning is per part: ClamAV scans bytes, and there is no such thing
 * as scanning a logical document. The document is clean only once every part is, and one infected
 * part rejects the whole document — see `Kern\Documents\Jobs\ScanQuarantinedPart`.
 *
 * `sha256` stays per part as well as being rolled up into the document's own composite hash: "have
 * I already uploaded this exact photograph" and "have I already uploaded this exact document" are
 * different questions and both get asked.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('document_parts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('document_id');
            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();

            // 1-based, so what the database says and what the review screen says are the same
            // number. A person reporting "page 3 is upside down" should not need translating.
            $table->unsignedInteger('position');

            $table->text('original_filename_ciphertext');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);

            $table->string('object_path');
            $table->unsignedInteger('object_encryption_version');

            // 'QUARANTINE' | 'SCANNED' | 'REJECTED' — Kern\Documents\Enums\DocumentStatus,
            // restricted to the three a *file* can be in. PROCESSING and READY describe reading a
            // document's meaning, which parts have no part in.
            $table->string('status')->default('QUARANTINE');
            $table->string('rejection_reason')->nullable();

            $table->timestampsTz();

            $table->unique(['document_id', 'position']);
            $table->index('document_id');
            // "Is this exact file already a part of something?" — asked per subject via the parent,
            // so document-first rather than a hash index spanning every tenant.
            $table->index(['document_id', 'sha256']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_parts');
    }
};
