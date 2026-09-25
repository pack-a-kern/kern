<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a reading of the law found nothing for.
 *
 * A value missing for a year is two different things — the law of that year does not state it, as
 * rent had no quota of expenses in 2023, or nothing was asked because no model could be reached —
 * and a screen showing both as an empty cell says neither. A row here is the first: read, and the
 * law does not say. `UNREAD` is the second, and is work still to do.
 *
 * One row per value and year, replaced by the next reading and deleted the moment a value is
 * proposed for that year: nothing here outlives what it explains.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('fiscal_parameter_reads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('fiscal_parameter_id')->constrained('fiscal_parameters')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            // ParameterReadOutcome: NOT_IN_LAW, UNREAD.
            $table->string('outcome', 16);
            $table->string('model')->nullable();
            $table->timestampTz('read_at');
            $table->timestampsTz();

            $table->unique(['fiscal_parameter_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_parameter_reads');
    }
};
