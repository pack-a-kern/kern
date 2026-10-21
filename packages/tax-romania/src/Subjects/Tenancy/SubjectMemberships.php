<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\Tenancy;

use Kern\Platform\Tenancy\Contracts\TenantMemberships;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;

/** Taxpayer memberships, answered for the platform from {@see SubjectMembership}'s own scopes. */
final readonly class SubjectMemberships implements TenantMemberships
{
    public function rolesByUser(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $byUser = [];

        foreach (SubjectMembership::query()->whereIn('user_id', $userIds)->active()->get(['user_id', 'role']) as $membership) {
            $byUser[$membership->user_id][] = $membership->role->value;
        }

        return $byUser;
    }

    public function removeAllFor(string $userId): void
    {
        SubjectMembership::query()->where('user_id', $userId)->delete();
    }
}
