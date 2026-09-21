<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Fetching\Exceptions;

use RuntimeException;

/**
 * A source answered, but not with the document: a 404 page, a 500, a login wall.
 *
 * Without this the error page came back as the body — and was stored as evidence of what the
 * publisher said, parsed for links, or read as a file that "changed shape". A download that did not
 * succeed is refused here, once, for every source.
 */
final class UnexpectedStatusException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    public static function forUrl(string $url, int $status): self
    {
        return new self($status, sprintf('"%s" answered with HTTP %d rather than the document.', $url, $status));
    }

    /** Nothing is published at this address — the answer an archive gives for a day it has no file for. */
    public function isNotFound(): bool
    {
        return $this->status === 404;
    }
}
