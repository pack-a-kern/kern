<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `spv_sessions` (`BLUEPRINT.md` §6.11) — one row per ephemeral `apps/spv-browser` session
 * (`browser_session_ref`), scoped to the `spv_connections` row it authenticates. No PII: a
 * session record is lifecycle metadata only (`BLUEPRINT.md` §16 — status/progress, never content).
 *
 * `tax_subject_id` is denormalised from `spv_connections.tax_subject_id`, matching every other
 * child table in this codebase (`docs/handoff/025-rental-module.md`'s `rental_accruals`
 * precedent) — needed for `App\Support\Tenancy\ScopedToTenant` without an extra join.
 *
 * No `updated_at`: every transition writes one of the explicit timestamp/state columns below
 * through `App\Domain\Spv\Actions\*`, never an auto-touched "something changed" marker
 * (`declarations`' identical precedent, `docs/handoff/028-d212-declarations.md`).
 *
 * `challenge_requested_at` is a justified addition beyond the blueprint's literal column list:
 * without it, an OTP/safety code's TTL (`BLUEPRINT.md` §13.2 step 9 — "codul are TTL foarte scurt")
 * has nothing to measure from. Set when the session enters `AWAITING_USER_CHALLENGE`.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('spv_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tax_subject_id')->constrained('tax_subjects')->cascadeOnDelete();
            $table->foreignUuid('connection_id')->constrained('spv_connections')->cascadeOnDelete();

            $table->string('browser_session_ref');
            $table->string('state');

            $table->timestampTz('created_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('challenge_requested_at')->nullable();
            $table->timestampTz('destroyed_at')->nullable();

            $table->index('tax_subject_id');
            $table->index('connection_id');
            $table->unique('browser_session_ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spv_sessions');
    }
};
