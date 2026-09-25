<?php

declare(strict_types=1);

namespace App\Domain\Subjects\Tenancy;

use App\Domain\Subjects\Models\SecurityEvent;
use App\Domain\Subjects\Models\SubjectMembership;
use Kern\Platform\Tenancy\Contracts\TenantGate;

/**
 * What "may act for" means here: a membership of this taxpayer that has not been revoked.
 *
 * The platform's tenant context asks this on every binding. A revoked membership answers exactly
 * as a missing one does — a withdrawn grant is not a softer form of access — and both are written
 * to `security_events`, because a refusal nobody records is a refusal nobody learns about.
 */
final readonly class MembershipTenantGate implements TenantGate
{
    public function roleFor(string $userId, string $tenantId): ?string
    {
        $membership = SubjectMembership::query()
            ->where('subject_id', $tenantId)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->first();

        if ($membership === null) {
            SecurityEvent::create([
                'event_type' => 'CROSS_TENANT_ACCESS_DENIED',
                'user_id' => $userId,
                'subject_id' => $tenantId,
                'context_json' => ['reason' => 'no_active_membership'],
                'occurred_at' => now('UTC'),
            ]);

            return null;
        }

        return $membership->role->value;
    }
}
