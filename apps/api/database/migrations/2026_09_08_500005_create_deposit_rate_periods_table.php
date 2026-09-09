<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `deposit_rate_periods` (`BLUEPRINT.md` §6.4). `tax_subject_id` added beyond the literal column
 * list — same tenancy reasoning as `account_balance_snapshots`.
 *
 * `annual_rate_decimal` is `string`, not `decimal`/`NUMERIC` — the identical, empirically-verified
 * reason `App\Domain\Fx`'s rate columns are strings
 * (`docs/handoff/014-source-registry-bnr.md` "Key decisions" #4,
 * `docs/handoff/016-postgres-suite-stabilisation.md`): SQLite's `NUMERIC` affinity silently
 * round-trips through a lossy PHP float, and `App\Domain\Wallet\ValueObjects\Decimal` is this
 * module's own BCMath-exact reader/writer for it — see that class's docblock for why it is not
 * simply `App\Domain\Fx\ValueObjects\DecimalRate` reused.
 *
 * ⚠ Same consequence as `Domain/Fx`'s rate columns: never `orderBy`/range-compare/aggregate
 * `annual_rate_decimal` in SQL — it compares lexicographically as text. Filter by `valid_from`/
 * `valid_to` (properly typed dates), load, and compare via `Decimal`.
 *
 * `valid_to` nullable = "this rate is still in effect" (an open-ended, current period) — the same
 * convention `fiscal_parameter_versions.valid_until` uses elsewhere in this codebase.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('deposit_rate_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tax_subject_id');
            $table->foreign('tax_subject_id')->references('id')->on('tax_subjects')->cascadeOnDelete();

            $table->uuid('deposit_id');
            $table->foreign('deposit_id')->references('id')->on('deposits')->cascadeOnDelete();

            $table->date('valid_from');
            $table->date('valid_to')->nullable();

            $table->string('annual_rate_decimal', 32);

            $table->index('tax_subject_id');
            $table->index(['deposit_id', 'valid_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_rate_periods');
    }
};
