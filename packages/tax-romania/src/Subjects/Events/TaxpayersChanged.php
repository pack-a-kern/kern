<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The taxpayers someone works in changed — one was added, renamed, given a picture, closed, or they
 * gained or lost access to one.
 *
 * The application's frame draws that list (the switcher) from the page's shared props, and stays
 * mounted from one screen to the next: without this, a change made on another device, or by another
 * member of the same taxpayer, showed only on the next full page load. Each open frame hears it on
 * the person's own channel and asks for the list again.
 *
 * It says nothing of what changed — not a name, not an id. The socket is not where a taxpayer's
 * details travel; the list is fetched over HTTPS, through the same authorisation as any page.
 *
 * After commit: a frame asking for the list before the change is committed would read it as it was.
 */
final class TaxpayersChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param list<string> $userIds whoever's list it is */
    public function __construct(public readonly array $userIds)
    {
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(static fn (string $userId): PrivateChannel => new PrivateChannel("users.{$userId}"), $this->userIds);
    }

    public function broadcastAs(): string
    {
        return 'taxpayers.changed';
    }

    /** @return array<string, never> */
    public function broadcastWith(): array
    {
        return [];
    }
}
