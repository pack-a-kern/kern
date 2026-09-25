<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\Events;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kern\TaxRomania\Spv\Events\Concerns\BroadcastsOnSpvChannel;

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
