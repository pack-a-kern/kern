<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives every table of data fetched from outside one prefix, `ext_`, and folds `fx_sources` into
 * `official_sources`.
 *
 * ## Why the prefix
 *
 * These tables hold what official publishers say — BNR's rates, the law's text, the ECB's banks —
 * and nothing a taxpayer typed. Reading `ext_fx_rates` beside `deposit_terms` tells a person which
 * side of that line a table is on without opening it.
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
        'official_sources' => 'ext_official_sources',
        'source_documents' => 'ext_source_documents',
        'source_document_versions' => 'ext_source_document_versions',
        'source_sections' => 'ext_source_sections',
        'source_section_chunks' => 'ext_source_section_chunks',
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
        Schema::table('official_sources', function (Blueprint $table): void {
            $table->unsignedSmallInteger('priority')->default(1);
        });

        DB::statement('UPDATE official_sources o SET priority = f.priority FROM fx_sources f WHERE f.official_source_id = o.id');

        foreach (self::FX_TABLES as $name => $index) {
            $this->repoint($name, $index, from: 'fx_source_id', to: 'official_source_id', references: 'official_sources', mapping:
                "UPDATE {$name} t SET official_source_id = f.official_source_id FROM fx_sources f WHERE f.id = t.fx_source_id");
        }

        Schema::drop('fx_sources');

        foreach (self::RENAMES as $from => $to) {
            Schema::rename($from, $to);
            $this->renameOwnedNames($to, $from, $to);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::RENAMES, true) as $from => $to) {
            Schema::rename($to, $from);
            $this->renameOwnedNames($from, $to, $from);
        }

        Schema::create('fx_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('official_source_id')->constrained('official_sources');
            $table->string('code')->unique();
            $table->unsignedSmallInteger('priority')->default(1);

            $table->timestampsTz();
        });

        // One FX source per official source that any rate, average or anomaly points at.
        DB::statement(<<<'SQL'
            INSERT INTO fx_sources (id, official_source_id, code, priority, created_at, updated_at)
            SELECT gen_random_uuid(), o.id, o.code, o.priority, now(), now()
            FROM official_sources o
            WHERE EXISTS (SELECT 1 FROM fx_rates r WHERE r.official_source_id = o.id)
               OR EXISTS (SELECT 1 FROM fx_period_averages a WHERE a.official_source_id = o.id)
               OR EXISTS (SELECT 1 FROM fx_rate_anomalies n WHERE n.official_source_id = o.id)
            SQL);

        foreach (self::FX_TABLES as $name => $index) {
            $this->repoint($name, $index, from: 'official_source_id', to: 'fx_source_id', references: 'fx_sources', mapping:
                "UPDATE {$name} t SET fx_source_id = f.id FROM fx_sources f WHERE f.official_source_id = t.official_source_id");
        }

        Schema::table('official_sources', function (Blueprint $table): void {
            $table->dropColumn('priority');
        });
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

        DB::statement(sprintf('ALTER TABLE %s ALTER COLUMN %s SET NOT NULL', $table, $to));

        Schema::table($table, function (Blueprint $blueprint) use ($index, $to, $references): void {
            $blueprint->foreign($to)->references('id')->on($references);
            $columns = [$to, ...$index['columns']];
            $index['unique'] ? $blueprint->unique($columns, $index['name']) : $blueprint->index($columns, $index['name']);
        });
    }

    /**
     * Renames the constraints and indexes on `$table` whose names begin with `$oldPrefix`.
     *
     * Constraints first: renaming a primary key or unique constraint also renames the index behind
     * it, so the index pass then finds only the plain indexes left.
     */
    private function renameOwnedNames(string $table, string $oldPrefix, string $newPrefix): void
    {
        $like = str_replace('_', '\_', $oldPrefix) . '\_%';

        $constraints = DB::select('SELECT conname AS name FROM pg_constraint WHERE conrelid = ?::regclass AND conname LIKE ?', [$table, $like]);

        foreach ($constraints as $constraint) {
            DB::statement(sprintf(
                'ALTER TABLE "%s" RENAME CONSTRAINT "%s" TO "%s"',
                $table,
                $constraint->name,
                $newPrefix . substr($constraint->name, strlen($oldPrefix)),
            ));
        }

        $indexes = DB::select(
            'SELECT indexname AS name FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexname LIKE ?',
            [$table, $like],
        );

        foreach ($indexes as $index) {
            DB::statement(sprintf('ALTER INDEX "%s" RENAME TO "%s"', $index->name, $newPrefix . substr($index->name, strlen($oldPrefix))));
        }
    }
};
