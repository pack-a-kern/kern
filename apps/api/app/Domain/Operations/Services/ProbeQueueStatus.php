<?php

declare(strict_types=1);

namespace App\Domain\Operations\Services;

use App\Domain\Operations\DataTransferObjects\QueueSnapshot;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Throwable;

/**
 * Horizon's own counters, read through its repository contracts rather than by embedding its
 * dashboard.
 *
 * Horizon *is* the `queue` container here (`compose.yaml` runs `php artisan horizon`), so these
 * repositories are the authoritative source — reading `jobs`/`failed_jobs` tables directly would
 * describe a different queue driver than the one actually in use.
 *
 * Every read is guarded as a group and degrades to {@see QueueSnapshot::unavailable()}. The
 * repositories are Redis-backed, and Redis being unreachable is precisely the incident during
 * which an operator opens this page: a probe that throws would take the whole overview endpoint
 * down with it, and a probe that returned zeros would claim the queue is empty when the truth is
 * that nobody can tell.
 *
 * `ProbeSystemHealth` deliberately declines to report worker state for the same reason it declines
 * to report Reverb — it has no contract to ask through. This class has one, which is why the queue
 * panel lives on the operations screen and not beside the health rows.
 */
final readonly class ProbeQueueStatus
{
    public function __construct(
        private JobRepository $jobs,
        private WorkloadRepository $workload,
        private MasterSupervisorRepository $masters,
    ) {
    }

    public function __invoke(): QueueSnapshot
    {
        try {
            return new QueueSnapshot(
                available: true,
                pending: (int) $this->jobs->countPending(),
                completed: (int) $this->jobs->countCompleted(),
                failed: (int) $this->jobs->countFailed(),
                recentlyFailed: (int) $this->jobs->countRecentlyFailed(),
                supervisors: count($this->masters->all()),
                masterStatus: $this->masterStatus(),
                maxWaitSeconds: $this->maxWaitSeconds(),
            );
        } catch (Throwable $exception) {
            report($exception);

            return QueueSnapshot::unavailable();
        }
    }

    /**
     * Horizon reports its own paused/running state per master supervisor. With one worker container
     * there is one master; the first is reported rather than inventing an aggregate that would be
     * wrong the moment a second is added.
     */
    private function masterStatus(): ?string
    {
        $master = $this->masters->all()[0] ?? null;

        if (! is_object($master) || ! property_exists($master, 'status')) {
            return null;
        }

        $status = $master->status;

        return is_string($status) && $status !== '' ? $status : null;
    }

    /**
     * The longest a job is currently waiting on any queue — the number that tells an operator
     * whether the workers are keeping up, which a raw pending count does not.
     */
    private function maxWaitSeconds(): ?int
    {
        $longest = null;

        foreach ($this->workload->get() as $queue) {
            $longest = max($longest ?? 0, $queue['wait']);
        }

        return $longest;
    }
}
