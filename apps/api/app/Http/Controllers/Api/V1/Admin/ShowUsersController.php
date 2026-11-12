<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kern\Platform\Identity\DataTransferObjects\UserDirectoryFilter;
use Kern\Platform\Identity\Enums\UserStatus;
use Kern\Platform\Identity\Queries\AdminUserDirectory;
use Kern\Platform\Routing\PageSizes;
use Kern\TaxRomania\Subjects\Queries\MembershipRoleCounts;

/**
 * `GET /api/v1/admin/users?email=&status=&role=&page=&per_page=` — one page of the people who can sign
 * in, their MFA posture and their memberships, with the counts over every account.
 *
 * `email` is a whole address: it is matched through its blind index, never decrypted to be searched.
 *
 * `roles` here are the *platform* roles from `config/access.php`, which are enforced. They are a
 * different thing from `Kern\TaxRomania\Subjects\Enums\MembershipRole`, which is recorded on every
 * tax-subject membership and is still consulted by nothing — `grep` for `MembershipRole::` finds
 * only writes. `enforcement.membership_roles_enforced` reports that, so the screen cannot imply a
 * per-subject permission model that does not exist yet.
 */
final class ShowUsersController
{
    public function __invoke(Request $request, AdminUserDirectory $directory, MembershipRoleCounts $roleCounts, PageSizes $sizes): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['nullable', 'string', 'max:254'],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'role' => ['nullable', 'string', Rule::in(array_keys(config()->array('access.roles')))],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => $sizes->rule(),
        ]);

        $email = is_string($validated['email'] ?? null) && trim($validated['email']) !== '' ? $validated['email'] : null;

        return response()->json([
            ...$directory->page(new UserDirectoryFilter(
                email: $email,
                status: is_string($validated['status'] ?? null) ? UserStatus::from($validated['status']) : null,
                role: is_string($validated['role'] ?? null) ? $validated['role'] : null,
                page: isset($validated['page']) ? max(1, (int) $validated['page']) : 1,
                perPage: $sizes->of($validated['per_page'] ?? null),
            )),
            'roles' => $roleCounts(),
            'enforcement' => [
                // Reported as data rather than written into the UI as copy: when per-subject role
                // checks are implemented, this flag is what the screen should stop showing.
                'membership_roles_enforced' => false,
            ],
        ]);
    }
}
