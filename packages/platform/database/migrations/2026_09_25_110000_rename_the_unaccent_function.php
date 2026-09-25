<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `taxos_unaccent()` becomes `kern_unaccent()`.
 *
 * The function is the immutable wrapper the legislation indexes are built on (`2026_09_08_301100`),
 * and it carries the old vendor name — which now belongs to the product rather than to the platform
 * (ADR 0016). Renamed rather than recreated: an index references a function by identity, not by
 * name, so `ALTER FUNCTION` leaves every index and generated column intact and nothing is rebuilt.
 *
 * The migrations that created it and used it keep their own names and bodies. They ran; rewriting
 * history to spell a name differently would make a migrated database and a fresh one disagree about
 * what happened, and on a fresh one this rename simply follows them a few files later.
 */
return new class () extends Migration {
    public function up(): void
    {
        /*
         * A function outlives the tables that used it.
         *
         * `migrate:fresh` drops every table — and with them every index built on this function —
         * but functions are not tables and stay where they are. So a second run of the suite finds
         * both names already present: the old one recreated a few migrations ago, the new one left
         * behind by the run before. The leftover is dropped rather than renamed around, and plainly
         * rather than with `CASCADE`, so that anything still depending on it says so instead of
         * being quietly taken with it.
         */
        // Postgres only, like the migration that creates this function and the indexes built on it:
        // sqlite has no functions to rename, so there is nothing here for it to do.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP FUNCTION IF EXISTS kern_unaccent(text)');
        DB::statement('ALTER FUNCTION taxos_unaccent(text) RENAME TO kern_unaccent');
    }

    public function down(): void
    {
        DB::statement('ALTER FUNCTION kern_unaccent(text) RENAME TO taxos_unaccent');
    }
};
