<?php

declare(strict_types=1);

namespace Kern\Legislation\Legislatie\Exceptions;

use RuntimeException;

/** legislatie.just.ro answered, but not with what reading an act needs. */
final class PortalAnswerException extends RuntimeException
{
    public static function noToken(): self
    {
        return new self('The portal web service gave no token.');
    }

    public static function unreadable(string $action): self
    {
        return new self("The portal web service's answer to {$action} is not readable XML.");
    }

    public static function fault(string $action, string $message): self
    {
        return new self("The portal web service refused {$action}: {$message}");
    }

    public static function notExactlyOne(string $type, int $number, int $year, int $found): self
    {
        return new self("The portal lists {$found} acts {$type} {$number}/{$year}, where exactly one was expected.");
    }

    public static function noInForceDate(string $url): self
    {
        return new self("The act at {$url} has no consolidations, and the portal gives no date it came into force.");
    }

    public static function noConsolidations(string $url): self
    {
        return new self("The consolidated form at {$url} lists no consolidations.");
    }
}
