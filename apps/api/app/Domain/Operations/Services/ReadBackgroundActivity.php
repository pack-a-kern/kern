<?php

declare(strict_types=1);

namespace App\Domain\Operations\Services;

use App\Domain\Operations\DataTransferObjects\ActivityEntry;
use App\Domain\Operations\DataTransferObjects\FailedJobSummary;
use App\Domain\Operations\Enums\JobRunStatus;
use App\Domain\Operations\Models\JobRun;
use App\Domain\Operations\Repositories\FailedJobRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * The two background-work records, merged into one feed.
 *
 * `job_runs` holds every execution of an operation task, in every outcome. `failed_jobs` is
 * Laravel's own table and holds any queued job in the application that threw and exhausted its
 * retries — document scanning and extraction, not just maintenance commands. The two never overlap:
 * `RunOperationTask` catches its own failures and records them as a `FAILED` run rather than
 * rethrowing, so an operation task never reaches `failed_jobs`.
 *
 * Merged in PHP rather than by a SQL `UNION`. A union of these two tables needs per-engine casts
 * (`job_runs.id` is a `uuid` in Postgres and text in the sqlite the suite runs on) and would have to
 * parse `failed_jobs.payload` in SQL to get a job's name, on a column that is plain text and can be
 * malformed. Both sources are small enough that neither is worth it.
 *
 * The cost of merging in PHP is that paging is bounded: the feed covers the most recent
 * `operations.activity.window` runs plus the failures `FailedJobRepository` already caps. That is a
 * real limit rather than a hidden one — the response reports the total it actually paged over, so
 * the screen never claims to show more than it looked at.
 */
final readonly class ReadBackgroundActivity
{
    public function __construct(
        private FailedJobRepository $failedJobs,
        private ConfigRepository $config,
    ) {
    }

    /**
     * @param  string  $status  One of `all`, `failed`, `succeeded`, `running`.
     * @param  string  $kind  One of `all`, `task_run`, `failed_job`.
     * @return array{entries: list<ActivityEntry>, total: int, page: int, pages: int}
     */
    public function page(string $status, string $kind, int $page): array
    {
        $entries = [];

        if ($kind !== ActivityEntry::KIND_FAILED_JOB) {
            $entries = $this->taskRuns();
        }

        if ($kind !== ActivityEntry::KIND_TASK_RUN) {
            $entries = [...$entries, ...$this->failedJobs()];
        }

        $entries = array_values(array_filter(
            $entries,
            static fn (ActivityEntry $entry): bool => match ($status) {
                'failed' => $entry->status === JobRunStatus::FAILED->value,
                'succeeded' => $entry->status === JobRunStatus::SUCCEEDED->value,
                'running' => $entry->status === JobRunStatus::RUNNING->value,
                default => true,
            },
        ));

        // Newest first. `occurred_at` is ISO-8601 with a fixed offset on both sides, so a string
        // comparison orders it correctly without re-parsing every row.
        usort($entries, static fn (ActivityEntry $a, ActivityEntry $b): int => $b->occurredAt <=> $a->occurredAt);

        $perPage = $this->intConfig('operations.runs.page_size', 20);
        $total = count($entries);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));

        return [
            'entries' => array_slice($entries, ($page - 1) * $perPage, $perPage),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ];
    }

    /**
     * @return list<ActivityEntry>
     */
    private function taskRuns(): array
    {
        /** @var list<JobRun> $runs */
        $runs = JobRun::query()
            ->orderByDesc('started_at')
            ->limit($this->intConfig('operations.activity.window', 200))
            ->get()
            ->all();

        return array_map(
            static fn (JobRun $run): ActivityEntry => new ActivityEntry(
                kind: ActivityEntry::KIND_TASK_RUN,
                id: $run->id,
                label: $run->task_key,
                occurredAt: $run->started_at->toIso8601String(),
                status: $run->status->value,
                trigger: $run->trigger->value,
                durationSeconds: $run->durationSeconds(),
                exitCode: $run->exit_code,
                output: $run->output,
            ),
            $runs,
        );
    }

    /**
     * @return list<ActivityEntry>
     */
    private function failedJobs(): array
    {
        return array_map(
            static fn (FailedJobSummary $job): ActivityEntry => new ActivityEntry(
                kind: ActivityEntry::KIND_FAILED_JOB,
                id: $job->uuid,
                label: $job->jobName,
                occurredAt: $job->failedAt,
                // Laravel's table only ever holds failures, so there is no status to read — the
                // table's existence is the status.
                status: JobRunStatus::FAILED->value,
                queue: $job->queue,
                connection: $job->connection,
                exception: $job->exception,
            ),
            $this->failedJobs->recent(),
        );
    }

    private function intConfig(string $key, int $fallback): int
    {
        $value = $this->config->get($key);

        return is_int($value) && $value > 0 ? $value : $fallback;
    }
}
