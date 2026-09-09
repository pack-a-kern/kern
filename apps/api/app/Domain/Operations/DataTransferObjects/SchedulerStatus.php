<?php

declare(strict_types=1);

namespace App\Domain\Operations\DataTransferObjects;

/**
 * Whether the scheduler process is alive, from the heartbeat file it touches every minute.
 *
 * Liveness is read from a file's mtime rather than from a process list because the answer must be
 * "the scheduler ran a task within the last three minutes", not "a process with a matching name
 * exists" — a wedged `schedule:work` satisfies the second and fails the first.
 */
final readonly class SchedulerStatus
{
    public function __construct(
        public bool $alive,
        public ?string $lastBeatAt,
        public ?int $ageSeconds,
        public int $maxAgeSeconds,
    ) {
    }

    /** @return array{alive: bool, last_beat_at: string|null, age_seconds: int|null, max_age_seconds: int} */
    public function toArray(): array
    {
        return [
            'alive' => $this->alive,
            'last_beat_at' => $this->lastBeatAt,
            'age_seconds' => $this->ageSeconds,
            'max_age_seconds' => $this->maxAgeSeconds,
        ];
    }
}
