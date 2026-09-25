<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `declaration_submissions` (`BLUEPRINT.md` §6.10) — one attempt to file a `declarations` row,
 * `transport` `SPV_BROWSER|ANAF_API|MANUAL`. This task builds no actual transport (SPV automation
 * is M10, `docs/reference/anaf-spv-integration.md`) — `Kern\TaxRomania\Declarations\Actions\
 * SubmitDeclaration` records the fact that a submission happened (`submitted_at`,
 * `external_reference_ciphertext`) once a caller (a future M10 action) tells it to.
 *
 * `external_reference_ciphertext`/`response_ciphertext` are encrypted per `BLUEPRINT.md` §4.3 — an
 * ANAF submission reference or response payload can carry the same identifying content as the
 * filing itself. There is no `tax_subject_id` column on this table (the blueprint's own schema
 * does not carry one here); `Kern\TaxRomania\Declarations\Models\DeclarationSubmission` reads its
 * owning subject id from the parent `declarations` row it belongs to, the identical shape
 * `Kern\Documents\Models\DocumentExtraction` already establishes for the same "encrypted
 * child row with no tenant column of its own" case.
 *
 * `receipt_document_id` references `Kern\Documents`' `documents` table as a real,
 * schema-level foreign key only (`nullOnDelete()`) — the identical precedent
 * `rental_contracts.source_document_id -> documents` already establishes.
 *
 * `response_ciphertext`/`receipt_document_id` may be recorded once, after `submitted_at`, when
 * ANAF's outcome becomes known (`Kern\TaxRomania\Declarations\Models\DeclarationSubmission::
 * recordResponse()`); no `updated_at` column exists, so that write is a plain column `UPDATE` with
 * no implied "something else may also have changed" (`Declaration::UPDATED_AT`-style precedent).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('declaration_submissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('declaration_id')->constrained('declarations');
            $table->string('transport');
            $table->timestampTz('submitted_at');

            $table->text('external_reference_ciphertext')->nullable();

            $table->uuid('receipt_document_id')->nullable();
            $table->foreign('receipt_document_id')->references('id')->on('documents')->nullOnDelete();

            $table->text('response_ciphertext')->nullable();

            $table->timestampTz('created_at');

            $table->index('declaration_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('declaration_submissions');
    }
};
