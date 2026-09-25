<?php

declare(strict_types=1);

namespace Kern\Platform\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when a user asks to act for a tenant they hold no role for.
 *
 * Identical for "never had one" and "had one, revoked": a withdrawn membership is not a softer form
 * of access, and an error that distinguished them would answer the question "was this person ever
 * involved with this taxpayer" to anyone who asked.
 */
final class UnauthorizedTenantAccessException extends RuntimeException
{
    public static function forUser(string $userId, string $tenantId): self
    {
        return new self("User {$userId} may not act for tenant {$tenantId}.");
    }
}
