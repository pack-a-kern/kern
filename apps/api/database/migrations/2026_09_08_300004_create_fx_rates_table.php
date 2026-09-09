<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `fx_rates` (`BLUEPRINT.md` §6.9) — one immutable row per `(fx_source_id, rate_date,
 * currency_code)`. **Never updated by application code once written**
 * (`App\Domain\Fx\Services\FxRateImporter` — BLUEPRINT.md §7.2 step 9: a changed historical value
 * creates an `fx_rate_anomalies` row instead of overwriting this one).
 *
 * `rate_decimal` is the RON amount for exactly `multiplier` units of `currency_code`, stored
 * verbatim as BNR published it — deliberately NOT pre-divided into a "per single unit" number.
 * This is the defence against the multiplier trap the task brief calls out explicitly: some
 * currencies are quoted per 100 (or another) unit, and dividing once at import time then losing
 * track of having done so would silently scale a future conversion by that factor. Every reader
 * (`App\Domain\Fx\Services\FxRateQuery`) divides by `multiplier` itself, every time, from these
 * two columns together — never from a cached quotient.
 *
 * `rate_decimal` is a `string`, not `decimal(20,6)`/`NUMERIC`. This was verified empirically, not
 * assumed: on `pdo_pgsql` a `NUMERIC` column round-trips as an exact PHP string, but on `pdo_sqlite`
 * — this project's test connection, `phpunit.xml` `DB_CONNECTION=sqlite` — a `NUMERIC`-affinity
 * column silently returns a PHP **float** (confirmed with a throwaway PDO script: inserting the
 * string `"123456789012.123456"` into a `NUMERIC` column and reading it back produced
 * `float(123456789012.12346)`, already wrong at the 12th significant digit; the same value in a
 * `TEXT` column round-trips byte-for-byte). Laravel's own SQLite grammar maps `decimal()` to
 * `numeric` (`Illuminate\Database\Schema\Grammars\SQLiteGrammar::typeDecimal()`), so this is not a
 * hypothetical edge case, it is what this exact migration would have done. A `string` column
 * behaves identically — exact, no float, ever — on every engine this project runs, which is a
 * stronger and simpler guarantee than "exact on Postgres, silently wrong in every sqlite-backed
 * test" (`docs/AGENTS.md` rule 6, `docs/CODE-QUALITY.md` §4: no float for money, applied here to
 * exchange rates for the same reason they apply to money). `App\Domain\Fx\Models\FxRate` applies
 * no Eloquent cast to this column for the same reason Laravel's built-in `decimal:N` cast was
 * rejected even before this finding: it formats via `sprintf('%F', ...)`, itself a float
 * round-trip. Every reader parses this column through
 * `App\Domain\Fx\ValueObjects\DecimalRate::fromString()`, which validates the format at read time.
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
        Schema::create('fx_rates', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('fx_source_id')->constrained('fx_sources');
            $table->date('rate_date');
            $table->char('currency_code', 3);
            $table->unsignedInteger('multiplier');
            $table->string('rate_decimal', 32);
            $table->foreignUuid('source_document_version_id')->constrained('source_document_versions');

            $table->timestampsTz();

            $table->unique(['fx_source_id', 'rate_date', 'currency_code'], 'fx_rates_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rates');
    }
};
