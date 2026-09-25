<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Exceptions;

use RuntimeException;

final class SessionNotFoundException extends RuntimeException
{
    public static function create(): self
    {
        return new self('No such session for this account.');
    }
}
