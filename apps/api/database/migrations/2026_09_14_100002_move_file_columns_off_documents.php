<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The columns that describe a *file* leave `documents`, now that `document_parts` holds them.
 *
 * `sha256` stays, but changes meaning: it is no longer this document's file's hash, it is the hash
 * of its parts' hashes in order. That keeps the duplicate check on upload working — re-uploading
 * the same four photographs in the same order is the same document — while making it answerable at
 * all for a document that has no single file. `App\Domain\Documents\Models\Document::contentHash()`
 * is the one place it is computed.
 *
 * For every document that exists today the composite hash of a single part is `sha256(that part's
 * hash)`, so the column is rewritten here rather than left holding the old meaning: a mixture of
 * the two definitions in one column is how a duplicate check starts silently passing.
 *
 * `part_count` is denormalised on purpose. The inbox renders "4 pagini" on every row of every page
 * of the list, and asking the parts table per row is the classic N+1 that a list screen cannot
 * afford. It is maintained in exactly one place, `RecalculateDocumentAggregate`.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->unsignedInteger('part_count')->default(1);
        });

        // Computed in PHP rather than in SQL: `digest()` lives in pgcrypto, which this database
        // does not install, and enabling an extension to rewrite one column once is a permanent
        // cost for a one-off. The definition is the same one `Document::contentHash()` applies.
        $parts = DB::table('document_parts')->orderBy('document_id')->lazyById(500, 'id');

        foreach ($parts as $part) {
            DB::table('documents')
                ->where('id', $part->document_id)
                ->update([
                    'sha256' => hash('sha256', $part->sha256),
                    'part_count' => 1,
                ]);
        }

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn([
                'original_filename_ciphertext',
                'mime_type',
                'size_bytes',
                'object_path',
                'object_encryption_version',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->text('original_filename_ciphertext')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('object_path')->nullable();
            $table->unsignedInteger('object_encryption_version')->nullable();
            $table->dropColumn('part_count');
        });

        DB::statement(<<<'SQL'
            UPDATE documents
               SET original_filename_ciphertext = parts.original_filename_ciphertext,
                   mime_type = parts.mime_type,
                   size_bytes = parts.size_bytes,
                   sha256 = parts.sha256,
                   object_path = parts.object_path,
                   object_encryption_version = parts.object_encryption_version
              FROM document_parts AS parts
             WHERE parts.document_id = documents.id
               AND parts.position = 1
        SQL);
    }
};
