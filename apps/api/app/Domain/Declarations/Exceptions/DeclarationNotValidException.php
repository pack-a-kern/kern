<?php

declare(strict_types=1);

namespace App\Domain\Declarations\Exceptions;

use RuntimeException;

final class DeclarationNotValidException extends RuntimeException
{
    public static function forDeclaration(string $declarationId): self
    {
        return new self("Declaration {$declarationId} is not VALID; it cannot be submitted yet.");
    }
}
