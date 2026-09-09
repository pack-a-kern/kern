<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `fx_period_averages` (`BLUEPRINT.md` §6.9/§7.4) — one row per `(fx_source_id, period_type,
 * period_start, currency_code)`.
 *
 * `period_start` is the first calendar day of the period (the 1st of the month for `MONTH`,
 * January 1st for `YEAR`), so both period types sort and index uniformly.
 *
 * Two independent values are always kept side by side, per the task brief and BLUEPRINT.md §7.4
 * ("Păstrăm ambele: calculated_value; official_published_value") — this table never silently
 * prefers one over the other:
 * - `calculated_value_decimal` — the arithmetic mean this codebase computed locally
 *   (`App\Domain\Fx\Services\FxPeriodAverageCalculator`), always present once calculated.
 * - `official_published_value_decimal` — BNR's own published average, nullable because it is
 *   supplied later, out of band (a monthly bulletin), by a process this milestone does not build.
 *
 * Unlike `fx_rates`, this table is **not** append-only: it is a derived/cached computation (always
 * reproducible from `fx_rates`, §7.5), so recomputing and overwriting `calculated_value_decimal`
 * is safe and expected as more of a period's daily rates land. `verification_status` starts
 * `PENDING` (no official value to compare against yet) and only moves to `VERIFIED`/`MISMATCH`
 * once `recordOfficialPublishedValue()` supplies one — see `App\Domain\Fx\Enums\VerificationStatus`.
 * `verification_tolerance_decimal` records the tolerance actually applied at verification time,
 * for reproducibility (`BLUEPRINT.md` §39) even if the configured default later changes.
 *
 * All three decimal-like columns are `string`, not `decimal(20,6)`/`NUMERIC` — see
 * `2026_09_08_300004_create_fx_rates_table.php`'s docblock for the empirically-verified reason
 * (SQLite's `NUMERIC` affinity silently returns a lossy PHP float over PDO; `TEXT` does not).
 *
 * ⚠ CONSEQUENCE FOR CALLERS — these columns are TEXT, so SQL compares them LEXICOGRAPHICALLY.
 * `"10" < "9"` is true as a string. Therefore **never** write `orderBy()`, `where('<'/'>')`,
 * `whereBetween()`, `MIN`/`MAX`/`AVG`/`SUM` against a rate column: the answer will be silently
 * wrong, not an error. Filter and sort by `rate_date`/`currency_code` (which are properly typed),
 * load the rows, and compare or aggregate in PHP through
 * `App\Domain\Fx\ValueObjects\DecimalRate`, which is BCMath-backed and exact. This is the price
 * of exact storage on every engine, and it is deliberate.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('fx_period_averages', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('fx_source_id')->constrained('fx_sources');
            $table->enum('period_type', ['MONTH', 'YEAR']);
            $table->date('period_start');
            $table->char('currency_code', 3);

            $table->string('method');
            $table->string('calculated_value_decimal', 32);
            $table->boolean('calculated_from_daily');

            $table->string('official_published_value_decimal', 32)->nullable();
            $table->string('verification_tolerance_decimal', 32)->nullable();
            $table->enum('verification_status', ['PENDING', 'VERIFIED', 'MISMATCH'])->default('PENDING');

            $table->timestampsTz();

            $table->unique(
                ['fx_source_id', 'period_type', 'period_start', 'currency_code'],
                'fx_period_averages_identity_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_period_averages');
    }
};
