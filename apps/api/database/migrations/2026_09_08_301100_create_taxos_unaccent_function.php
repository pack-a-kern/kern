<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Milestone 11 (`docs/handoff/029-hybrid-retrieval.md`) — the first migration in this codebase to
 * need `vector`/`unaccent`/`pg_trgm`. Two things this migration does, both pgsql-only (guarded by
 * driver check, same precedent as `2026_09_08_100001_create_audit_events_table.php`'s trigger and
 * `2026_09_08_200002_create_subject_memberships_table.php`'s partial index):
 *
 * 1. **`CREATE EXTENSION IF NOT EXISTS`** for all three. `docker/postgres/init/001-extensions.sql`
 *    already creates them on the compose-managed dev database, but that init script runs only
 *    once, against the database named by `POSTGRES_DB`, on first volume init — it does **not**
 *    run for a database created afterward (a fresh `CREATE DATABASE` for an isolated test run,
 *    `docs/AGENTS.md` §7b) and does **not** run at all in CI, where `.github/workflows/ci.yml`'s
 *    `postgres:` service starts the `pgvector/pgvector:pg18-trixie` image directly with no init
 *    script mount. Without this migration creating them itself, idempotently, CI's `taxos`
 *    database would never have `vector`/`unaccent`/`pg_trgm` and every migration after this one
 *    would fail there — this was a latent gap in the existing CI/init setup that nothing hit until
 *    this task became the first consumer of these extensions.
 * 2. **`taxos_unaccent(text) RETURNS text`** — a thin, `IMMUTABLE` SQL-callable wrapper around
 *    contrib `unaccent()`. `unaccent()` itself is `STABLE`, not `IMMUTABLE` (`SELECT provolatile
 *    FROM pg_proc WHERE proname = 'unaccent'` on this project's own `pgvector/pgvector:pg18-trixie`
 *    image returns `s`) — confirmed empirically, not assumed — which Postgres refuses inside a
 *    generated column expression or a functional index (`ERROR: generation expression is not
 *    immutable`). Wrapping it as `IMMUTABLE` is the standard, widely-used pattern for combining
 *    `unaccent` with generated tsvector columns/trigram indexes; it is safe here specifically
 *    because this project pins one fixed dictionary (`unaccent`) that never changes at runtime.
 *
 *    **Two non-obvious things this wrapper needed, both found by actually running `CREATE INDEX`
 *    against real Postgres, not assumed from documentation:**
 *    - It must be `LANGUAGE plpgsql`, not `LANGUAGE sql`. A `LANGUAGE sql` function gets
 *      *inlined* by the planner when used inside `CREATE INDEX ... (expr)`, and inlining
 *      re-resolves `'unaccent'::regdictionary` in a context that does not find the schema-`public`
 *      dictionary (`ERROR: text search dictionary "unaccent" does not exist`) even though the
 *      exact same expression works fine in an ordinary `SELECT`. `plpgsql` is never inlined, which
 *      sidesteps this entirely.
 *    - Both the dictionary argument *and* the `unaccent()` function call itself must be
 *      schema-qualified (`public.unaccent('public.unaccent'::regdictionary, input)`) —
 *      `CREATE INDEX`'s own immutability validation evaluates the expression under a search path
 *      that does not include `public`, so an unqualified call to either resolves to nothing
 *      (`ERROR: function unaccent(regdictionary, text) does not exist`) even though `unaccent`
 *      itself lives in, and is normally found via, `public`.
 *
 *    Empirically verified (against this project's own `taxos_rag` throwaway database, 2026-09-08):
 *    both the comma-below Romanian letters (ș U+0219, ț U+021B) and the legacy cedilla forms
 *    (ş U+015F, ţ U+0163) already fold correctly to plain `s`/`t` through contrib `unaccent`'s
 *    default dictionary — `SELECT unaccent('unaccent', chr(537))` → `s`, `chr(351)` → `s`,
 *    `chr(539)` → `t`, `chr(355)` → `t` — so this wrapper does not need its own `translate()`
 *    table for the two encodings; it only needs to be `IMMUTABLE`-safe to call from an index.
 *
 * Used by: `2026_09_08_301101_create_source_section_chunks_table.php` (generated `text_search`
 * column) and `2026_09_08_301102_add_citation_trigram_index_to_source_sections_table.php`
 * (trigram index on `citation_label`), and by every query
 * `App\Domain\TaxKnowledge\Retrieval\Query\HybridLegislationSearch` issues.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('CREATE EXTENSION IF NOT EXISTS vector');
        DB::unprepared('CREATE EXTENSION IF NOT EXISTS unaccent');
        DB::unprepared('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION taxos_unaccent(input text) RETURNS text
                LANGUAGE plpgsql IMMUTABLE PARALLEL SAFE STRICT AS
            $$
            BEGIN
                RETURN public.unaccent('public.unaccent'::regdictionary, input);
            END;
            $$;
            SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Extensions are deliberately NOT dropped here: other objects (e.g. a future migration,
        // or manually-created indexes) may depend on them, and DROP EXTENSION would cascade.
        // Only this migration's own function is this migration's to remove.
        DB::unprepared('DROP FUNCTION IF EXISTS taxos_unaccent(text)');
    }
};
