<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Support;

use Illuminate\Contracts\Session\Session as SessionContract;
use Illuminate\Database\Eloquent\Builder;
use Kern\Platform\Identity\Models\UserSession;

/**
 * Keeps `user_sessions` (device/session listing, `BLUEPRINT.md` §4.7) in sync with the real session
 * store — see that table's migration docblock for why its primary key *is* the underlying
 * session-store id rather than a surrogate.
 *
 * Deliberately framework-session-free in its own method signatures (no `Illuminate\Http\Request`
 * parameter) except for the one place it must reach into the session store directly —
 * `revoke()`'s `$this->currentSession->getHandler()->destroy($sessionId)` — because
 * `SessionHandlerInterface::destroy()` is id-parameterised and works for *any* session id, not
 * only the request's own, exactly the capability "revoke another device" needs.
 * `$currentSession` is only ever used for its handler, never its own id/data, so injecting the
 * current request's bound `Session` instance (rather than something more elaborate) is correct
 * even when revoking a session that is not the caller's own.
 */
final readonly class SessionRegistrar
{
    public function __construct(private SessionContract $currentSession)
    {
    }

    public function register(string $userId, string $sessionId, ?string $ipAddress, ?string $userAgent): UserSession
    {
        return UserSession::query()->updateOrCreate(
            ['id' => $sessionId],
            [
                'user_id' => $userId,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'last_used_at' => now('UTC'),
                'created_at' => now('UTC'),
                'revoked_at' => null,
            ],
        );
    }

    /**
     * Follows a session-id regeneration so the ledger keeps pointing at the live session.
     *
     * Laravel rotates the session id whenever privilege changes — at login, and again when a
     * second factor is confirmed. The `user_sessions` row is keyed by that id, so a rotation that
     * is not mirrored here silently orphans the row: the owner's own session stops matching, shows
     * up as somebody else's device, and cannot be ended from the UI. Worse, "end other sessions"
     * then revokes the orphan and leaves the real session running and invisible.
     *
     * Falls back to registering a fresh row when no row matched, so a session that was never
     * recorded (or was revoked out from under us) still appears rather than vanishing.
     */
    public function rotate(
        string $fromSessionId,
        string $toSessionId,
        string $userId,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $updated = UserSession::query()
            ->whereKey($fromSessionId)
            ->whereNull('revoked_at')
            ->update(['id' => $toSessionId, 'last_used_at' => now('UTC')]);

        if ($updated === 0) {
            $this->register($userId, $toSessionId, $ipAddress, $userAgent);
        }
    }

    public function touch(string $sessionId): void
    {
        UserSession::query()
            ->whereKey($sessionId)
            ->whereNull('revoked_at')
            ->update(['last_used_at' => now('UTC')]);
    }

    /**
     * Marks the row revoked and destroys the matching entry in the real session store, so a
     * stolen/leaked session cookie for `$sessionId` stops working immediately, not merely stops
     * appearing in a future "your sessions" listing.
     */
    public function revoke(string $sessionId): void
    {
        $session = UserSession::query()->whereKey($sessionId)->first();

        if ($session === null || $session->isRevoked()) {
            return;
        }

        $session->revoked_at = now('UTC');
        $session->save();

        $this->currentSession->getHandler()->destroy($sessionId);
    }

    /**
     * @return int how many sessions were revoked
     */
    public function revokeAllForUser(string $userId, ?string $exceptSessionId = null): int
    {
        $sessions = UserSession::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->when(
                $exceptSessionId !== null,
                /** @param Builder<UserSession> $query */
                static fn (Builder $query): Builder => $query->where('id', '!=', $exceptSessionId),
            )
            ->get();

        foreach ($sessions as $session) {
            $this->revoke($session->id);
        }

        return $sessions->count();
    }
}
