<?php

declare(strict_types=1);

namespace Kern\Platform\Tenancy\Contracts;

/**
 * Who holds which role in which tenant, for the platform screens that are about people rather than
 * tenants — the users directory, and erasing an account.
 *
 * Where memberships are kept is the application's answer, as with {@see TenantGate}: the platform
 * used to read and delete the application's table by name, so a rule written there ("a revoked
 * membership is history, not access") had a second copy here that nothing kept in step.
 */
interface TenantMemberships
{
    /**
     * The roles each of these users holds now, one entry per membership in force.
     *
     * @param  list<string>  $userIds
     * @return array<string, list<string>>
     */
    public function rolesByUser(array $userIds): array;

    /** Ends every membership this user holds — access to every tenant, at once. */
    public function removeAllFor(string $userId): void;
}
