<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\Events;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kern\TaxRomania\Spv\Enums\SpvSyncRunStatus;
use Kern\TaxRomania\Spv\Events\Concerns\BroadcastsOnSpvChannel;

/**
 * `BLUEPRINT.md` §16/§3.2's `SpvSyncProgress`/completion lifecycle. This module reports only start
 * (0%) and finish (100%) — a single `listaMesaje` call (or an unimplemented browser sync) has no
 * intermediate progress to report honestly; fabricating a smooth progress bar for a single
 * request/response would be inventing data. `messageCount` is the only content carried — never a
 * message title (`BLUEPRINT.md` §16).
 */
final class SpvSyncCompleted implements ShouldBroadcast
{
    use BroadcastsOnSpvChannel;
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $syncRunId,
        public readonly string $taxSubjectId,
        public readonly SpvSyncRunStatus $status,
        public readonly int $messageCount,
    ) {
    }

    public function broadcastAs(): string
    {
        return 'SpvSyncCompleted';
    }

    /** @return array{sync_run_id: string, status: string, message_count: int, progress: int} */
    public function broadcastWith(): array
    {
        return [
            'sync_run_id' => $this->syncRunId,
            'status' => $this->status->value,
            'message_count' => $this->messageCount,
            'progress' => 100,
        ];
    }
}
