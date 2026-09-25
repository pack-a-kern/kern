<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Declarations\Exceptions;

use InvalidArgumentException;
use Kern\TaxRomania\Declarations\Enums\DeclarationStatus;

final class InvalidSubmissionOutcomeException extends InvalidArgumentException
{
    public static function forStatus(DeclarationStatus $status): self
    {
        return new self("A submission outcome must be ACCEPTED or REJECTED, got {$status->value}.");
    }
}
