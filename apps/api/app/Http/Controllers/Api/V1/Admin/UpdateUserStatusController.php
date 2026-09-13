<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Audit\DataTransferObjects\AuditEventInput;
use App\Domain\Audit\Services\AuditChainService;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * `PUT /api/v1/admin/users/{user}/status` — suspends or reactivates an account.
 *
 * Suspending is the reversible half of removing access; accounts are never deleted here, because
 * `audit_events.actor_id` references them with `ON DELETE RESTRICT` — the audit chain must keep
 * naming whoever performed each past action, so an account that has ever done anything cannot be
 * removed without breaking the trail.
 */
final class UpdateUserStatusController extends Controller
{
    public function __invoke(Request $request, User $user, AuditChainService $audit): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(UserStatus::class)],
        ]);

        $status = UserStatus::from((string) $validated['status']);
        $actor = $request->user();

        abort_if(
            $actor !== null && $actor->getKey() === $user->getKey() && $status !== UserStatus::ACTIVE,
            422,
            'You cannot suspend your own account.',
        );

        // An erased account is a tombstone: its password, passkeys and authenticator are gone, so
        // moving it back to ACTIVE would claim it can sign in when nothing can. Erasure is the one
        // status change with no way back.
        abort_if(
            $user->status === UserStatus::DELETED,
            422,
            'This account has been erased and cannot be changed.',
        );

        $before = $user->status;
        $user->status = $status;
        $user->save();

        $actorKey = $actor?->getKey();
        $actorId = is_scalar($actorKey) ? (string) $actorKey : null;
        $userKey = $user->getKey();
        $userId = is_scalar($userKey) ? (string) $userKey : '';

        $audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $actorId,
            eventType: 'USER_STATUS_CHANGED',
            resourceType: 'user',
            resourceId: $userId,
            payloadHash: $audit->hashPayload(['before' => $before->value, 'after' => $status->value]),
        ));

        return response()->json(['status' => $status->value]);
    }
}
