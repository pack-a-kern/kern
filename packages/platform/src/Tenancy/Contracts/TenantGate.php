<?php

declare(strict_types=1);

namespace Kern\Platform\Tenancy\Contracts;

/**
 * Whether a user may act for a tenant, and in what role.
 *
 * The scoping mechanism is the same for every application — every row belongs to a tenant, every
 * read is restricted to the active one, every insert is stamped with it. *Who may act for whom* is
 * not: here it is a non-revoked membership of a taxpayer, elsewhere it is a seat in a workspace or
 * an ownership row. So the mechanism lives in the platform and asks this, and the application
 * answers it.
 *
 * An implementation answers with the role's name, or null where the user may not act at all. It is
 * also the right place to record the refusal: only the application knows what its own security log
 * is called.
 */
interface TenantGate
{
    /** The role this user holds for this tenant, or null where they hold none. */
    public function roleFor(string $userId, string $tenantId): ?string;
}
