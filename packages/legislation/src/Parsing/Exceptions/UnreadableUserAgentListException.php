<?php

declare(strict_types=1);

namespace Kern\Legislation\Parsing\Exceptions;

use RuntimeException;

final class UnreadableUserAgentListException extends RuntimeException
{
    public static function notTheList(): self
    {
        return new self('The User-Agent list is not JSON with an `updatedAt` time and a `user` list of names.');
    }

    public static function empty(): self
    {
        return new self('The User-Agent list names no browser this installation can tell apart.');
    }
}
