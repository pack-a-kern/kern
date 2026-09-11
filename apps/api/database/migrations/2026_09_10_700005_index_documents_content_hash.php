<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supports the duplicate check every upload now makes.
 *
 * `documents.sha256` has been recorded since the table was created but never read, so nothing
 * needed to find a row by it. `InitiateDocumentUpload` now looks one up on every upload, and that
 * lookup is on the path a person waits on — an unindexed scan of a subject's whole document history
 * would get slower every time they used the app.
 *
 * Composite and subject-first: the query always narrows by tenant before content, and a hash index
 * spanning every tenant would answer a question no query asks (`BLUEPRINT.md` §6.1 — business
 * queries are tenant-scoped).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->index(['tax_subject_id', 'sha256']);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropIndex(['tax_subject_id', 'sha256']);
        });
    }
};
