<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records what came of a document's external-AI reading attempt (ADR 0005).
 *
 * Until now a failed attempt left no trace anywhere a person could see: the document reached
 * `READY`, no `document_extractions` row was written, and the review screen said "there is no
 * proposed extraction for this document yet" — describing a document nothing had been tried on and
 * one whose provider had just timed out in identical words. The only difference was a line in a
 * queue worker's log file.
 *
 * Two columns rather than one so "never attempted" and "attempted, and here is when" stay
 * distinguishable, and so a stale outcome can be recognised as stale.
 *
 * The outcome is a closed enum (`Kern\Documents\Enums\AiExtractionOutcome`), never free text
 * and never a provider's own error message: a failing endpoint can echo back parts of what it was
 * sent, and what it was sent is somebody's receipt (`BLUEPRINT.md` §4.10).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->string('ai_extraction_outcome')->nullable()->after('rejection_reason');
            $table->timestampTz('ai_extraction_attempted_at')->nullable()->after('ai_extraction_outcome');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn(['ai_extraction_outcome', 'ai_extraction_attempted_at']);
        });
    }
};
