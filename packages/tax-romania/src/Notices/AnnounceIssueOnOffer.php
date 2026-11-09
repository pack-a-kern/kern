<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Notices;

use Illuminate\Support\Facades\Notification;
use Kern\Investments\Events\IssueOnOffer;
use Kern\Notifications\Models\Preference;
use Kern\Platform\Identity\Models\User;

/**
 * Tells of a new issue on offer everyone whose switch for it is on — on, as every kind's switch is, until
 * the person turns it off (ADR 0022). Someone who turned it off is not sent it at all, the bell included:
 * unlike every other notice, it is about the market, not about anything of theirs, and a bell entry they
 * asked not to have is noise.
 *
 * People are read one at a time, so an installation with many users is not held in memory at once.
 */
final readonly class AnnounceIssueOnOffer
{
    public function handle(IssueOnOffer $event): void
    {
        $turnedOff = Preference::query()
            ->where('notifiable_type', (new User())->getMorphClass())
            ->where('type', IssueOnOfferNotice::key())
            ->where('push_enabled', false)
            ->select('notifiable_id');

        foreach (User::query()->whereNotIn('id', $turnedOff)->lazyById() as $user) {
            Notification::send($user, IssueOnOfferNotice::about($event->instrumentId));
        }
    }
}
