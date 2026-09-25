<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Access\Services\AccessCatalogue;
use Kern\Platform\Identity\Enums\UserStatus;
use Kern\Platform\Identity\Models\User;

/**
 * `PUT /api/v1/admin/users/{user}/roles` — replaces a user's platform roles.
 *
 * Two guards that are not optional here:
 *
 *  - **You cannot remove your own last administrative role.** Locking yourself out of the only
 *    screen that can grant roles back is a one-click, irreversible mistake, and break-glass is an
 *    environment edit plus a container restart — not a recovery an operator should need.
 *  - **The last SUPER_ADMIN cannot be demoted.** Same reason, one level up: an installation with
 *    no super administrator can only be repaired from the shell.
 *
 * Recorded in the audit chain with the before and after sets, because "who granted this" is the
 * first question asked after an access incident.
 */
final class UpdateUserRolesController
{
    private const string SUPER_ADMIN = 'SUPER_ADMIN';

    public function __invoke(
        Request $request,
        User $user,
        AccessCatalogue $catalogue,
        AuditChainService $audit,
    ): JsonResponse {
        // Nothing to grant: an erased account cannot sign in, so a role on it is a permission
        // nobody can exercise — and a row that reads like a live administrator.
        abort_if(
            $user->status === UserStatus::DELETED,
            422,
            'This account has been erased and cannot be changed.',
        );

        $validated = $request->validate([
            'roles' => ['present', 'array'],
            'roles.*' => ['string', Rule::in($catalogue->roles())],
        ]);

        /** @var list<string> $roles */
        $roles = array_values(array_unique($validated['roles']));
        $before = $user->getRoleNames()->all();

        $actor = $request->user();

        if ($actor !== null && $actor->getKey() === $user->getKey() && $roles === []) {
            abort(422, 'You cannot remove your own last platform role.');
        }

        if (in_array(self::SUPER_ADMIN, $before, true) && ! in_array(self::SUPER_ADMIN, $roles, true)) {
            $remaining = User::role(self::SUPER_ADMIN)->where('id', '!=', $user->getKey())->count();

            abort_if($remaining === 0, 422, 'This is the last super administrator; grant the role to someone else first.');
        }

        $user->syncRoles($roles);

        $actorKey = $actor?->getKey();
        $actorId = is_scalar($actorKey) ? (string) $actorKey : null;
        $userKey = $user->getKey();
        $userId = is_scalar($userKey) ? (string) $userKey : '';

        $audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $actorId,
            eventType: 'ROLE_CHANGED',
            resourceType: 'user',
            resourceId: $userId,
            payloadHash: $audit->hashPayload(['before' => $before, 'after' => $roles]),
        ));

        return response()->json(['roles' => $roles]);
    }
}
