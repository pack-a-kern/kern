<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\Exceptions;

use Kern\TaxRomania\Spv\Enums\SpvAuthMethod;
use RuntimeException;

/** A browser-session action was attempted against a `CERTIFICATE` connection, or vice versa. */
final class SpvAuthMethodMismatchException extends RuntimeException
{
    public static function expected(SpvAuthMethod $expected, SpvAuthMethod $actual): self
    {
        return new self("Expected an SPV connection with auth method {$expected->value}, got {$actual->value}.");
    }
}
