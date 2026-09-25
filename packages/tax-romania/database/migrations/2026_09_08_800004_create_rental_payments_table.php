<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `rental_payments` (`BLUEPRINT.md` §6.5). `tax_subject_id` denormalised, matching every other
 * Wallet/Rental child table.
 *
 * `accrual_id` is `nullOnDelete()` — a payment record should outlive the accrual it once settled
 * being removed. `transaction_id` references `Domain/Wallet`'s `financial_transactions` table at
 * the schema level only — a real foreign key, never an Eloquent model import across the module
 * boundary (`docs/AGENTS.md` §6; the identical precedent `deposit_interest_events.fx_source_
 * document_version_id` already establishes, see that migration's docblock, and see
 * `Kern\TaxRomania\Rental\Models\RentalPayment`'s docblock for why no `BelongsTo` relation exists for
 * it here). `nullOnDelete()`: if the wallet transaction is ever deleted, the payment record itself
 * must survive, only losing its (unconfirmed-by-default) link.
 *
 * `transaction_match_status` (`Kern\TaxRomania\Rental\Enums\TransactionMatchStatus`) starts
 * `UNMATCHED`; a `PROPOSED`/`REJECTED` link's `transaction_id` is still populated (kept for audit,
 * per `Kern\Documents\Models\DocumentExtraction`'s identical precedent) — only `CONFIRMED`
 * should ever be treated as ground truth.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('rental_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tax_subject_id');
            $table->foreign('tax_subject_id')->references('id')->on('tax_subjects')->cascadeOnDelete();

            $table->uuid('rental_contract_id');
            $table->foreign('rental_contract_id')->references('id')->on('rental_contracts')->cascadeOnDelete();

            $table->uuid('accrual_id')->nullable();
            $table->foreign('accrual_id')->references('id')->on('rental_accruals')->nullOnDelete();

            $table->uuid('transaction_id')->nullable();
            $table->foreign('transaction_id')->references('id')->on('financial_transactions')->nullOnDelete();
            $table->string('transaction_match_status')->default('UNMATCHED');

            $table->bigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->date('paid_at');

            $table->timestampsTz();

            $table->index('tax_subject_id');
            $table->index(['rental_contract_id', 'paid_at']);
            $table->index('accrual_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_payments');
    }
};
