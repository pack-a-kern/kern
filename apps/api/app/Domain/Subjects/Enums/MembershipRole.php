<?php

declare(strict_types=1);

namespace App\Domain\Subjects\Enums;

/**
 * `BLUEPRINT.md` §5.3 — roles a `User` can hold against a `TaxSubject` via `subject_memberships`.
 * Ordered here from most to least privileged; `App\Support\Tenancy\TenantContext` exposes
 * the resolved role but this task defines no permission matrix beyond the role name itself — that
 * is for whichever module first needs to distinguish, e.g., `ACCOUNTANT` from `VIEWER` in practice.
 */
enum MembershipRole: string
{
    case OWNER = 'OWNER';
    case ADMIN = 'ADMIN';
    case ACCOUNTANT = 'ACCOUNTANT';
    case VIEWER = 'VIEWER';
}
