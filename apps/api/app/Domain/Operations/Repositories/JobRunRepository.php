<?php

declare(strict_types=1);

namespace App\Domain\Operations\Repositories;

use App\Domain\Operations\Enums\JobRunStatus;
use App\Domain\Operations\Enums\OperationTask;
use App\Domain\Operations\Models\JobRun;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Queries over the run ledger that the overview needs.
 *
 * Listing runs is not here: `App\Domain\Operations\Services\ReadBackgroundActivity` reads them
 * directly, because it has to interleave them with `failed_jobs` before paging and could not use a
 * page of runs on its own.
 */
final readonly class JobRunRepository
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
     * @return array<string, JobRun>
     */
    public function latestPerTask(): array
    {
        /** @var list<JobRun> $runs */
        $runs = JobRun::query()
            ->whereIn('started_at', static function (QueryBuilder $query): void {
                $query->from('job_runs')->selectRaw('max(started_at)')->groupBy('task_key');
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
        return JobRun::query()
            ->where('task_key', $task->value)
            ->where('status', JobRunStatus::RUNNING->value)
            ->exists();
    }
}
