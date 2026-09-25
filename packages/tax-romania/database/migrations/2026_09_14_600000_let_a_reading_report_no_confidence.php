<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `document_extractions.confidence_decimal` becomes nullable, so "the reader did not say" can be
 * recorded as what it is.
 *
 * The column was `NOT NULL`, so a reading that reported no confidence had to be given a number
 * anyway, and the number chosen was 0.6000. That placeholder then appeared on the review screen as
 * a filled bar reading 60% — indistinguishable from a model that had actually assessed the document
 * and arrived at 60%. Every reading looked equally, mildly uncertain, and the one figure whose job
 * is to flag a reading worth checking said the same thing about all of them.
 *
 * Null means the reader did not report one. The screen says so in words rather than drawing a bar.
 *
 * Existing rows are left exactly as they are: 0.6000 may be a placeholder or a real assessment and
 * nothing in the data distinguishes them, so rewriting them would be inventing history. They age
 * out as documents are re-read.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('document_extractions', function (Blueprint $table): void {
            $table->decimal('confidence_decimal', 5, 4)->nullable()->change();
        });
    }

    public function down(): void
    {
        // A row with no reported confidence has to become something to go back. The old placeholder
        // is the honest choice: it is exactly what this column held before, for exactly this case.
        DB::table('document_extractions')->whereNull('confidence_decimal')->update(['confidence_decimal' => '0.6000']);

        Schema::table('document_extractions', function (Blueprint $table): void {
            $table->decimal('confidence_decimal', 5, 4)->nullable(false)->change();
        });
    }
};
