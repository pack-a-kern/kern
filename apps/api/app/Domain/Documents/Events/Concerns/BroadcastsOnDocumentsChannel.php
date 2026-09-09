<?php

declare(strict_types=1);

namespace App\Domain\Documents\Events\Concerns;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Shared by every `App\Domain\Documents\Events\*` broadcast event: which private channel a
 * subject's document lifecycle events go out on (`routes/channels.php` authorizes it against an
 * active `SubjectMembership`, the same membership check `App\Domain\Subjects\Tenancy\
 * TenantContext::authorizeAndActivate()` performs for HTTP requests). Extracting this rather than
 * repeating the channel-name string in four classes is removing duplication of a real invariant
 * (`docs/CODE-QUALITY.md` §1) — the channel name is a contract the PWA frontend also depends on.
 */
trait BroadcastsOnDocumentsChannel
{
    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("tax-subjects.{$this->taxSubjectId}.documents")];
    }
}
