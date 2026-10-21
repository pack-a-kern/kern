<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\Enums;

/**
 * `BLUEPRINT.md` §5.3 — roles a `User` can hold against a `TaxSubject` via `subject_memberships`.
 * Ordered here from most to least privileged; `Kern\Platform\Tenancy\TenantContext` exposes
 * the resolved role but this task defines no permission matrix beyond the role name itself — that
 * is for whichever module first needs to distinguish, e.g., `ACCOUNTANT` from `VIEWER` in practice.
 */
enum MembershipRole: string
{
    case OWNER = 'OWNER';
    case ADMIN = 'ADMIN';
    case ACCOUNTANT = 'ACCOUNTANT';
    case VIEWER = 'VIEWER';

    /**
     * The roles that open a taxpayer today: OWNER, and no other.
     *
     * The other three have names and no rules yet — nothing says what a VIEWER may not do — and a
     * name with no rule behind it was read as no limit at all: a VIEWER could change and delete
     * everything an owner could (docs/SECURITY-FINDINGS.md, "Per-tenant roles"). So until sharing
     * defines what each may do, holding one opens nothing. A role joins this list together with the
     * rules that limit it, never before.
     *
     * @return list<self>
     */
    public static function opening(): array
    {
        return [self::OWNER];
    }

    public function opens(): bool
    {
        return in_array($this, self::opening(), true);
    }
}
