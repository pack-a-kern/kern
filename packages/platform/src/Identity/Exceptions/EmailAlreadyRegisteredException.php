<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Exceptions;

use RuntimeException;

final class EmailAlreadyRegisteredException extends RuntimeException
{
    public static function create(): self
    {
        return new self('An account with this email already exists.');
    }
}
