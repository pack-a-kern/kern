<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every existing document becomes a one-part document.
 *
 * Copied rather than recomputed: `object_path` in particular must be carried over verbatim. Objects
 * already in the store live at the pre-parts key (`…/documents/{id}/original.enc`), and new parts
 * are written under `…/documents/{id}/parts/{part}/original.enc`. Since the path is stored on the
 * row, both layouts coexist and no encrypted object has to be moved — which is the one step of this
 * migration that could not be rolled back if it went wrong halfway.
 *
 * Soft-deleted documents are included. Their parts are reachable only through a row the default
 * scope already hides, and leaving them out would mean a restore produced a document with no files.
 */
return new class () extends Migration {
    public function up(): void
    {
        $documents = DB::table('documents')->orderBy('id')->lazyById(500, 'id');

        foreach ($documents as $document) {
            DB::table('document_parts')->insert([
                'id' => (string) Str::uuid7(),
                'document_id' => $document->id,
                'position' => 1,
                'original_filename_ciphertext' => $document->original_filename_ciphertext,
                'mime_type' => $document->mime_type,
                'size_bytes' => $document->size_bytes,
                'sha256' => $document->sha256,
                'object_path' => $document->object_path,
                'object_encryption_version' => $document->object_encryption_version,
                // A document that reached any later status has a part that passed its scan; one
                // still in quarantine has a part still in quarantine. REJECTED is carried across
                // with its reason, because the reason a document was refused is a fact about the
                // file that was refused.
                'status' => match ($document->status) {
                    'QUARANTINE' => 'QUARANTINE',
                    'REJECTED' => 'REJECTED',
                    default => 'SCANNED',
                },
                'rejection_reason' => $document->status === 'REJECTED' ? $document->rejection_reason : null,
                'created_at' => $document->created_at,
                'updated_at' => $document->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('document_parts')->delete();
    }
};
