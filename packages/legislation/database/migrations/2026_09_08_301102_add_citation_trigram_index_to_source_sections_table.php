<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fuzzy article/citation lookup (`BLUEPRINT.md` §15.2 — "pg_trgm for fuzzy article lookup ('art. 170
 * alin. 2')") over `source_sections.citation_label`, normalised through
 * `taxos_unaccent(lower(...))` (`2026_09_08_301100_create_taxos_unaccent_function.php`) so
 * "art 170 alin 2" fuzzy-matches "Art. 170 alin. (2)" regardless of case, diacritics, or
 * punctuation. pgsql-only, matching every other pgvector/pg_trgm-specific migration in this task.
 *
 * A plain `CREATE INDEX` on an existing table, not a new column — `source_sections` already
 * exists from `2026_09_08_301001_create_source_sections_table.php` (Milestone 3).
 */
return new class () extends Migration {
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE INDEX source_sections_citation_trgm
                ON source_sections
                USING gin (taxos_unaccent(lower(citation_label)) gin_trgm_ops)
            SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP INDEX IF EXISTS source_sections_citation_trgm');
    }
};
