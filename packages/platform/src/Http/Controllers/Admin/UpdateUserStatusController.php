<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Access\SuperAdministratorRole;
use Kern\Platform\Identity\Actions\RevokeAllSessions;
use Kern\Platform\Identity\Enums\UserStatus;
use Kern\Platform\Identity\Models\User;

/**
 * `PUT /api/v1/admin/users/{user}/status` — suspends or reactivates an account.
 *
 * Suspending is the reversible half of removing access; accounts are never deleted here, because
 * `audit_events.actor_id` references them with `ON DELETE RESTRICT` — the audit chain must keep
 * naming whoever performed each past action, so an account that has ever done anything cannot be
 * removed without breaking the trail.
 */
final class UpdateUserStatusController
{
    public function __invoke(
        Request $request,
        User $user,
        AuditChainService $audit,
        RevokeAllSessions $revokeAll,
    ): JsonResponse {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(UserStatus::class)],
        ]);

        $status = UserStatus::from((string) $validated['status']);
        $actor = $request->user();

        abort_if(
            $actor !== null && $actor->getKey() === $user->getKey() && $status !== UserStatus::ACTIVE,
            422,
            __('platform::admin.status.self'),
        );

        // An erased account is a tombstone: its password, passkeys and authenticator are gone, so
        // moving it back to ACTIVE would claim it can sign in when nothing can. Erasure is the one
        // status change with no way back.
        abort_if(
            $user->status === UserStatus::DELETED,
            422,
            __('platform::admin.erased_account'),
        );

        // Only a super administrator may change a super administrator's status — the same rule erasure
        // and role changes already enforce. `users.manage` alone is not enough: an installation that
        // grants it to ADMIN would otherwise let an ordinary administrator suspend the super
        // administrators and lock the top role out from below it. (Suspending the *last* active super
        // administrator needs no separate guard: the only actor this check admits is another super
        // administrator, who is themselves active, so one always remains — and nobody may suspend
        // their own account, above.)
        if (SuperAdministratorRole::isHeldBy($user)) {
            abort_unless(
                $actor !== null && SuperAdministratorRole::isHeldBy($actor),
                403,
                __('platform::admin.status.super_administrator_only'),
            );
        }

        $before = $user->status;
        $user->status = $status;
        $user->save();

        // A suspension that left the sessions alive would be a label, not a lock.
        if ($status !== UserStatus::ACTIVE) {
            $revokeAll($user, null);
        }

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
