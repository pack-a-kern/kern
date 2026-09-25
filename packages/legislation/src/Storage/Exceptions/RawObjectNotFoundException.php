<?php

declare(strict_types=1);

namespace Kern\Legislation\Storage\Exceptions;

use RuntimeException;

final class RawObjectNotFoundException extends RuntimeException
{
    public static function forPath(string $path): self
    {
        return new self(sprintf('No raw source object stored at "%s".', $path));
    }
}
