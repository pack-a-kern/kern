<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Kern\Platform\Identity\Actions\RevokeAllSessions;
use Kern\Platform\Identity\Actions\RevokeSession;
use Kern\Platform\Identity\Exceptions\SessionNotFoundException;
use Kern\Platform\Identity\Models\UserSession;

/**
 * `GET /api/v1/auth/sessions`, `DELETE /api/v1/auth/sessions/{id}`,
 * `DELETE /api/v1/auth/sessions` — `BLUEPRINT.md` §4.7's device/session management. Behind
 * `auth:web`.
 */
final class SessionController
{
    public function index(Request $request): JsonResponse
    {
        /** @var \Kern\Platform\Identity\Models\User $user */
        $user = $request->user();
        $currentId = $request->session()->getId();

        $sessions = UserSession::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->orderByDesc('last_used_at')
            ->get()
            ->map(static fn (UserSession $session): array => [
                'id' => $session->id,
                'ip_address' => $session->ip_address,
                'user_agent' => $session->user_agent,
                'last_used_at' => $session->last_used_at->toIso8601String(),
                'created_at' => $session->created_at->toIso8601String(),
                'is_current' => $session->id === $currentId,
            ]);

        return response()->json(['sessions' => $sessions]);
    }

    /**
     * `Kern\Platform\Identity\Support\SessionRegistrar::revoke()` destroys the target session's
     * entry in the real session store — but if the target *is* the caller's own current session,
     * `Illuminate\Session\Middleware\StartSession` would otherwise unconditionally re-persist this
     * request's in-memory session attributes back to that same (just-destroyed) store id at the
     * end of the request, silently undoing the revocation. Calling `invalidate()` here (which
     * flushes the in-memory attributes and rotates to a fresh, empty id) is what makes
     * self-revocation actually stick — confirmed necessary by a real end-to-end test, not assumed.
     */
    public function destroy(Request $request, string $sessionId, RevokeSession $revoke): JsonResponse
    {
        /** @var \Kern\Platform\Identity\Models\User $user */
        $user = $request->user();
        $isCurrentSession = $sessionId === $request->session()->getId();

        try {
            $revoke($user, $sessionId);
        } catch (SessionNotFoundException $exception) {
            throw ValidationException::withMessages(['session' => [$exception->getMessage()]]);
        }

        if ($isCurrentSession) {
            $request->session()->invalidate();
        }

        return response()->json(['revoked' => true]);
    }

    /**
     * See `destroy()`'s docblock — the same "don't let StartSession resurrect the session it is
     * about to destroy" concern applies when `keep_current=false` includes the caller's own
     * session in the sweep.
     */
    public function destroyAll(Request $request, RevokeAllSessions $revokeAll): JsonResponse
    {
        /** @var \Kern\Platform\Identity\Models\User $user */
        $user = $request->user();

        $keepCurrent = $request->boolean('keep_current', true);
        $currentId = $request->session()->getId();
        $exceptId = $keepCurrent ? $currentId : null;

        $revoked = $revokeAll($user, $exceptId);

        if (! $keepCurrent) {
            $request->session()->invalidate();
        }

        return response()->json(['revoked_count' => $revoked]);
    }
}
