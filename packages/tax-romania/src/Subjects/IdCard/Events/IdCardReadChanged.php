<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\IdCard\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An identity-card read has finished — the result is waiting in the scratch store (ADR 0028).
 *
 * A nudge, nothing more: it carries the token the browser is waiting on and whether the read
 * succeeded, never a field from the card. The CNP, the name and the address travel only over the
 * HTTPS fetch that follows, through ordinary authorisation — the socket is not where a national
 * identifier goes. Broadcast on the uploader's own private channel (`users.{userId}`), the one it is
 * already authorised for.
 *
 * After commit, for the same reason every broadcast here is: a screen that fetched before the
 * result row was committed would find nothing.
 */
final class IdCardReadChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $userId,
        public readonly string $token,
        public readonly string $status,
    ) {
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.' . $this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'idcard.read.changed';
    }

    /** @return array{token: string, status: string} */
    public function broadcastWith(): array
    {
        return ['token' => $this->token, 'status' => $this->status];
    }
}
