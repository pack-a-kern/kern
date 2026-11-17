<?php

declare(strict_types=1);

namespace Kern\Legislation\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A law's removal finished, or could not (`DeleteLawJob`).
 *
 * Removing a consolidated act is minutes of deleting — too long to hold the request that asked for it,
 * which times out at the gateway — so the work is a job and this says, over the asker's private channel,
 * when it is done (`deleted`) or failed (`failed`). The screen that asked then takes the law off its
 * list, or says it stays; the socket carries the law's id and that word only, nothing the screen could
 * not already read over HTTPS.
 *
 * Broadcast on `legislation.law_deletion.broadcast_channel`, `{user}` for whoever asked — the
 * application's own per-person channel, as a portal search uses (`PortalSearchChanged`). A removal with
 * no asker, or with the channel set empty, is broadcast nowhere.
 *
 * After commit: the `deleted` word is only true once the deletion's transaction has committed, so a
 * screen acting on it never reads a law the database still holds.
 */
final class LawDeletionChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $lawId,
        public readonly ?string $userId,
        public readonly string $status,
    ) {
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $channel = config()->string('legislation.law_deletion.broadcast_channel', '');

        return $this->userId === null || $channel === ''
            ? []
            : [new PrivateChannel(str_replace('{user}', $this->userId, $channel))];
    }

    public function broadcastWhen(): bool
    {
        return $this->broadcastOn() !== [];
    }

    public function broadcastAs(): string
    {
        return 'legislation.law-deletion.changed';
    }

    /** @return array{id: string, status: string} */
    public function broadcastWith(): array
    {
        return ['id' => $this->lawId, 'status' => $this->status];
    }
}
