<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\Queries;

use Illuminate\Support\Facades\DB;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;

/**
 * How many distinct people hold each membership role right now.
 *
 * Lives in Subjects rather than Identity because `MembershipRole` is this module's concept.
 * Deptrac enforces that boundary and caught this being written on the wrong side.
 *
 * Every role is returned, including those with no members: a roles screen that hides empty roles
 * cannot answer "does anyone hold ADMIN?", which is the question it is usually opened for.
 */
final readonly class MembershipRoleCounts
{
    /**
     * @return list<array{role: string, members: int}>
     */
    public function __invoke(): array
    {
        $counts = [];

        // Only memberships in force: a revoked one is history, not access.
        $rows = DB::table('subject_memberships')
            ->whereNull('revoked_at')
            ->groupBy('role')
            ->selectRaw('role, count(distinct user_id) as members')
            ->pluck('members', 'role')
            ->all();

        foreach ($rows as $role => $members) {
            if (is_scalar($members)) {
                $counts[(string) $role] = (int) $members;
            }
        }

        $result = [];

        foreach (MembershipRole::cases() as $role) {
            $result[] = ['role' => $role->value, 'members' => $counts[$role->value] ?? 0];
        }

        return $result;
    }
}
