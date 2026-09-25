<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Declarations\Exceptions;

use RuntimeException;

/**
 * `docs/AGENTS.md` rule 18 — "retroactively change a submitted declaration" — enforced in code by
 * `Kern\TaxRomania\Declarations\Models\Declaration`'s own `saving` guard, not only by convention: once
 * `locked_at` is set, every column but `status` (and only the single `SUBMITTED -> ACCEPTED
 * |REJECTED` flip) refuses to save at all.
 */
final class DeclarationIsImmutableException extends RuntimeException
{
    public static function forDeclaration(string $declarationId): self
    {
        return new self(
            "Declaration {$declarationId} is locked (submitted) and cannot be mutated. " .
            'File a RECTIFICATIVE declaration instead.',
        );
    }
}
