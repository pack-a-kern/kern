<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Operations\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A background task started or ended, so the topbar's activity indicator should look again.
 *
 * A nudge, not the data: it carries only the moment it fired. The screen asks
 * `GET /api/v1/operations/activity` for the current picture over HTTPS, under the same permission
 * the operations screen reads through — the socket is not where queue counts travel, and a message
 * missed is corrected by the next poll. Broadcast on the private channel
 * `operations.activity.broadcast_channel`, which only `operations.view` may join
 * (`routes/channels.php`); set the channel empty to broadcast nowhere.
 *
 * After commit: the run row whose change prompted this is written first, so a screen that asks on the
 * nudge reads the ledger as it now is.
 */
final class OperationsActivityChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public readonly string $at;

    public function __construct()
    {
        $this->at = now()->toIso8601String();
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $channel = config()->string('operations.activity.broadcast_channel', '');

        return $channel === '' ? [] : [new PrivateChannel($channel)];
    }

    public function broadcastWhen(): bool
    {
        return $this->broadcastOn() !== [];
    }

    public function broadcastAs(): string
    {
        return 'operations.activity.changed';
    }

    /** @return array{at: string} */
    public function broadcastWith(): array
    {
        return ['at' => $this->at];
    }
}
