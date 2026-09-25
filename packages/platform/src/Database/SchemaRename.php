<?php

declare(strict_types=1);

namespace Kern\Platform\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rename a table and everything Postgres named after it, or do nothing if it is already renamed.
 *
 * `ALTER TABLE … RENAME` keeps the data, the foreign keys, the generated columns and the indexes —
 * and keeps their *names*, so a schema ends up with `ext_fx_rates` carrying an index called
 * `fx_rates_identity_unique`, down to Postgres 18's own NOT NULL constraints. A name that still says
 * the old thing is a name somebody will trust.
 *
 * Idempotent on purpose. The same rename can be owned by a package and by an application that
 * installed that package before the package owned it, and neither should have to know whether the
 * other went first.
 */
final class SchemaRename
{
    public static function table(string $from, string $to): void
    {
        if (! Schema::hasTable($from)) {
            return;
        }

        Schema::rename($from, $to);
        self::ownedNames($to, $from, $to);
    }

    /**
     * Renames the constraints and indexes on `$table` whose names begin with `$oldPrefix`.
     *
     * Constraints first: renaming a primary key or unique constraint also renames the index behind
     * it, so the index pass then finds only the plain indexes left.
     */
    private static function ownedNames(string $table, string $oldPrefix, string $newPrefix): void
    {
        $like = str_replace('_', '\_', $oldPrefix) . '\_%';

        /** @var list<object{name: string}> $constraints */
        $constraints = DB::select(
            'SELECT conname AS name FROM pg_constraint WHERE conrelid = ?::regclass AND conname LIKE ?',
            [$table, $like],
        );

        foreach ($constraints as $constraint) {
            DB::statement(sprintf(
                'ALTER TABLE "%s" RENAME CONSTRAINT "%s" TO "%s"',
                $table,
                $constraint->name,
                $newPrefix . substr($constraint->name, strlen($oldPrefix)),
            ));
        }

        /** @var list<object{name: string}> $indexes */
        $indexes = DB::select(
            'SELECT indexname AS name FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexname LIKE ?',
            [$table, $like],
        );

        foreach ($indexes as $index) {
            DB::statement(sprintf(
                'ALTER INDEX "%s" RENAME TO "%s"',
                $index->name,
                $newPrefix . substr($index->name, strlen($oldPrefix)),
            ));
        }
    }
}
