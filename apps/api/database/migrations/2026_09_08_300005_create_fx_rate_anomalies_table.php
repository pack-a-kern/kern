<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `fx_rate_anomalies` — not named in `BLUEPRINT.md` §6.9's literal column list, but required by its
 * own narrative text (§7.2 step 9: "dacă valoarea pentru aceeași zi se modifică, create anomaly,
 * nu overwrite silent") and by the task brief's explicit, testable requirement: a changed
 * historical `fx_rates` value must produce an anomaly record, never a silent overwrite. `BLUEPRINT.md`
 * names the *behaviour* without naming a table for it; this is the natural, minimal-scope table
 * that behaviour needs, owned by this same bounded context (`Domain/Fx`).
 *
 * Append-only, like `fx_rates` itself. No `resolved_at`/`resolution` column: nothing in this
 * milestone ever sets one (no resolution workflow was asked for), and an unused column is exactly
 * the "speculative scaffolding" `docs/CODE-QUALITY.md` §2 forbids — a future agent building a
 * resolution workflow adds it in its own migration when it exists to populate it.
 *
 * The rate columns are `string`, not `decimal(20,6)`/`NUMERIC` — see
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
        Schema::create('fx_rate_anomalies', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('fx_source_id')->constrained('fx_sources');
            $table->date('rate_date');
            $table->char('currency_code', 3);

            $table->unsignedInteger('existing_multiplier');
            $table->string('existing_rate_decimal', 32);

            $table->unsignedInteger('incoming_multiplier');
            $table->string('incoming_rate_decimal', 32);
            $table->foreignUuid('incoming_source_document_version_id')->constrained('source_document_versions');

            $table->timestampTz('detected_at');

            $table->timestampsTz();

            $table->index(['fx_source_id', 'rate_date', 'currency_code'], 'fx_rate_anomalies_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rate_anomalies');
    }
};
