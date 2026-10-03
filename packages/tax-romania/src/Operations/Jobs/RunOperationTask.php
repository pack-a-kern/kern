<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Operations\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kern\TaxRomania\Operations\Enums\OperationTask;
use Kern\TaxRomania\Operations\Enums\TaskRunStatus;
use Kern\TaxRomania\Operations\Enums\TaskRunTrigger;
use Kern\TaxRomania\Operations\Models\TaskRun;
use Kern\TaxRomania\Operations\Services\OperationRunLock;
use Kern\TaxRomania\Operations\Services\TaskRunRecorder;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Executes one {@see OperationTask} on a queue worker and writes the outcome to the run ledger.
 *
 * On a worker rather than in the request because every task here takes minutes: an HTTP handler
 * that ran `kern:fx:backfill 2019` inline would hold a php-fpm child for the duration and time
 * out at the edge long before the work finished, leaving the operator with a 504 and no idea
 * whether the import completed.
 *
 * The command is resolved from the enum case, never from the payload. A queue payload is
 * serialized data that has been to Redis and back; treating a string in it as a command to execute
 * would put the allowlist on the wrong side of the trust boundary.
 *
 * `$tries = 1`: an import that failed halfway is a situation for an operator to look at, not for
 * the queue to repeat unattended. Every attempt would write another ledger row and another partial
 * import, and the second failure tells nobody anything the first did not.
 *
 * @see \Kern\TaxRomania\Operations\Services\OperationRunLock for why the lock is taken before dispatch
 *      and released here.
 */
final class RunOperationTask implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var int */
    public $tries = 1;

    /**
     * @param  array<string, int|string>  $arguments  Already validated against {@see OperationTask::arguments()}.
     * @param  string|null  $lockOwner  Token from {@see OperationRunLock::acquire()} when the caller
     *                                    already holds the lock; `null` to take it here.
     */
    public function __construct(
        private readonly OperationTask $task,
        private readonly TaskRunTrigger $trigger,
        private readonly ?string $actorId,
        private readonly array $arguments = [],
        private readonly ?string $lockOwner = null,
    ) {
    }

    /**
     * Maps validated form fields onto the command's own parameter names.
     *
     * An artisan option is addressed as `--type` while a form field cannot be, so
     * {@see \Kern\TaxRomania\Operations\DataTransferObjects\TaskArgument} carries both names and the
     * translation happens here — once, next to the call — rather than leaking `--` into the screen,
     * the JSON and the stored `task_runs.arguments`.
     *
     * Every value is handed over as text, as a terminal would: a command reads its input as the
     * strings it gets from one, and an integer here — the form's act number, `2346` — failed
     * `kern:legislation:sync`'s own check that a number is digits, so no act could be read from
     * the screen at all.
     *
     * @return array<string, string>
     */
    private function commandArguments(): array
    {
        $mapped = [];

        foreach ($this->task->arguments() as $argument) {
            if (array_key_exists($argument->name, $this->arguments)) {
                $mapped[$argument->commandKey] = (string) $this->arguments[$argument->name];
            }
        }

        return $mapped;
    }

    /**
     * What a job that was killed rather than thrown leaves behind.
     *
     * A worker timeout does not raise into `handle()` — it fails the job and ends the process — so
     * the `catch` below never runs, the `finally` never runs, and the ledger row stays `RUNNING`
     * for ever. The Tasks tab then shows a run still in flight that nothing will ever finish, which
     * is worse than a failure: a failure says look at me.
     *
     * Found when the counterparty classifier was put on the `default` queue, whose sixty-second
     * timeout it exceeded every ten minutes. That was a mistake in the task, but the stuck row is
     * this job's to prevent, and it would have happened to any task slow enough.
     *
     * The run is found rather than remembered: `$run` is created inside `handle()` and this may be
     * called in a process that never had it.
     */
    public function failed(?Throwable $exception): void
    {
        $recorder = app(TaskRunRecorder::class);
        $run = TaskRun::query()
            ->where('task_key', $this->task->value)
            ->where('status', TaskRunStatus::RUNNING)
            ->orderByDesc('started_at')
            ->first();

        if ($run !== null) {
            $recorder->fail($run, $exception === null ? 'Killed' : class_basename($exception));
        }

        // And the lock, which `finally` never reached. It has a time to live, so this only saves the
        // next half hour of scheduled runs from returning without doing anything — which is exactly
        // the half hour in which somebody is looking at why the last one failed.
        if ($this->lockOwner === null) {
            app(OperationRunLock::class)->forceRelease($this->task);
        }
    }

    public function handle(Kernel $artisan, TaskRunRecorder $recorder, OperationRunLock $lock): void
    {
        // A manual run arrives holding the lock the controller took, so a double-click was already
        // refused with an answer. A scheduled run has no owner and takes it here — otherwise the
        // 14:00 import and an operator's "Run now" a second earlier would execute concurrently.
        $owner = $this->lockOwner ?? $lock->acquire($this->task);

        if ($owner === null) {
            return;
        }

        $run = $recorder->start($this->task, $this->trigger, $this->actorId, $this->arguments);
        $output = new BufferedOutput();

        try {
            $exitCode = $artisan->call($this->task->command(), $this->commandArguments(), $output);
            $recorder->finish($run, $exitCode, $output->fetch());
        } catch (Throwable $exception) {
            // The message, not the trace: an exception from an importer can carry an endpoint or a
            // driver DSN, and this string is rendered in a browser (`BLUEPRINT.md` §4.10). The full
            // trace reaches the logs through `report()`.
            report($exception);
            $recorder->fail($run, $output->fetch() . "\n" . class_basename($exception));
        } finally {
            $lock->release($this->task, $owner);
        }
    }
}
