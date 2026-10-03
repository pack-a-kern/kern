<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Operations\KilledTaskRunTest;

use Illuminate\Queue\TimeoutExceededException;
use Kern\TaxRomania\Operations\Enums\OperationTask;
use Kern\TaxRomania\Operations\Enums\TaskRunStatus;
use Kern\TaxRomania\Operations\Enums\TaskRunTrigger;
use Kern\TaxRomania\Operations\Jobs\RunOperationTask;
use Kern\TaxRomania\Operations\Models\TaskRun;
use Kern\TaxRomania\Operations\Services\OperationRunLock;

/**
 * A task whose job the worker killed leaves a failure, not a run that never ends.
 *
 * A queue timeout does not raise into `handle()` — it fails the job and ends the process — so the
 * `catch` never runs and neither does the `finally`. Before this, the ledger row stayed `RUNNING`
 * for ever and the lock stood until its half-hour expiry, which meant the Tasks tab showed a run
 * still in flight that nothing would finish, while every scheduled attempt in that window returned
 * having done nothing.
 *
 * Found on `counterparties.classify`, which asked a model from the sixty-second `default` queue. The
 * task was wrong to be there and has moved; the stuck row was this job's to prevent, and would have
 * happened to any task slow enough.
 */
it('records a failure when the worker kills the job', function (): void {
    $run = TaskRun::create([
        'task_key' => OperationTask::FX_IMPORT_DAILY->value,
        'trigger' => TaskRunTrigger::SCHEDULED,
        'status' => TaskRunStatus::RUNNING,
        'started_at' => now('UTC'),
    ]);

    $job = new RunOperationTask(OperationTask::FX_IMPORT_DAILY, TaskRunTrigger::SCHEDULED, null);
    $job->failed(new TimeoutExceededException());

    $run->refresh();

    expect($run->status)->toBe(TaskRunStatus::FAILED)
        ->and($run->finished_at)->not->toBeNull()
        ->and((string) $run->output)->toContain('TimeoutExceededException');
});

it('lets the next run take the lock the killed one was holding', function (): void {
    $lock = app(OperationRunLock::class);

    // The job took it and died without releasing: the token went with the process.
    expect($lock->acquire(OperationTask::FX_IMPORT_DAILY))->not->toBeNull()
        ->and($lock->acquire(OperationTask::FX_IMPORT_DAILY))->toBeNull();

    $job = new RunOperationTask(OperationTask::FX_IMPORT_DAILY, TaskRunTrigger::SCHEDULED, null);
    $job->failed(new TimeoutExceededException());

    expect($lock->acquire(OperationTask::FX_IMPORT_DAILY))->not->toBeNull();
});

it('leaves the lock alone when the caller owns it', function (): void {
    // A manual run arrives holding the lock its controller took. Forcing that one open would let a
    // second click through while the first is still going.
    $lock = app(OperationRunLock::class);
    $owner = $lock->acquire(OperationTask::FX_IMPORT_DAILY);

    expect($owner)->not->toBeNull();

    $job = new RunOperationTask(OperationTask::FX_IMPORT_DAILY, TaskRunTrigger::MANUAL, null, [], $owner);
    $job->failed(new TimeoutExceededException());

    expect($lock->acquire(OperationTask::FX_IMPORT_DAILY))->toBeNull();
});

it('does nothing when there is no run to fail', function (): void {
    // A job killed before it ever started one, or a row somebody already resolved.
    $job = new RunOperationTask(OperationTask::FX_IMPORT_DAILY, TaskRunTrigger::SCHEDULED, null);

    $job->failed(new TimeoutExceededException());

    expect(TaskRun::query()->count())->toBe(0);
});
