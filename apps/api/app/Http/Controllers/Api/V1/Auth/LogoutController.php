<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Audit\DataTransferObjects\AuditEventInput;
use App\Domain\Audit\Services\AuditChainService;
use App\Domain\Identity\Support\SessionRegistrar;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * `POST /api/v1/auth/logout` — behind `auth:web`. Revokes the `user_sessions` row for the current
 * session (not just `Auth::logout()`'s own cookie invalidation) so it disappears from "your active
 * sessions" immediately, then destroys the session itself.
 */
final class LogoutController extends Controller
{
    public function __invoke(Request $request, SessionRegistrar $sessions, AuditChainService $audit): JsonResponse
    {
        /** @var \App\Domain\Identity\Models\User $user */
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
