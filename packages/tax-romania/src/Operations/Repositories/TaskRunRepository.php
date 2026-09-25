<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Operations\Repositories;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Kern\TaxRomania\Operations\Enums\OperationTask;
use Kern\TaxRomania\Operations\Enums\TaskRunStatus;
use Kern\TaxRomania\Operations\Models\TaskRun;

/**
 * Queries over the run ledger that the overview needs.
 *
 * Listing runs is not here: `Kern\TaxRomania\Http\Controllers\Operations\ShowTaskRunsController` pages
 * one task's history directly, and a shared method would have to take a page number and a task key
 * only to hand back what that controller already builds.
 */
final readonly class TaskRunRepository
{
    /**
     * The most recent run of each task, for the task list.
     *
     * One query rather than one per task, and written portably rather than with Postgres's
     * `DISTINCT ON`: the test suite runs against sqlite in memory (`make check-php`), and a query
     * only one engine understands would make this endpoint untestable there.
     *
     * Ordered ascending on purpose. `started_at` has microsecond precision, so a collision between
     * two tasks' maxima is already improbable; ascending order makes it harmless as well, because
     * a later row for the same task overwrites an earlier one in the map.
     *
     * @return array<string, TaskRun>
     */
    public function latestPerTask(): array
    {
        /** @var list<TaskRun> $runs */
        $runs = TaskRun::query()
            ->whereIn('started_at', static function (QueryBuilder $query): void {
                $query->from('task_runs')->selectRaw('max(started_at)')->groupBy('task_key');
            })
            ->orderBy('started_at')
            ->get()
            ->all();

        $byTask = [];

        foreach ($runs as $run) {
            $byTask[$run->task_key] = $run;
        }

        return $byTask;
    }

    /** Whether a run of this task is currently open — a lock-independent second opinion for the UI. */
    public function isRunning(OperationTask $task): bool
    {
        return TaskRun::query()
            ->where('task_key', $task->value)
            ->where('status', TaskRunStatus::RUNNING->value)
            ->exists();
    }
}
