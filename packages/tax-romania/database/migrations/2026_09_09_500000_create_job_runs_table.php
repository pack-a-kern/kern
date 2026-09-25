<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per execution of a background operation task — the BNR import, an FX backfill, a
 * legislation reindex, an audit-chain verification (`Kern\TaxRomania\Operations\Enums\OperationTask`).
 *
 * This table is what makes `/admin/operatiuni` truthful rather than decorative. Without it a
 * "Run now" button can only ever report *dispatched*: the work happens on a queue worker, in a
 * different process, minutes later, and nothing in Laravel records the outcome of an artisan
 * command. Horizon knows a *job* ran; it does not know that the import found 0 new rates.
 *
 * Written by both triggers, deliberately — the scheduler's runs and an operator's runs land in the
 * same ledger, because "did the nightly import actually work" and "did my manual retry work" are
 * the same question asked twice.
 *
 * `output` holds the command's console output, truncated to `config('operations.runs.
 * max_output_bytes')`. It is an operator diagnostic, not a log store, and the commands it captures
 * report counts and endpoints — never document contents or taxpayer data (`BLUEPRINT.md` §4.10,
 * §16). `arguments` is likewise bounded: only the typed arguments an `OperationTask` declares can
 * ever reach it, validated against that declaration before dispatch.
 *
 * `triggered_by` is a plain reference to `users.id`, `nullOnDelete` rather than `restrict`: unlike
 * `audit_events.actor_id` this is a convenience column on an operational log, not the evidentiary
 * record — the audit chain independently records who started each manual run.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('job_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // The `OperationTask` case, e.g. `fx.import_daily`. A string rather than a foreign key:
            // the set of tasks is a code-level enum, and a run of a task later removed from the
            // enum must remain readable in the history rather than orphan or cascade away.
            $table->string('task_key', 64);
            $table->string('trigger', 16);
            $table->string('status', 16);

            $table->uuid('triggered_by')->nullable();
            $table->foreign('triggered_by')->references('id')->on('users')->nullOnDelete();

            $table->jsonb('arguments')->nullable();

            // Precision 6, for the same reason `settings.updated_at` carries it: the history is
            // ordered by `started_at`, and two runs dispatched in the same second would otherwise
            // be indistinguishable, silently picking an arbitrary order.
            $table->timestampTz('started_at', 6);
            $table->timestampTz('finished_at', 6)->nullable();

            $table->smallInteger('exit_code')->nullable();
            $table->text('output')->nullable();

            $table->index(['task_key', 'started_at']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_runs');
    }
};
