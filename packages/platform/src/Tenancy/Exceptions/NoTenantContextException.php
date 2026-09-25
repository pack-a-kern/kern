<?php

declare(strict_types=1);

namespace Kern\Platform\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when a tenant-scoped query runs with no tenant bound.
 *
 * Failing loudly is the whole point. The two silent alternatives are worse in both directions: a
 * scope that matches nothing turns "this taxpayer's transactions" into an empty page nobody can
 * explain, and one that matches everything hands one tenant another's rows. A query that cannot
 * say whose data it wants is a bug in the caller, and it is raised as one.
 */
final class NoTenantContextException extends RuntimeException
{
    public static function notBound(): self
    {
        return new self('No tenant is bound to this request or job.');
    }
}
