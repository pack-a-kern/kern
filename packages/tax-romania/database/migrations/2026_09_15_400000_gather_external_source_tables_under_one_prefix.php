<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kern\Platform\Database\SchemaRename;

/**
 * Folds `fx_sources` into the official source itself, and gives the exchange-rate tables the same
 * `ext_` prefix the legislation ones take.
 *
 * ## Why this one is here
 *
 * It needs two packages at once: `fx_sources` is this one's and `official_sources` is
 * `kern/legislation`'s, and folding one into the other can only be done by something that has both.
 * This package requires that one, so this is that something — it sat in the application until an
 * installation of this package on its own found `ext_fx_rates` missing, because the renaming had been
 * left behind. The legislation half of it lives in
 * `kern/legislation`'s `2026_09_15_399000_prefix_the_legislation_tables`, which is why the source
 * tables are already named `ext_official_sources` by the time this runs.
 *
 * ## Why `fx_sources` goes
 *
 * It held one row, BNR, repeating `official_sources`' code and adding a single column, `priority`.
 * Every rate pointed at it only to reach the official source behind it. So the priority moves onto
 * the official source, the three FX tables point straight at it, and one link in the provenance
 * chain disappears: `rate → official source`, not `rate → fx source → official source`.
 *
 * ## What a rename keeps
 *
 * Postgres keeps the data, foreign keys, the full-text column and the embedding index through
 * `ALTER TABLE … RENAME`, and no function, trigger or view names these tables. What it does not
 * rename is the constraints and indexes named after the old table, down to Postgres 18's own NOT
 * NULL constraints; those are renamed here too, so no name in the schema still says `fx_rates`.
 */
return new class () extends Migration {
    /** Old name => new name. */
    private const array RENAMES = [
        'fx_rates' => 'ext_fx_rates',
        'fx_period_averages' => 'ext_fx_period_averages',
        'fx_rate_anomalies' => 'ext_fx_rate_anomalies',
    ];

    /**
     * The tables that pointed at `fx_sources`, and the index each built on that column — rebuilt on
     * `official_source_id` in the same position, so a rate stays unique per source, date and currency.
     *
     * @var array<string, array{unique: bool, name: string, columns: list<string>}>
     */
    private const array FX_TABLES = [
        'fx_rates' => ['unique' => true, 'name' => 'fx_rates_identity_unique', 'columns' => ['rate_date', 'currency_code']],
        'fx_period_averages' => [
            'unique' => true,
            'name' => 'fx_period_averages_identity_unique',
            'columns' => ['period_type', 'period_start', 'currency_code'],
        ],
        'fx_rate_anomalies' => ['unique' => false, 'name' => 'fx_rate_anomalies_lookup', 'columns' => ['rate_date', 'currency_code']],
    ];

    public function up(): void
    {
        // The column itself is `kern/legislation`'s — its repository writes it on every fetch, so a
        // bare installation of that package needs it too. All that is left here is moving the
        // exchange-rate module's own priority onto the source it was describing.
        // No alias on the table being updated, in this statement and the two mappings below:
        // Postgres allows one, sqlite does not, and the suite's in-memory database is sqlite. It cost
        // the whole suite — the migration threw, so every test that migrates from nothing failed.
        // `UPDATE ... FROM` itself is understood by both.
        DB::statement('UPDATE ext_official_sources SET priority = f.priority FROM fx_sources f WHERE f.official_source_id = ext_official_sources.id');

        foreach (self::FX_TABLES as $name => $index) {
            $this->repoint($name, $index, from: 'fx_source_id', to: 'official_source_id', references: 'ext_official_sources', mapping:
                "UPDATE {$name} SET official_source_id = f.official_source_id FROM fx_sources f WHERE f.id = {$name}.fx_source_id");
        }

        Schema::drop('fx_sources');

        foreach (self::RENAMES as $from => $to) {
            SchemaRename::table($from, $to);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::RENAMES, true) as $from => $to) {
            SchemaRename::table($to, $from);
        }

        Schema::create('fx_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('official_source_id')->constrained('ext_official_sources');
            $table->string('code')->unique();
            $table->unsignedSmallInteger('priority')->default(1);

            $table->timestampsTz();
        });

        // One FX source per official source that any rate, average or anomaly points at.
        DB::statement(<<<'SQL'
            INSERT INTO fx_sources (id, official_source_id, code, priority, created_at, updated_at)
            SELECT gen_random_uuid(), o.id, o.code, o.priority, now(), now()
            FROM ext_official_sources o
            WHERE EXISTS (SELECT 1 FROM fx_rates r WHERE r.official_source_id = o.id)
               OR EXISTS (SELECT 1 FROM fx_period_averages a WHERE a.official_source_id = o.id)
               OR EXISTS (SELECT 1 FROM fx_rate_anomalies n WHERE n.official_source_id = o.id)
            SQL);

        foreach (self::FX_TABLES as $name => $index) {
            $this->repoint($name, $index, from: 'official_source_id', to: 'fx_source_id', references: 'fx_sources', mapping:
                "UPDATE {$name} SET fx_source_id = f.id FROM fx_sources f WHERE f.official_source_id = {$name}.official_source_id");
        }

    }

    /**
     * Moves one FX table's source reference from one column to another: add the new column, fill it,
     * drop the old column with its key and index, then make the new one required, keyed and indexed.
     *
     * @param  array{unique: bool, name: string, columns: list<string>}  $index
     */
    private function repoint(string $table, array $index, string $from, string $to, string $references, string $mapping): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($to): void {
            $blueprint->uuid($to)->nullable();
        });

        DB::statement($mapping);

        Schema::table($table, function (Blueprint $blueprint) use ($index, $from): void {
            $index['unique'] ? $blueprint->dropUnique($index['name']) : $blueprint->dropIndex($index['name']);
            $blueprint->dropForeign([$from]);
            $blueprint->dropColumn($from);
        });

        // Through the Blueprint, not `ALTER COLUMN ... SET NOT NULL`, which is Postgres's spelling:
        // sqlite has no such statement and rebuilds the table instead. Restating the column in full
        // is what `change()` requires — anything left out of it would be dropped.
        Schema::table($table, function (Blueprint $blueprint) use ($to): void {
            $blueprint->uuid($to)->nullable(false)->change();
        });

        Schema::table($table, function (Blueprint $blueprint) use ($index, $to, $references): void {
            $blueprint->foreign($to)->references('id')->on($references);
            $columns = [$to, ...$index['columns']];
            $index['unique'] ? $blueprint->unique($columns, $index['name']) : $blueprint->index($columns, $index['name']);
        });
    }
};
