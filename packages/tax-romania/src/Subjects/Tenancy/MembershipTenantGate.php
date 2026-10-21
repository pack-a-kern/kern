<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\Tenancy;

use Kern\Platform\Security\RecordSecurityEvent;
use Kern\Platform\Security\SecurityEventType;
use Kern\Platform\Tenancy\Contracts\TenantGate;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;

/**
 * What "may act for" means here: a membership of this taxpayer that has not been revoked, in a
 * role that has rules ({@see MembershipRole::opening()} — OWNER, until sharing defines the others).
 *
 * The platform's tenant context asks this on every binding — each request, and each queued job that
 * acts for a taxpayer. A revoked membership, or one in a role without rules, answers exactly as a
 * missing one does — a withdrawn or undefined grant is not a softer form of access — and all three
 * are written to `security_events`, because a refusal nobody records is a refusal nobody learns about.
 */
final readonly class MembershipTenantGate implements TenantGate
{
    public function __construct(private RecordSecurityEvent $securityLog)
    {
    }

    public function roleFor(string $userId, string $tenantId): ?string
    {
        $membership = SubjectMembership::query()
            ->where('subject_id', $tenantId)
            ->where('user_id', $userId)
            ->active()
            ->first();

        if ($membership === null || ! $membership->role->opens()) {
            ($this->securityLog)(
                SecurityEventType::CROSS_TENANT_ACCESS_DENIED,
                $userId,
                $tenantId,
                ['reason' => $membership === null ? 'no_active_membership' : 'role_without_rules'],
            );

            return null;
        }

        return $membership->role->value;
    }

    public function tenantsFor(string $userId): array
    {
        /** @var list<string> */
        return SubjectMembership::query()->where('user_id', $userId)->opening()->pluck('subject_id')->all();
    }
}
