<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Operations\Services;

use Kern\TaxRomania\Operations\DataTransferObjects\OperationsActivity;
use Kern\TaxRomania\Operations\Models\TaskRun;
use Kern\TaxRomania\Operations\Repositories\TaskRunRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Throwable;

/**
 * Assembles {@see OperationsActivity} for the topbar — the queues and the running tasks, from the
 * sources the operations screen already trusts, never a hand-kept list of queue names.
 *
 * The per-queue counts come from Horizon's workload (its own grouping, so every configured queue is
 * there and a new one needs no change here). Whether the queue is moving comes from
 * {@see ProbeQueueStatus}'s master status. The failure total is the walked count the Jobs tab reads
 * ({@see ReadQueueJobs::retrievableTotal()}), not the Redis index counter, which over-reports stale
 * ids. The running tasks are the ledger's open runs ({@see TaskRunRepository::running()}).
 *
 * Every read is guarded: Horizon being unreachable is the incident during which this is asked, so an
 * unavailable probe reports `available: false` rather than throwing or claiming an idle queue.
 */
final readonly class ProbeOperationsActivity
{
    public function __construct(
        private WorkloadRepository $workload,
        private ProbeQueueStatus $queueStatus,
        private ReadQueueJobs $jobs,
        private TaskRunRepository $runs,
    ) {
    }

    public function __invoke(): OperationsActivity
    {
        $snapshot = ($this->queueStatus)();
        $available = $snapshot->available;

        $queues = [];
        $pending = 0;

        try {
            foreach ($this->workload->get() as $entry) {
                /** @var array<string, mixed> $row */
                $row = (array) $entry;
                $name = isset($row['name']) && is_string($row['name']) ? $row['name'] : '';

                if ($name === '') {
                    continue;
                }

                $waiting = isset($row['length']) && is_numeric($row['length']) ? (int) $row['length'] : 0;
                $processes = isset($row['processes']) && is_numeric($row['processes']) ? (int) $row['processes'] : 0;
                $queues[] = ['name' => $name, 'waiting' => $waiting, 'processes' => $processes];
                $pending += $waiting;
            }
        } catch (Throwable $exception) {
            report($exception);
            $available = false;
            $queues = [];
            $pending = 0;
        }

        $running = array_map(
            static fn (TaskRun $run): array => [
                'key' => $run->task_key,
                'since' => $run->started_at->toIso8601String(),
            ],
            $this->runs->running(),
        );

        return new OperationsActivity(
            available: $available,
            moving: $available && $snapshot->masterStatus === 'running',
            pending: $pending,
            failed: $this->failedTotal(),
            queues: $queues,
            running: $running,
        );
    }

    private function failedTotal(): int
    {
        try {
            return $this->jobs->retrievableTotal('failed');
        } catch (Throwable $exception) {
            report($exception);

            return 0;
        }
    }
}
