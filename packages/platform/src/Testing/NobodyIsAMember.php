<?php

declare(strict_types=1);

namespace Kern\Platform\Testing;

use Kern\Platform\Tenancy\Contracts\TenantGate;

/** A gate that refuses everything, for proving the refusal reaches the caller. */
final readonly class NobodyIsAMember implements TenantGate
{
    public function roleFor(string $userId, string $tenantId): ?string
    {
        return null;
    }

    public function tenantsFor(string $userId): array
    {
        return [];
    }
}
