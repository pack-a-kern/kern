<?php

declare(strict_types=1);

namespace Kern\Legislation\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An act a person chose in "Import selected" has been read in — or could not be: said on that person's own
 * channel (`legislation.portal_search.broadcast_channel`) with the act's portal id and which, so the dialog
 * asks for its search again over HTTPS rather than every few seconds.
 */
final readonly class PortalActRead implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(public string $portalId, public string $userId, public bool $read)
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
        return 'legislation.act.read';
    }

    /** @return array{id: string, read: bool} */
    public function broadcastWith(): array
    {
        return ['id' => $this->portalId, 'read' => $this->read];
    }
}
