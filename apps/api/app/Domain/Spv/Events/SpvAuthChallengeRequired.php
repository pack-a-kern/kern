<?php

declare(strict_types=1);

namespace App\Domain\Spv\Events;

use App\Domain\Spv\Events\Concerns\BroadcastsOnSpvChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * `BLUEPRINT.md` §13.2 step 7 / §16's `SpvAuthChallengeRequired` — ANAF's portal (once a real adapter
 * exists) asked for a safety code; the UI must prompt the user. Status/progress only, never the
 * username or any portal content (`BLUEPRINT.md` §16's own worked example).
 */
final class SpvAuthChallengeRequired implements ShouldBroadcast
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
        return 'SpvAuthChallengeRequired';
    }

    /** @return array{session_id: string} */
    public function broadcastWith(): array
    {
        return ['session_id' => $this->sessionId];
    }
}
