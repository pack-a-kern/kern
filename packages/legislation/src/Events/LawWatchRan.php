<?php

declare(strict_types=1);

namespace Kern\Legislation\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A watch a person asked to run now has run: said on that person's own channel — the one a portal
 * search's progress is said on (`legislation.portal_search.broadcast_channel`) — with the watch's id only,
 * so the screen asks for what it did over HTTPS.
 */
final readonly class LawWatchRan implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(public string $watchId, public string $userId)
    {
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $channel = config()->string('legislation.portal_search.broadcast_channel');

        return $channel === '' ? [] : [new PrivateChannel(str_replace('{user}', $this->userId, $channel))];
    }

    public function broadcastWhen(): bool
    {
        return $this->broadcastOn() !== [];
    }

    public function broadcastAs(): string
    {
        return 'legislation.watch.ran';
    }

    /** @return array{id: string} */
    public function broadcastWith(): array
    {
        return ['id' => $this->watchId];
    }
}
