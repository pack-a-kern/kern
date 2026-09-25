<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\Events;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kern\TaxRomania\Spv\Events\Concerns\BroadcastsOnSpvChannel;

/** `BLUEPRINT.md` §16's `SpvAuthenticated` — the SPV login (once a real portal adapter exists) succeeded. */
final class SpvAuthenticated implements ShouldBroadcast
{
    use BroadcastsOnSpvChannel;
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $sessionId,
        public readonly string $taxSubjectId,
    ) {
    }

    public function broadcastAs(): string
    {
        return 'SpvAuthenticated';
    }

    /** @return array{session_id: string} */
    public function broadcastWith(): array
    {
        return ['session_id' => $this->sessionId];
    }
}
