<?php

declare(strict_types=1);

namespace Kern\Platform\Console\Commands\Identity;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\Platform\Identity\Models\UserSession;
use Kern\Platform\Identity\Support\SessionRegistrar;

/**
 * Deletes the `user_sessions` rows whose session expired long enough ago to be unusable.
 *
 * ## What goes wrong without it
 *
 * A row is written at sign-in and marked revoked when somebody signs out or ends a device. A session
 * that merely *expires* tells nobody: the cookie stops working once `session.lifetime` has passed
 * since its last request, and the row stays with `revoked_at` null. So the table gains a row per
 * sign-in for ever, and `BLUEPRINT.md` §4.7's device list reports every one of them as a live device
 * — hundreds, for an account with two.
 *
 * ## Why the cutoff is read and not configured
 *
 * `session.lifetime` is the number that decides when the session itself died, so it is the only
 * honest basis for calling a row dead. Repeating it under this package's own config would let the two
 * drift, and the direction it drifts in matters: a cutoff shorter than the real lifetime deletes the
 * ledger rows of sessions still in use, and the owner loses the ability to end a device that is
 * genuinely signed in. `identity.sessions.prune_grace_minutes` is therefore only the margin added on
 * top — slack for clock skew between containers, and for a lifetime someone lengthened today.
 *
 * A missing or unreadable `session.lifetime` fails the run rather than falling back to a guess, for
 * the same reason: a guessed cutoff is a cutoff that may be too short, and the cost of guessing wrong
 * is deleting live rows. A failed run is visible in the ledger; a wrong one is not.
 *
 * ## No index, on purpose
 *
 * `last_used_at` is not indexed and should not be: it is written on *every authenticated request*
 * (`Kern\Platform\Identity\Support\SessionRegistrar::touch()`), so an index to speed up an hourly
 * delete would be paid for by every page load. This command is also what keeps the table small enough
 * for that to stay true.
 */
final class PruneExpiredSessionsCommand extends Command
{
    protected $signature = 'kern:identity:prune-sessions';

    protected $description = 'Delete the session ledger rows whose session has expired';

    public function handle(ConfigRepository $config): int
    {
        $lifetime = $config->get('session.lifetime');

        if (! is_numeric($lifetime)) {
            $this->error('session.lifetime is not set to a number; refusing to guess a cutoff.');

            return self::FAILURE;
        }

        $grace = $config->get('identity.sessions.prune_grace_minutes');
        // Plus how stale a session in use may let its row run (`SessionRegistrar::TOUCH_INTERVAL_MINUTES`).
        $minutes = (int) $lifetime + (is_numeric($grace) ? (int) $grace : 0) + SessionRegistrar::TOUCH_INTERVAL_MINUTES;

        $cutoff = now('UTC')->subMinutes($minutes);

        $deleted = UserSession::query()
            ->where('last_used_at', '<', $cutoff)
            ->delete();

        // `delete()` on an Eloquent builder is typed `mixed` — on a *model* it returns a bool — so
        // the row count has to be narrowed before it can be reported as one.
        $count = is_int($deleted) ? $deleted : 0;

        $this->info($count === 0
            ? 'No expired sessions to remove.'
            : "Removed {$count} expired session row(s) last used before {$cutoff->toIso8601String()}.");

        return self::SUCCESS;
    }
}
