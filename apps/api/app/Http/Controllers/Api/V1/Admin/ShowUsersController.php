<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Subjects\Queries\MembershipRoleCounts;
use Illuminate\Http\JsonResponse;
use Kern\Platform\Identity\Queries\AdminUserDirectory;

/**
 * `GET /api/v1/admin/users` — the people who can sign in, their MFA posture and their memberships.
 *
 * `roles` here are the *platform* roles from `config/access.php`, which are enforced. They are a
 * different thing from `App\Domain\Subjects\Enums\MembershipRole`, which is recorded on every
 * tax-subject membership and is still consulted by nothing — `grep` for `MembershipRole::` finds
 * only writes. `enforcement.membership_roles_enforced` reports that, so the screen cannot imply a
 * per-subject permission model that does not exist yet.
 */
final class ShowUsersController
{
    public function __invoke(AdminUserDirectory $directory, MembershipRoleCounts $roleCounts): JsonResponse
    {
        return response()->json([
            ...$directory->list(),
            'roles' => $roleCounts(),
            'enforcement' => [
                // Reported as data rather than written into the UI as copy: when per-subject role
                // checks are implemented, this flag is what the screen should stop showing.
                'membership_roles_enforced' => false,
            ],
        ]);
    }
}
