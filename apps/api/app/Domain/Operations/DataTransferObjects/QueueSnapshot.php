<?php

declare(strict_types=1);

namespace App\Domain\Operations\DataTransferObjects;

/**
 * Horizon's own counters, as far as they can be read without Horizon being up.
 *
 * `available` is false when Horizon's Redis-backed repositories cannot answer — which is exactly
 * the situation an operator opens this screen in. Every count is then `null` rather than `0`: a
 * zero here would read as "nothing is queued", when the truth is "nobody knows".
 */
final readonly class QueueSnapshot
{
    public function __construct(
        public bool $available,
        public ?int $pending,
        public ?int $completed,
        public ?int $failed,
        public ?int $recentlyFailed,
        public ?int $supervisors,
        public ?string $masterStatus,
        public ?int $maxWaitSeconds,
    ) {
    }

    public static function unavailable(): self
    {
        return new self(false, null, null, null, null, null, null, null);
    }

    /**
     * @return array{
     *     available: bool, pending: int|null, completed: int|null, failed: int|null,
     *     recently_failed: int|null, supervisors: int|null, master_status: string|null,
     *     max_wait_seconds: int|null
     * }
     */
    public function toArray(): array
    {
        return [
            'available' => $this->available,
            'pending' => $this->pending,
            'completed' => $this->completed,
            'failed' => $this->failed,
            'recently_failed' => $this->recentlyFailed,
            'supervisors' => $this->supervisors,
            'master_status' => $this->masterStatus,
            'max_wait_seconds' => $this->maxWaitSeconds,
        ];
    }
}
