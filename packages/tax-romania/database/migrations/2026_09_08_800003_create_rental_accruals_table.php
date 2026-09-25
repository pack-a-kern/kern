<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `rental_accruals` (`BLUEPRINT.md` §6.5). `tax_subject_id` denormalised, matching every other
 * Wallet/Rental child table.
 *
 * **Justified addition beyond the blueprint's literal column list**: `rental_contract_version_id`.
 * The blueprint's schema names only `rental_contract_id`, but without recording which *version*
 * produced a given accrual there would be no way to prove — the exact requirement this milestone
 * states explicitly — that an accrual generated either side of a mid-period amendment used the
 * correct terms; `Kern\TaxRomania\Rental\Actions\GenerateRentalAccruals`'s own tests assert against
 * this column directly. `restrictOnDelete()`: an accrual must never survive its generating version
 * being deleted out from under it (unlike the `nullOnDelete()` document references above, there is
 * no sensible "unknown version" state for an already-computed accrual).
 *
 * `gross_minor` is the owning tax subject's **share** of the rent (`BLUEPRINT.md` §33, see
 * `Kern\TaxRomania\Rental\Models\RentalAccrual`'s docblock) — never the full contractual rent.
 *
 * Uniqueness on `(rental_contract_id, period_start, period_end)` is what makes
 * `Kern\TaxRomania\Rental\Actions\GenerateRentalAccruals` idempotent, the same idiom
 * `deposit_interest_events`'s unique key establishes for `Kern\TaxRomania\Wallet\Actions\
 * GenerateDepositInterestSchedule`.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('rental_accruals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tax_subject_id');
            $table->foreign('tax_subject_id')->references('id')->on('tax_subjects')->cascadeOnDelete();

            $table->uuid('rental_contract_id');
            $table->foreign('rental_contract_id')->references('id')->on('rental_contracts')->cascadeOnDelete();

            $table->uuid('rental_contract_version_id');
            $table->foreign('rental_contract_version_id')->references('id')->on('rental_contract_versions')->restrictOnDelete();

            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_date');

            $table->bigInteger('gross_minor');
            $table->string('status')->default('PENDING');

            $table->timestampsTz();

            $table->index('tax_subject_id');
            $table->index(['rental_contract_id', 'period_start']);
            $table->unique(['rental_contract_id', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_accruals');
    }
};
