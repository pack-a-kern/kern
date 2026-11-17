<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Operations\DataTransferObjects;

use Carbon\CarbonImmutable;

/**
 * What the background queue is doing right now, for the topbar's activity indicator.
 *
 * Two populations, as everywhere else on the operations screen: the queues, grouped by name as
 * Horizon knows them (so a queue added later appears on its own, with no code here naming it), and
 * the named tasks the run ledger has open. The counts are the authoritative ones — Horizon's own
 * workload for what is waiting, the walked failure total the Jobs tab shows, never the raw index
 * counters that over-report.
 *
 * `available` is false when Horizon cannot be reached, so the indicator can say "nobody can tell"
 * rather than claim an empty queue.
 */
final readonly class OperationsActivity
{
    /**
     * @param  list<array{name: string, waiting: int, processes: int}>  $queues
     * @param  list<array{key: string, since: string}>  $running
     */
    public function __construct(
        public bool $available,
        public bool $moving,
        public int $pending,
        public int $failed,
        public array $queues,
        public array $running,
    ) {
    }

    /**
     * @return array{
     *     at: string, available: bool, moving: bool, pending: int, failed: int,
     *     queues: list<array{name: string, waiting: int, processes: int}>,
     *     running: list<array{key: string, since: string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'available' => $this->available,
            'moving' => $this->moving,
            'pending' => $this->pending,
            'failed' => $this->failed,
            'queues' => $this->queues,
            'running' => $this->running,
        ];
    }
}
