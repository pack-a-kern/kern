<?php

declare(strict_types=1);

namespace App\Domain\Declarations\Exceptions;

use App\Domain\Declarations\Enums\DeclarationStatus;
use InvalidArgumentException;

final class InvalidSubmissionOutcomeException extends InvalidArgumentException
{
    public static function forStatus(DeclarationStatus $status): self
    {
        return new self("A submission outcome must be ACCEPTED or REJECTED, got {$status->value}.");
    }
}
