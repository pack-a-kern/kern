<?php

declare(strict_types=1);

namespace App\Domain\Spv\Events;

use App\Domain\Spv\Events\Concerns\BroadcastsOnSpvChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** `BLUEPRINT.md` §16/§3.2's `SpvSyncStarted`. */
final class SpvSyncStarted implements ShouldBroadcast
{
    use BroadcastsOnSpvChannel;
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $syncRunId,
        public readonly string $taxSubjectId,
    ) {
    }

    public function broadcastAs(): string
    {
        return 'SpvSyncStarted';
    }

    /** @return array{sync_run_id: string, progress: int} */
    public function broadcastWith(): array
    {
        return ['sync_run_id' => $this->syncRunId, 'progress' => 0];
    }
}
