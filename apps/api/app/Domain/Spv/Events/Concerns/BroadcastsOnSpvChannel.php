<?php

declare(strict_types=1);

namespace App\Domain\Spv\Events\Concerns;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Shared by every `App\Domain\Spv\Events\*` broadcast event — mirrors `App\Domain\Documents\
 * Events\Concerns\BroadcastsOnDocumentsChannel` exactly, one private channel per subject,
 * authorized in `routes/channels.php` against an active `SubjectMembership`.
 */
trait BroadcastsOnSpvChannel
{
    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("tax-subjects.{$this->taxSubjectId}.spv")];
    }
}
