<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rentals keep what a contract owes, and stop tracking what a tenant paid.
 *
 * Rental income is set by the contract for each year regardless of when the rent is collected
 * (Codul fiscal, art. 84), so a record of payments received answers a question the tax never asks.
 * Received rent already exists where every other money does — as a transaction on the account it
 * arrived in — and a second copy of it here was two ledgers for the same money.
 *
 * - `rental_payments` goes, with the matching of payments to transactions that fed it.
 * - `rental_accruals.status` goes: a period is owed whether or not it was paid, so "paid" is not a
 *   property of it.
 * - `rental_contracts.deposit_minor` goes: a guarantee deposit is not income, nothing read it, and
 *   nobody is asked for one.
 *
 * All three were empty — nothing in the application ever wrote them.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('rental_payments');

        Schema::table('rental_accruals', function (Blueprint $table): void {
            $table->dropColumn('status');
        });

        Schema::table('rental_contracts', function (Blueprint $table): void {
            $table->dropColumn('deposit_minor');
        });
    }

    public function down(): void
    {
        Schema::table('rental_contracts', function (Blueprint $table): void {
            $table->bigInteger('deposit_minor')->nullable();
        });

        Schema::table('rental_accruals', function (Blueprint $table): void {
            $table->string('status')->default('PENDING');
        });

        Schema::create('rental_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tax_subject_id')->constrained('tax_subjects')->cascadeOnDelete();
            $table->foreignUuid('rental_contract_id')->constrained('rental_contracts')->cascadeOnDelete();
            $table->foreignUuid('accrual_id')->nullable()->constrained('rental_accruals')->nullOnDelete();
            $table->foreignUuid('transaction_id')->nullable()->constrained('financial_transactions')->nullOnDelete();
            $table->string('transaction_match_status')->default('UNMATCHED');
            $table->bigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->date('paid_at');
            $table->timestampsTz();
        });
    }
};
