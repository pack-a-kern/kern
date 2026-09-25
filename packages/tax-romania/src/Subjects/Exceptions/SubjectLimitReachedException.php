<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\Exceptions;

use RuntimeException;

/**
 * The account already holds as many taxpayers as its role allows.
 *
 * Carries the limit, not the count: a caller telling someone "you have 3" when the answer is "3 is
 * all you get" explains nothing, and the count is on their screen already.
 */
final class SubjectLimitReachedException extends RuntimeException
{
    private function __construct(public readonly int $limit, string $message)
    {
        parent::__construct($message);
    }

    public static function of(int $limit): self
    {
        return new self($limit, sprintf('This account may register at most %d taxpayers.', $limit));
    }
}
