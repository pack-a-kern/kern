<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Operations\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\TaxRomania\Operations\Enums\OperationTask;
use Kern\TaxRomania\Operations\Enums\TaskRunStatus;
use Kern\TaxRomania\Operations\Enums\TaskRunTrigger;
use Kern\TaxRomania\Operations\Events\OperationsActivityChanged;
use Kern\TaxRomania\Operations\Models\TaskRun;
use Throwable;

/**
 * Opens and closes rows in the run ledger.
 *
 * A run row is written *before* the command executes, not after, so a task that never returns —
 * an OOM-killed worker, a container restart mid-import — still leaves evidence that it started.
 * A row stuck in `RUNNING` is itself the diagnostic; a ledger that only records completions would
 * show nothing at all for exactly the failure an operator most needs to see.
 *
 * Opening or closing a run nudges the topbar's activity indicator ({@see OperationsActivityChanged}),
 * so "a task started" and "a task ended" reach an open screen at once rather than on its next poll.
 */
final readonly class TaskRunRecorder
{
    public function __construct(private ConfigRepository $config)
    {
    }

    /**
     * @param  array<string, int|string>  $arguments
     */
    public function start(OperationTask $task, TaskRunTrigger $trigger, ?string $actorId, array $arguments = []): TaskRun
    {
        $run = new TaskRun();
        $run->task_key = $task->value;
        $run->trigger = $trigger;
        $run->status = TaskRunStatus::RUNNING;
        $run->triggered_by = $actorId;
        $run->arguments = $arguments === [] ? null : $arguments;
        $run->started_at = CarbonImmutable::now('UTC');
        $run->save();

        $this->nudge();

        return $run;
    }

    /**
     * Close a run. Success is the command's own exit code, not whether an exception escaped: a
     * command that returns `FAILURE` after reporting a misconfigured endpoint has failed, and
     * recording it as a success because nothing threw is exactly the lie this ledger exists to
     * prevent.
     */
    public function finish(TaskRun $run, int $exitCode, string $output): void
    {
        $run->status = $exitCode === 0 ? TaskRunStatus::SUCCEEDED : TaskRunStatus::FAILED;
        $run->exit_code = $exitCode;
        $run->output = $this->truncate($output);
        $run->finished_at = CarbonImmutable::now('UTC');
        $run->save();

        $this->nudge();
    }

    /** The command threw rather than returning. There is no exit code to record, only the cause. */
    public function fail(TaskRun $run, string $reason): void
    {
        $run->status = TaskRunStatus::FAILED;
        $run->exit_code = null;
        $run->output = $this->truncate($reason);
        $run->finished_at = CarbonImmutable::now('UTC');
        $run->save();

        $this->nudge();
    }

    /**
     * Nudge the activity indicator, best-effort. The run row is saved before this is called, so a
     * broadcast that cannot be dispatched — a queue or Reverb hiccup — must never fail the recording
     * the ledger exists to keep; a failure here is reported and swallowed, not thrown back into the
     * task that just ran.
     */
    private function nudge(): void
    {
        try {
            OperationsActivityChanged::dispatch();
        } catch (Throwable $exception) {
            report($exception);
        }
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
