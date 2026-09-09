<?php

declare(strict_types=1);

namespace App\Domain\Operations\Services;

use App\Domain\Operations\Enums\JobRunStatus;
use App\Domain\Operations\Enums\JobRunTrigger;
use App\Domain\Operations\Enums\OperationTask;
use App\Domain\Operations\Models\JobRun;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Opens and closes rows in the run ledger.
 *
 * A run row is written *before* the command executes, not after, so a task that never returns —
 * an OOM-killed worker, a container restart mid-import — still leaves evidence that it started.
 * A row stuck in `RUNNING` is itself the diagnostic; a ledger that only records completions would
 * show nothing at all for exactly the failure an operator most needs to see.
 */
final readonly class JobRunRecorder
{
    public function __construct(private ConfigRepository $config)
    {
    }

    /**
     * @param  array<string, int|string>  $arguments
     */
    public function start(OperationTask $task, JobRunTrigger $trigger, ?string $actorId, array $arguments = []): JobRun
    {
        $run = new JobRun();
        $run->task_key = $task->value;
        $run->trigger = $trigger;
        $run->status = JobRunStatus::RUNNING;
        $run->triggered_by = $actorId;
        $run->arguments = $arguments === [] ? null : $arguments;
        $run->started_at = CarbonImmutable::now('UTC');
        $run->save();

        return $run;
    }

    /**
     * Close a run. Success is the command's own exit code, not whether an exception escaped: a
     * command that returns `FAILURE` after reporting a misconfigured endpoint has failed, and
     * recording it as a success because nothing threw is exactly the lie this ledger exists to
     * prevent.
     */
    public function finish(JobRun $run, int $exitCode, string $output): void
    {
        $run->status = $exitCode === 0 ? JobRunStatus::SUCCEEDED : JobRunStatus::FAILED;
        $run->exit_code = $exitCode;
        $run->output = $this->truncate($output);
        $run->finished_at = CarbonImmutable::now('UTC');
        $run->save();
    }

    /** The command threw rather than returning. There is no exit code to record, only the cause. */
    public function fail(JobRun $run, string $reason): void
    {
        $run->status = JobRunStatus::FAILED;
        $run->exit_code = null;
        $run->output = $this->truncate($reason);
        $run->finished_at = CarbonImmutable::now('UTC');
        $run->save();
    }

    /**
     * Console output is an operator diagnostic, not a log store. The cap is configurable because
     * how much of a long reindex report is worth keeping is an operator's call, not this class's.
     */
    private function truncate(string $output): ?string
    {
        $trimmed = trim($output);

        if ($trimmed === '') {
            return null;
        }

        $limit = $this->config->get('operations.runs.max_output_bytes');
        $limit = is_int($limit) && $limit > 0 ? $limit : 4000;

        if (strlen($trimmed) <= $limit) {
            return $trimmed;
        }

        return substr($trimmed, 0, $limit) . "\n…";
    }
}
