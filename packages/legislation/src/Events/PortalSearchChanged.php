<?php

declare(strict_types=1);

namespace Kern\Legislation\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Kern\Legislation\Models\PortalSearchRun;

/**
 * A portal search read another page, or finished, or was cancelled (ADR 0024).
 *
 * An ordinary event first: anything may listen — a log, another package waiting on its own search.
 * Broadcast as well on its owner's private channel, `legislation.portal_search.broadcast_channel` with
 * `{user}` for them, so an open screen asks for the results again without asking every few seconds; a
 * search with no owner, or with the channel set empty, is broadcast nowhere.
 *
 * It carries the search's id and its counts, never the acts: the screen fetches them over HTTPS,
 * through the same authorisation as any read, and the socket is not where data travels.
 *
 * After commit: a screen asking for the search before the page is committed would read it as it was.
 */
final class PortalSearchChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public readonly string $searchId;

    public readonly ?string $userId;

    public readonly string $status;

    public readonly int $pagesRead;

    public readonly int $found;

    public function __construct(PortalSearchRun $search)
    {
        $this->searchId = $search->id;
        $this->userId = $search->user_id;
        $this->status = $search->status->value;
        $this->pagesRead = $search->pages_read;
        $this->found = count($search->acts);
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $channel = config()->string('legislation.portal_search.broadcast_channel', '');

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
        return 'legislation.portal-search.changed';
    }

    /** @return array{id: string, status: string, pages_read: int, found: int} */
    public function broadcastWith(): array
    {
        return ['id' => $this->searchId, 'status' => $this->status, 'pages_read' => $this->pagesRead, 'found' => $this->found];
    }
}
