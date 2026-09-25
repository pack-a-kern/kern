<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\SessionRegistrar;
use App\Http\Controllers\Api\V1\Auth\Concerns\EstablishesAuthenticatedSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;

/**
 * Signing in as someone else, and coming back.
 *
 * ## What this really is
 *
 * Impersonation hands an administrator everything the person can see — which here means documents,
 * amounts and personal data encrypted under a taxpayer's own key. It is the most powerful thing
 * this application can do, and it is indistinguishable, in every business table, from the person
 * doing it themselves. Only the audit chain tells the two apart, which is why both the start and
 * the end are recorded before anything else happens.
 *
 * ## The rules, and why each exists
 *
 * - **Super administrators only.** Ordinary administration is about roles and status, which are
 *   changes anyone can see afterwards. This is about reading someone's data.
 * - **Never another super administrator.** Otherwise impersonation is a way to acquire the one
 *   role you were not granted, and the audit trail would show that person acting, not you.
 * - **Never yourself**, which teaches nothing and only muddies the trail.
 * - **Never an erased or suspended account**, whose whole point is that nobody can sign in as it.
 * - **The way back is kept in the session**, not in a cookie the impersonated user could change,
 *   and it survives only as long as that session.
 */
final class ImpersonationController extends Controller
{
    use EstablishesAuthenticatedSession;

    /** Where the original administrator is remembered while they are acting as somebody else. */
    private const string SESSION_KEY = 'auth.impersonator_id';

    public function start(
        Request $request,
        string $user,
        SessionRegistrar $sessions,
        AuditChainService $audit,
    ): JsonResponse {
        $administrator = $request->user();
        abort_unless($administrator !== null, 401);
        abort_unless($administrator->hasRole('SUPER_ADMIN'), 403);
        abort_if($request->session()->has(self::SESSION_KEY), 409, 'Already acting as another user.');

        $target = User::query()->findOrFail($user);

        abort_if($target->id === $administrator->id, 422, 'You are already signed in as yourself.');
        abort_if($target->hasRole('SUPER_ADMIN'), 403, 'A super administrator cannot be impersonated.');
        abort_if($target->status !== UserStatus::ACTIVE, 422, 'Only an active account can be impersonated.');

        // Recorded before the switch, with the administrator as the actor — afterwards the session
        // belongs to someone else, and an event written then would name the wrong person.
        $audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $administrator->id,
            eventType: 'ImpersonationStarted',
            resourceType: 'User',
            resourceId: $target->id,
            payloadHash: $audit->hashPayload(['target_user_id' => $target->id]),
        ));

        $administratorId = $administrator->id;

        $this->establishSessionFor($request, $target, $sessions);
        $request->session()->put(self::SESSION_KEY, $administratorId);

        return response()->json(['impersonating' => $target->id]);
    }

    public function stop(Request $request, SessionRegistrar $sessions, AuditChainService $audit): JsonResponse
    {
        $impersonatorId = $request->session()->get(self::SESSION_KEY);
        abort_unless(is_string($impersonatorId), 409, 'This session is not impersonating anyone.');

        $administrator = User::query()->findOrFail($impersonatorId);
        $current = $request->user();
        $targetId = $current === null ? $administrator->id : $current->id;

        $audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $administrator->id,
            eventType: 'ImpersonationStopped',
            resourceType: 'User',
            resourceId: $targetId,
            payloadHash: $audit->hashPayload(['target_user_id' => $targetId]),
        ));

        $request->session()->forget(self::SESSION_KEY);
        $this->establishSessionFor($request, $administrator, $sessions);

        return response()->json(['impersonating' => null]);
    }
}
