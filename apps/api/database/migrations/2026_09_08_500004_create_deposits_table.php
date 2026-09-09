<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `deposits` (`BLUEPRINT.md` §6.4). `institution_id` rendered as a plain `institution_name` string —
 * same reasoning as `financial_accounts.institution_name`'s migration docblock.
 *
 * `principal_minor` has no database-level `CHECK (principal_minor > 0)` constraint — skipped for
 * the same portability reason `docs/handoff/014-source-registry-bnr.md`'s "Open questions"
 * recorded for `Domain/Fx` (SQLite's `CHECK` constraint support via Laravel's schema builder is
 * inconsistent, and every write path in this codebase goes through application code, never a bulk
 * import that bypasses it). `App\Domain\Wallet\Actions\OpenDeposit` validates positivity instead.
 *
 * `rate_type`/`interest_method`/`payment_frequency`/`day_count_convention` are all explicit,
 * required columns — `BLUEPRINT.md` §6.4 requires a day-count convention "explicit per deposit", and
 * `App\Domain\Wallet\Services\Interest\DepositInterestCalculator` never defaults any of these.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('deposits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tax_subject_id');
            $table->foreign('tax_subject_id')->references('id')->on('tax_subjects')->cascadeOnDelete();

            $table->uuid('funding_account_id')->nullable();
            $table->foreign('funding_account_id')->references('id')->on('financial_accounts')->nullOnDelete();

            $table->string('institution_name')->nullable();

            $table->bigInteger('principal_minor');
            $table->char('currency_code', 3);

            $table->date('opened_at');
            $table->date('maturity_at');

            $table->string('rate_type');
            $table->string('interest_method');
            $table->string('payment_frequency');
            $table->string('day_count_convention');

            $table->boolean('auto_renewal')->default(false);

            $table->text('metadata_ciphertext')->nullable();

            $table->timestampsTz();

            $table->index('tax_subject_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposits');
    }
};
