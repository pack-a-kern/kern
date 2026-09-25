<?php

declare(strict_types=1);

use App\Domain\Operations\DataTransferObjects\ScheduledTaskDefinition;
use App\Domain\Operations\Enums\OperationTask;
use App\Domain\Operations\Enums\TaskRunStatus;
use App\Domain\Operations\Enums\TaskRunTrigger;
use App\Domain\Operations\Jobs\RunOperationTask;
use App\Domain\Operations\Models\TaskRun;
use App\Domain\Operations\Services\OperationRunLock;
use App\Domain\Operations\Services\ScheduledTaskRegistry;
use App\Domain\Operations\Services\TaskRunRecorder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Schema;

it('registers a scheduled task only while it is enabled', function (): void {
    $registry = app(ScheduledTaskRegistry::class);

    expect(array_map(
        static fn (ScheduledTaskDefinition $definition): string => $definition->task->value,
        $registry->schedulable(),
    ))->toContain(OperationTask::FX_IMPORT_DAILY->value);

    $registry->setEnabled(OperationTask::FX_IMPORT_DAILY, false, null);

    // The task disabled here, not the whole list: other tasks are scheduled too.
    expect(array_map(
        static fn (ScheduledTaskDefinition $definition): string => $definition->task->value,
        $registry->schedulable(),
    ))->not->toContain(OperationTask::FX_IMPORT_DAILY->value);
});

it('never registers a task the code does not declare schedulable', function (): void {
    $registry = app(ScheduledTaskRegistry::class);

    // Even with a stored flag saying otherwise: `enabled` is a pause switch, not a way to put a
    // manual-only command on a timer.
    $registry->setEnabled(OperationTask::LEGISLATION_REINDEX, true, null);

    expect(array_map(
        static fn (ScheduledTaskDefinition $definition): string => $definition->task->value,
        $registry->schedulable(),
    ))->not->toContain(OperationTask::LEGISLATION_REINDEX->value);
});

it('drops a scheduled task whose cron expression is not configured', function (): void {
    // Timing lives in config, so an operator who blanks it removes the entry rather than getting a
    // task registered with an invalid expression, which `CronExpression` would reject at tick time.
    config()->set('operations.schedule.expressions', [OperationTask::FX_IMPORT_DAILY->value => '']);

    expect(app(ScheduledTaskRegistry::class)->schedulable())->toBe([]);
});

it('falls back to the code defaults rather than taking the scheduler down with the database', function (): void {
    // The scheduler re-evaluates the whole schedule every minute. If reading the pause flags threw,
    // `schedule:run` would abort and nothing would run that minute — including the heartbeat that
    // reports the scheduler healthy. Dropping the table simulates the unreachable-database case.
    Schema::drop('scheduled_tasks');

    $definitions = app(ScheduledTaskRegistry::class)->schedulable();

    expect(array_map(
        static fn (ScheduledTaskDefinition $definition): string => $definition->task->value,
        $definitions,
    ))->toContain(OperationTask::FX_IMPORT_DAILY->value);
});

it('records a run with the command exit code, not merely that nothing threw', function (): void {
    // `kern:access:sync` is the one task in the enum that is safe to execute here: it reaches no
    // network and writes only to the permission tables this suite already rebuilds.
    $task = OperationTask::ACCESS_SYNC;

    (new RunOperationTask($task, TaskRunTrigger::MANUAL, null))
        ->handle(app(Kernel::class), app(TaskRunRecorder::class), app(OperationRunLock::class));

    $run = TaskRun::query()->where('task_key', $task->value)->first();

    expect($run)->not->toBeNull()
        ->and($run?->status)->toBe(TaskRunStatus::SUCCEEDED)
        ->and($run?->exit_code)->toBe(0)
        ->and($run?->finished_at)->not->toBeNull();
});

it('skips a run while another run of the same task holds the lock', function (): void {
    $task = OperationTask::ACCESS_SYNC;
    $lock = app(OperationRunLock::class);

    expect($lock->acquire($task))->not->toBeNull();

    (new RunOperationTask($task, TaskRunTrigger::SCHEDULED, null))
        ->handle(app(Kernel::class), app(TaskRunRecorder::class), $lock);

    // No ledger row at all: the run never started, so recording one would claim work that did not
    // happen.
    expect(TaskRun::query()->count())->toBe(0);
});
