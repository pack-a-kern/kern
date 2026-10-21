<?php

declare(strict_types=1);

namespace Kern\Platform\Testing;

use Kern\Platform\Tenancy\Contracts\TenantGate;

/**
 * Who may act for a tenant, in an application that has no memberships to consult.
 *
 * Says yes to everyone, because what this suite is testing is the *mechanism* — that a bound tenant
 * restricts every read and stamps every write. Whether a particular person should have been bound is
 * the question this contract exists to hand back to the application, and one test below binds a
 * refusing gate to prove the refusal is honoured.
 */
final readonly class EveryoneIsAMember implements TenantGate
{
    public function roleFor(string $userId, string $tenantId): string
    {
        return 'member';
    }

    /** None to list: "everyone is a member" names no tenants, it only never refuses one. */
    public function tenantsFor(string $userId): array
    {
        return [];
    }
}
