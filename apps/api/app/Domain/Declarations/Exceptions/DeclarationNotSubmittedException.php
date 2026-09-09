<?php

declare(strict_types=1);

namespace App\Domain\Declarations\Exceptions;

use RuntimeException;

final class DeclarationNotSubmittedException extends RuntimeException
{
    public static function forDeclaration(string $declarationId): self
    {
        return new self("Declaration {$declarationId} is not SUBMITTED; a response cannot be recorded yet.");
    }
}
