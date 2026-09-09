<?php

declare(strict_types=1);

namespace App\Domain\Spv\Events;

use App\Domain\Spv\Events\Concerns\BroadcastsOnSpvChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

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
