<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `document_extractions` (`BLUEPRINT.md` §6.6, §14.6) — one row per AI/extraction interpretation
 * attempt for a document: which extractor produced it, its confidence, and its review status.
 * `status` defaults to `PROPOSED` and nothing in this task's code is permitted to write
 * `ACCEPTED` — see `Kern\Documents\Models\DocumentExtraction::accept()`'s docblock, the one
 * place that transition is even possible, which requires a `reviewed_by` human actor.
 *
 * `confidence_decimal` is `decimal(5,4)`, not a float column and never cast to a PHP `float` in
 * the model (`docs/handoff/016-postgres-suite-stabilisation.md` documents exactly this NUMERIC-
 * through-PHP-float trap for the Fx module) — the model casts it to `string`.
 *
 * `reviewed_by` uses `nullOnDelete()`: unlike `audit_events`/`audit_chain_heads`
 * (`docs/handoff/011-identity-tenancy.md` decision #4), this table carries no append-only
 * trigger, so `SET NULL` on a deleted reviewer is an ordinary, safe update.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('document_extractions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('document_id');
            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();

            $table->string('extractor_type');
            $table->string('extractor_version');
            $table->string('model_provider')->nullable();
            $table->string('model_name')->nullable();

            $table->string('schema_version');
            $table->text('result_ciphertext');
            $table->decimal('confidence_decimal', 5, 4);

            $table->string('status')->default('PROPOSED');

            $table->uuid('reviewed_by')->nullable();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();

            $table->timestampsTz();

            $table->index('document_id');
            $table->index(['document_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_extractions');
    }
};
