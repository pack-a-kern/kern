<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Operations\ProbeQueueStatusTest;

use App\Domain\Operations\Services\ProbeQueueStatus;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Mockery;
use Mockery\MockInterface;

/**
 * Horizon estimates a queue's wait from measured runtimes, so it is a fraction of a second as often
 * as not. Returned as a float, it was a TypeError that turned the whole queue panel "unavailable"
 * the first time a queue — the one reading laws — had a wait worth measuring.
 */
it('reports a fractional wait as whole seconds, and stays available', function (): void {
    /** @var JobRepository&MockInterface $jobs */
    $jobs = Mockery::mock(JobRepository::class);
    $jobs->allows('countPending')->andReturn(1);
    $jobs->allows('countCompleted')->andReturn(2);
    $jobs->allows('countFailed')->andReturn(0);
    $jobs->allows('countRecentlyFailed')->andReturn(0);

    /** @var WorkloadRepository&MockInterface $workload */
    $workload = Mockery::mock(WorkloadRepository::class);
    $workload->allows('get')->andReturn([
        ['name' => 'legislation', 'length' => 1, 'wait' => 13.4, 'processes' => 1],
        ['name' => 'default', 'length' => 0, 'wait' => 0, 'processes' => 3],
    ]);

    /** @var MasterSupervisorRepository&MockInterface $masters */
    $masters = Mockery::mock(MasterSupervisorRepository::class);
    $masters->allows('all')->andReturn([]);

    /** @var MetricsRepository&MockInterface $metrics */
    $metrics = Mockery::mock(MetricsRepository::class);
    $metrics->allows('jobsProcessedPerMinute')->andReturn(0);

    $snapshot = (new ProbeQueueStatus($jobs, $workload, $masters, $metrics))();

    expect($snapshot->available)->toBeTrue()
        ->and($snapshot->maxWaitSeconds)->toBe(13);
});
