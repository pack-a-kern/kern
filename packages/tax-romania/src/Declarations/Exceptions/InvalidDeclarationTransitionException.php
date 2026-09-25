<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Declarations\Exceptions;

use Kern\TaxRomania\Declarations\Enums\DeclarationStatus;
use RuntimeException;

final class InvalidDeclarationTransitionException extends RuntimeException
{
    public static function forTransition(string $declarationId, DeclarationStatus $from, DeclarationStatus $to): self
    {
        return new self(
            "Declaration {$declarationId} cannot transition from {$from->value} to {$to->value}.",
        );
    }
}
