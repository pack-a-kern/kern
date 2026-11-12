<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\Exceptions;

use RuntimeException;

/** The account already has as many taxpayers in use as one may (`SUBJECTS_MAX_ACTIVE`): close one first. */
final class TooManyActiveSubjectsException extends RuntimeException
{
    public const string REASON = 'TOO_MANY_ACTIVE_SUBJECTS';

    private function __construct(public readonly int $limit, string $message)
    {
        parent::__construct($message);
    }

    public static function of(int $limit): self
    {
        return new self($limit, sprintf('At most %d taxpayers may be in use at once. Close one first.', $limit));
    }
}
