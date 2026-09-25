<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Identity\Support\SessionRegistrar;

/**
 * `POST /api/v1/auth/logout` — behind `auth:web`. Revokes the `user_sessions` row for the current
 * session (not just `Auth::logout()`'s own cookie invalidation) so it disappears from "your active
 * sessions" immediately, then destroys the session itself.
 */
final class LogoutController
{
    public function __invoke(Request $request, SessionRegistrar $sessions, AuditChainService $audit): JsonResponse
    {
        /** @var \Kern\Platform\Identity\Models\User $user */
        $user = $request->user();
        $sessionId = $request->session()->getId();

        $sessions->revoke($sessionId);

        $audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $user->id,
            eventType: 'AuthSessionRevoked',
            resourceType: 'UserSession',
            resourceId: $sessionId,
            payloadHash: $audit->hashPayload(['reason' => 'logout']),
        ));

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['loggedOut' => true]);
    }
}
