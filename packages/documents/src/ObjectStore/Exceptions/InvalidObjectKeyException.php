<?php

declare(strict_types=1);

namespace Kern\Documents\ObjectStore\Exceptions;

use InvalidArgumentException;

final class InvalidObjectKeyException extends InvalidArgumentException
{
    public static function notAnIdentifier(string $label): self
    {
        return new self(sprintf('Object key %s must be a UUID or ULID.', $label));
    }
}
