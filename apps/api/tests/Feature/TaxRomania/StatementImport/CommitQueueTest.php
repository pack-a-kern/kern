<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\StatementImport\CommitQueueTest;

use Kern\TaxRomania\StatementImport\Jobs\WriteStatementRows;

/**
 * Committing a reviewed statement seals and blind-indexes every row, so a large one outlives the
 * sixty-second default queue — it was being stopped part-way. It runs on its own long lane instead,
 * with a worker that outlasts the job and a retry window that outlasts the worker. That last one is
 * the safety: a write reclaimed while still running would be handed to a second worker and write a
 * transaction twice.
 */
it('writes a reviewed statement on its own long-running queue, with its time, once', function (): void {
    $job = new WriteStatementRows('import-1', 'actor-1');

    expect($job->queue)->toBe(config('statement_import.commit.queue'))
        ->and($job->timeout)->toBe(config('statement_import.commit.timeout_seconds'))
        // One try: a half-written import is for an operator to look at, never a race with a retry.
        ->and($job->tries)->toBe(1);
});

it('has a worker for that queue that outlasts the job, and a retry window that outlasts the worker', function (): void {
    $supervisor = 'horizon.defaults.supervisor-statement-import';
    $timeout = config()->integer($supervisor . '.timeout');

    expect(config()->array($supervisor . '.queue'))->toBe([config()->string('statement_import.commit.queue')])
        ->and($timeout)->toBeGreaterThanOrEqual(config()->integer('statement_import.commit.timeout_seconds'))
        ->and(config()->integer('queue.connections.' . config()->string($supervisor . '.connection') . '.retry_after'))->toBeGreaterThan($timeout);
});
