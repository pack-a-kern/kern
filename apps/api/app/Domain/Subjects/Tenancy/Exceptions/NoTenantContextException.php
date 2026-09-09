<?php

declare(strict_types=1);

namespace App\Domain\Subjects\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when code queries a `App\Domain\Subjects\Tenancy\ScopedToTaxSubject` model, or otherwise
 * asks `App\Domain\Subjects\Tenancy\TenantContext` for the current subject, without first calling
 * `TenantContext::authorizeAndActivate()` in this request/job.
 *
 * This is deliberately a hard failure, not "return no rows" or "return all rows" — `docs/handoff/
 * 010-crypto-hardening.md` established this project's own precedent ("fail loudly over degrade
 * gracefully" in any security-correctness path): a scope that silently matched nothing would look
 * like an empty-but-valid result to a caller who forgot to bind tenant context, and a scope that
 * silently matched everything would be the exact cross-tenant leak `docs/AGENTS.md` §8 forbids.
 * Neither should ever be mistaken for "working correctly."
 */
final class NoTenantContextException extends RuntimeException
{
    public static function notBound(): self
    {
        return new self(
            'No tenant context is bound for this request. Call '
            . 'TenantContext::authorizeAndActivate($userId, $subjectId) before querying a '
            . 'subject-scoped model, or explicitly opt out via '
            . 'Model::withoutGlobalScope(TaxSubjectScope::class) for a genuinely cross-tenant '
            . 'operation.',
        );
    }
}
