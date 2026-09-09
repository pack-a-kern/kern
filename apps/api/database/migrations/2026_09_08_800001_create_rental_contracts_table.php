<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `rental_contracts` (`BLUEPRINT.md` §6.5). `tenant_identity_ciphertext` encrypted per §4.3
 * ("contrapărți identificabile").
 *
 * **Deliberate deviation from the blueprint's literal column list**: no `rent_minor`,
 * `payment_frequency`, or `payment_day` here — those live only on `rental_contract_versions`
 * (see `App\Domain\Rental\Models\RentalContract`'s docblock for why: a single source of truth for
 * "the rent in force on date X" is what makes correct cross-amendment accruals possible at all).
 * `deposit_minor` stays here — a one-time fact of the original contract, never versioned.
 *
 * `source_document_id`/`extraction_id` reference `Domain/Documents`' tables at the schema level
 * only — a real foreign key, never an Eloquent model import across the module boundary
 * (`docs/AGENTS.md` §6; the identical precedent `deposit_interest_events.fx_source_document_
 * version_id` already establishes in this codebase, see that migration's docblock). Both
 * `nullOnDelete()`: a rental contract must survive its source document being deleted.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('rental_contracts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tax_subject_id');
            $table->foreign('tax_subject_id')->references('id')->on('tax_subjects')->cascadeOnDelete();

            $table->uuid('property_id');
            $table->foreign('property_id')->references('id')->on('properties')->cascadeOnDelete();

            $table->string('tenant_type');
            $table->text('tenant_identity_ciphertext');

            $table->date('start_date');
            $table->date('end_date')->nullable();

            $table->char('currency_code', 3);
            $table->bigInteger('deposit_minor')->nullable();

            $table->string('status')->default('ACTIVE');

            $table->uuid('source_document_id')->nullable();
            $table->foreign('source_document_id')->references('id')->on('documents')->nullOnDelete();

            $table->uuid('extraction_id')->nullable();
            $table->foreign('extraction_id')->references('id')->on('document_extractions')->nullOnDelete();

            $table->timestampsTz();

            $table->index('tax_subject_id');
            $table->index('property_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_contracts');
    }
};
