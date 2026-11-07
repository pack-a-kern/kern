<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Operations\LongTaskQueueTest;

use Kern\TaxRomania\Operations\Enums\OperationTask;
use Kern\TaxRomania\Operations\Enums\TaskRunTrigger;
use Kern\TaxRomania\Operations\Jobs\RunOperationTask;

/**
 * The tasks that read a source's history run where they may take minutes; the rest stay on the
 * sixty-second queue. The bank backfill and a year of exchange rates were both stopped there.
 */
it('sends a task that reads history to the long-task queue, with its time', function (): void {
    $job = new RunOperationTask(OperationTask::BANKS_BACKFILL, TaskRunTrigger::MANUAL, null);

    expect($job->queue)->toBe(config('operations.long_tasks.queue'))
        ->and($job->timeout)->toBe(config('operations.long_tasks.timeout_seconds'));
});

it('leaves every other task on the default queue', function (): void {
    foreach (OperationTask::cases() as $task) {
        if (! $task->isLong()) {
            $job = new RunOperationTask($task, TaskRunTrigger::MANUAL, null);

            expect([$job->queue, $job->timeout])->toBe([null, null], $task->value);
        }
    }
});

it('has a worker for that queue that outlasts the job, and a retry window that outlasts the worker', function (): void {
    $supervisor = 'horizon.defaults.supervisor-operations-long';
    $timeout = config()->integer($supervisor . '.timeout');

    expect(config()->array($supervisor . '.queue'))->toBe([config()->string('operations.long_tasks.queue')])
        ->and($timeout)->toBeGreaterThanOrEqual(config()->integer('operations.long_tasks.timeout_seconds'))
        ->and(config()->integer('queue.connections.' . config()->string($supervisor . '.connection') . '.retry_after'))->toBeGreaterThan($timeout);
});
