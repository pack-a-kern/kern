<?php

declare(strict_types=1);

namespace App\Domain\Subjects\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown by `App\Domain\Subjects\Tenancy\TenantContext::authorizeAndActivate()` when a user has
 * no active (non-revoked) `SubjectMembership` for the requested `TaxSubject` — including a
 * membership that exists but has been revoked, which must fail identically to "never existed"
 * (`docs/handoff/011-identity-tenancy.md`'s "a revoked membership loses access immediately").
 *
 * The message intentionally omits *why* access was denied beyond the two ids involved (never a
 * membership row's contents) — this exception can surface in logs, and `BLUEPRINT.md` §23 forbids
 * PII in logs.
 */
final class UnauthorizedSubjectAccessException extends RuntimeException
{
    public static function forUser(string $userId, string $subjectId): self
    {
        return new self(
            "User {$userId} has no active membership for subject {$subjectId}.",
        );
    }
}
