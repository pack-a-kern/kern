<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `job_runs` becomes `task_runs`.
 *
 * "Job" is the wrong word here and it cost real confusion: a *job* in this application is anything
 * on the queue — document scanning, AI extraction, virus scans — and those failures live in
 * Laravel's own `failed_jobs`. This table holds something narrower and unrelated: one row per
 * execution of an `Kern\TaxRomania\Operations\Enums\OperationTask`, the six artisan commands an operator
 * can run or schedule. Every other name in that chain already says "task" (`OperationTask`,
 * `RunOperationTask`, `scheduled_tasks`); the table was the last thing calling them jobs.
 *
 * Not `scheduled_task_runs`, which was the obvious-looking alternative: only `fx.import_daily` has a
 * cron expression in `config/operations.php`, the other five tasks are manual-only, and every row in
 * the table today was triggered by hand. A name that describes a sixth of the contents is worse than
 * one that describes all of it — `trigger` already says SCHEDULED or MANUAL per row.
 *
 * A rename rather than a create-and-copy, so the history survives: these rows are the only record
 * that last night's import ran at all.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::rename('job_runs', 'task_runs');

        // Postgres carries indexes and constraints across a table rename but keeps their old names,
        // leaving `job_runs_pkey` on a table called `task_runs`. Cosmetic until someone reads the
        // schema to understand it — which is the entire point of this migration.
        $this->renameIndexes();
    }

    public function down(): void
    {
        Schema::rename('task_runs', 'job_runs');
    }

    private function renameIndexes(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'job_runs_pkey' => 'task_runs_pkey',
            'job_runs_started_at_index' => 'task_runs_started_at_index',
            'job_runs_task_key_started_at_index' => 'task_runs_task_key_started_at_index',
        ] as $from => $to) {
            DB::statement(sprintf('ALTER INDEX IF EXISTS %s RENAME TO %s', $from, $to));
        }

        // `RENAME CONSTRAINT` has no `IF EXISTS`, and a database restored from a dump may carry a
        // differently named foreign key, so its presence is checked rather than assumed.
        $exists = DB::selectOne(
            'select 1 from pg_constraint where conname = ?',
            ['job_runs_triggered_by_foreign'],
        );

        if ($exists !== null) {
            DB::statement(
                'ALTER TABLE task_runs RENAME CONSTRAINT job_runs_triggered_by_foreign TO task_runs_triggered_by_foreign',
            );
        }
    }
};
