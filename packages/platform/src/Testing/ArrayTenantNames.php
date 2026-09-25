<?php

declare(strict_types=1);

namespace Kern\Platform\Testing;

use Kern\Platform\Tenancy\Contracts\TenantNames;

/**
 * What a tenant is called, held in a static array.
 *
 * The third and last thing the platform asks of an application. Static so a test can set a name and
 * the container's own instance answers with it.
 */
final class ArrayTenantNames implements TenantNames
{
    /** @var array<string, list<string>> */
    public static array $names = [];

    /** @return list<string> */
    public function namesFor(string $tenantId): array
    {
        return self::$names[$tenantId] ?? [];
    }
}
