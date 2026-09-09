<?php

declare(strict_types=1);

namespace App\Domain\Operations\DataTransferObjects;

/**
 * One line of the background-activity feed, from either of the two sources it merges.
 *
 * The two are genuinely different records and this DTO does not pretend otherwise: `kind` says
 * which one a row came from, and the fields that only one of them has stay null on the other. A
 * task run has a trigger, a duration and an exit code; a failed job has a queue and an exception.
 * Flattening them into a single shape by dropping what does not overlap would leave the feed unable
 * to say why anything failed.
 *
 * `kind` is also what decides the row's action, since "run this task again" and "re-queue this job"
 * are different operations against different endpoints.
 */
final readonly class ActivityEntry
{
    public const string KIND_TASK_RUN = 'task_run';
    public const string KIND_FAILED_JOB = 'failed_job';

    public function __construct(
        public string $kind,
        /** `job_runs.id` or `failed_jobs.uuid` — the identifier its own endpoints take. */
        public string $id,
        /** The `OperationTask` key, or the failed job's class name. The screen translates the former. */
        public string $label,
        public string $occurredAt,
        public string $status,
        public ?string $trigger = null,
        public ?int $durationSeconds = null,
        public ?int $exitCode = null,
        public ?string $output = null,
        public ?string $queue = null,
        public ?string $connection = null,
        public ?string $exception = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'id' => $this->id,
            'label' => $this->label,
            'occurred_at' => $this->occurredAt,
            'status' => $this->status,
            'trigger' => $this->trigger,
            'duration_seconds' => $this->durationSeconds,
            'exit_code' => $this->exitCode,
            'output' => $this->output,
            'queue' => $this->queue,
            'connection' => $this->connection,
            'exception' => $this->exception,
        ];
    }
}
