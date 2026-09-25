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
        DB::statement('ALTER FUNCTION taxos_unaccent(text) RENAME TO kern_unaccent');
    }

    public function down(): void
    {
        DB::statement('ALTER FUNCTION kern_unaccent(text) RENAME TO taxos_unaccent');
    }
};
