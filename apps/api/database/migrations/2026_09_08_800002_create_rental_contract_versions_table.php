<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `rental_contract_versions` (`BLUEPRINT.md` §6.5) — the temporal source of truth for a contract's
 * rent/payment terms. `tax_subject_id` denormalised beyond the literal column list, matching every
 * other Wallet/Rental child table in this codebase (`docs/handoff/021-wallet.md`).
 *
 * `valid_to IS NULL` means "still in force" — the same convention `deposit_rate_periods.valid_to`
 * and `fiscal_parameter_versions.valid_until` already use. Exactly one such row per
 * `rental_contract_id` is expected at any time; enforced by construction in
 * `App\Domain\Rental\Actions\{CreateRentalContract,AmendRentalContract,TerminateRentalContract}`,
 * not by a database constraint (Postgres has no portable "at most one NULL per group" constraint
 * without a partial unique index predicate this schema does not need elsewhere).
 *
 * `rent_minor`/`payment_day` are plain integers, never a float (`docs/AGENTS.md` rule 6).
 * `terms_ciphertext` encrypted per §4.3 ("contracte/documente") — free-form amendment notes, never
 * read by any deterministic calculation (only `rent_minor`/`payment_frequency`/`payment_day` are).
 *
 * `source_document_id` references `Domain/Documents`' table at the schema level only, exactly like
 * `rental_contracts.source_document_id` — see that migration's docblock.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('rental_contract_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tax_subject_id');
            $table->foreign('tax_subject_id')->references('id')->on('tax_subjects')->cascadeOnDelete();

            $table->uuid('rental_contract_id');
            $table->foreign('rental_contract_id')->references('id')->on('rental_contracts')->cascadeOnDelete();

            $table->date('valid_from');
            $table->date('valid_to')->nullable();

            $table->bigInteger('rent_minor');
            $table->string('payment_frequency');
            $table->unsignedTinyInteger('payment_day')->nullable();

            $table->text('terms_ciphertext')->nullable();

            $table->uuid('source_document_id')->nullable();
            $table->foreign('source_document_id')->references('id')->on('documents')->nullOnDelete();

            $table->timestampsTz();

            $table->index('tax_subject_id');
            $table->unique(['rental_contract_id', 'valid_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_contract_versions');
    }
};
