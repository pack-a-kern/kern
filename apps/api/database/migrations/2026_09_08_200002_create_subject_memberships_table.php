<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `subject_memberships` (`BLUEPRINT.md` §5.3) — the only table that says which `User`s may access
 * which `TaxSubject`s, and with what role. Every tenant-scoping decision in this codebase
 * ultimately resolves through this table (`Kern\Platform\Tenancy\TenantContext`).
 *
 * No Eloquent relationship to `Kern\Platform\Identity\Models\User` is defined on the model over this
 * table — only a plain `user_id` column — per `docs/AGENTS.md` §6: a module never imports another
 * module's Eloquent models.
 *
 * A user may be re-invited after a previous membership was revoked, so `(subject_id, user_id)` is
 * not globally unique — but at most one *active* (non-revoked) membership per pair must exist, or
 * `TenantContext::authorizeAndActivate()`'s `first()` lookup would be picking an arbitrary row
 * among several equally "active" ones. Enforced with a Postgres partial unique index (guarded by
 * driver, matching the `audit_events` append-only trigger's precedent) rather than only at the
 * application layer, per `docs/AGENTS.md` §8 ("every business query scoped… automated tests").
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('subject_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('subject_id');
            $table->foreign('subject_id')->references('id')->on('tax_subjects')->cascadeOnDelete();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->enum('role', ['OWNER', 'ADMIN', 'ACCOUNTANT', 'VIEWER']);
            $table->jsonb('permissions_json')->default('{}');

            $table->timestampTz('valid_from');
            $table->timestampTz('revoked_at')->nullable();

            $table->timestampsTz();

            $table->index(['user_id']);
            $table->index(['subject_id', 'revoked_at']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(
                'CREATE UNIQUE INDEX subject_memberships_one_active_per_pair '
                . 'ON subject_memberships (subject_id, user_id) WHERE revoked_at IS NULL',
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP INDEX IF EXISTS subject_memberships_one_active_per_pair');
        }

        Schema::dropIfExists('subject_memberships');
    }
};
