<?php

declare(strict_types=1);

namespace Kern\Legislation\Fetching\Exceptions;

use RuntimeException;

final class TooManyRedirectsException extends RuntimeException
{
    public static function forUrl(string $url): self
    {
        return new self(sprintf('Exceeded the maximum allowed redirects fetching "%s".', $url));
    }
}
