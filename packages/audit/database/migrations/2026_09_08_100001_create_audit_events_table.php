<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only, hash-chained audit trail (`BLUEPRINT.md` §4.8).
 *
 * Columns match §4.8's schema exactly (`id, sequence, subject_id, actor_id, event_type,
 * resource_type, resource_id, occurred_at, payload_hash, previous_hash, event_hash,
 * signature_key_version`) with one addition: `chain_key`. §4.8 does not name it, but implies one
 * chain per subject — this task's brief (`docs/handoff/006-audit-chain.md`) requires "per-subject
 * chains, not one global chain, so writes parallelise", which needs an explicit scoping key. See
 * `Kern\Audit\Services\AuditChainService` for exactly how it is derived.
 *
 * `subject_id`/`actor_id` carry no foreign key: the Identity/Subjects modules' tables do not exist
 * yet at this milestone (this task's scope note). Add the constraints in a follow-up migration
 * once `users`/`tax_subjects` exist — nothing here depends on them being absent.
 *
 * ## Append-only enforcement
 *
 * `docs/handoff/006-audit-chain.md` §3 requires this "at the database level, not merely by
 * convention". A `BEFORE UPDATE OR DELETE` trigger (not a rewrite `RULE`, which would make the
 * mutation a *silent* no-op instead of an observable failure) raises an exception for either
 * operation. This only runs on Postgres — the migration is a no-op past table creation on any
 * other driver (guarded by `DB::getDriverName()`), because the sqlite in-memory connection used by
 * most of this application's Feature tests (`phpunit.xml`) cannot create a PL/pgSQL trigger
 * function, and this table must still exist there for any *other* test that happens to run every
 * migration. Anything that actually exercises the trigger runs against real Postgres — see the
 * "How to verify" section of this task's handoff note.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('chain_key');
            $table->unsignedBigInteger('sequence');

            $table->uuid('subject_id')->nullable()->index();
            $table->uuid('actor_id')->nullable()->index();

            $table->string('event_type')->index();
            $table->string('resource_type');
            $table->string('resource_id')->nullable();

            // Precision 6 (microseconds) is mandatory, not cosmetic: Kern\Audit\Support\
            // AuditEventHasher formats occurred_at with microsecond precision as part of the
            // hashed payload. Blueprint::timestampTz() defaults to precision 0 (whole seconds) —
            // leaving that default would silently truncate the stored value below what was
            // hashed at write time, making every recomputed hash mismatch on read.
            $table->timestampTz('occurred_at', precision: 6);

            $table->char('payload_hash', 64);
            $table->char('previous_hash', 64);
            $table->char('event_hash', 64);

            // Populated later, out of band, by the (not yet built) periodic Merkle-checkpoint
            // job — see Kern\Audit\Services\AuditChainService's docblock. Never written by
            // AuditChainService::record() itself, and (being append-only) never written after
            // insert either; a real checkpoint job writes it as an INSERT-time value on rows it
            // creates for a batch, not a retroactive UPDATE to this table.
            $table->unsignedSmallInteger('signature_key_version')->nullable();

            $table->unique(['chain_key', 'sequence']);
            $table->index(['resource_type', 'resource_id']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                -- CREATE OR REPLACE, not CREATE: `migrate:fresh` drops TABLES but not
                -- FUNCTIONS, and it never calls down(). A bare CREATE therefore fails with
                -- SQLSTATE[42723] "function already exists" on the second migration run against
                -- the same Postgres database — i.e. on every test after the first.
                CREATE OR REPLACE FUNCTION audit_events_deny_mutation() RETURNS trigger AS $trigger$
                BEGIN
                    RAISE EXCEPTION
                        'audit_events is append-only: % is not permitted (id=%)',
                        TG_OP,
                        OLD.id;
                END;
                $trigger$ LANGUAGE plpgsql;

                CREATE TRIGGER audit_events_no_update
                    BEFORE UPDATE ON audit_events
                    FOR EACH ROW EXECUTE FUNCTION audit_events_deny_mutation();

                CREATE TRIGGER audit_events_no_delete
                    BEFORE DELETE ON audit_events
                    FOR EACH ROW EXECUTE FUNCTION audit_events_deny_mutation();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS audit_events_no_update ON audit_events;
                DROP TRIGGER IF EXISTS audit_events_no_delete ON audit_events;
                DROP FUNCTION IF EXISTS audit_events_deny_mutation();
                SQL);
        }

        Schema::dropIfExists('audit_events');
    }
};
