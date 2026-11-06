<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Support\SessionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamps `user_sessions.last_used_at` on authenticated requests, so the ledger reflects when a
 * session was actually last used rather than only when it was opened.
 *
 * Without this, `last_used_at` moved only at login and at session-id rotation, and
 * `PruneExpiredSessionsCommand` — which deletes rows idle past the session lifetime plus a grace —
 * measured idleness from login. A session someone keeps using stayed alive (its cookie refreshes on
 * each request) while its ledger row was pruned out from under it after a few hours: it vanished from
 * the device list, and "sign out everywhere" and the password-change revoke, which act only on listed
 * rows, could no longer reach it. Suspension still ended it, through `EndInactiveAccountSession`, which
 * reads the account rather than the ledger — but the revoke controls a person reaches for are the
 * ledger's, so the ledger has to be true.
 *
 * `SessionRegistrar::touch()` only writes the one row and only while it is unrevoked, so a guest costs
 * nothing (skipped here) and a session already revoked this request is not resurrected. `last_used_at`
 * is deliberately unindexed: the prune it feeds runs hourly, so the write must stay cheap and the scan
 * is the one that can afford to be slower.
 *
 * ## At most every few minutes
 *
 * Not on every request: a page and its background calls are several a second, and the prune measures
 * idleness in hours. The session remembers when it last wrote, so deciding costs no read, and writes
 * again once `SessionRegistrar::TOUCH_INTERVAL_MINUTES` has passed — which the prune adds to its cutoff.
 * Revoking is unaffected: it acts on the row, and a revoked row is never written here at all.
 */
final readonly class RecordSessionActivity
{
    /** When this session last wrote its row, as a Unix timestamp — kept in the session itself. */
    private const string TOUCHED_AT = 'auth.session_touched_at';

    public function __construct(private SessionRegistrar $sessions)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() instanceof User) {
            $session = $request->session();
            $now = now('UTC')->getTimestamp();
            $touchedAt = $session->get(self::TOUCHED_AT);

            if (! is_int($touchedAt) || $now - $touchedAt >= SessionRegistrar::TOUCH_INTERVAL_MINUTES * 60) {
                $this->sessions->touch($session->getId());
                $session->put(self::TOUCHED_AT, $now);
            }
        }

        return $next($request);
    }
}
