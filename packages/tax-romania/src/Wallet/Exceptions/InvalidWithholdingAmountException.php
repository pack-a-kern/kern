<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Exceptions;

use InvalidArgumentException;

final class InvalidWithholdingAmountException extends InvalidArgumentException
{
    public static function outOfRange(int $withholdingMinor, int $grossInterestMinor): self
    {
        return new self(
            "Withholding ({$withholdingMinor}) must be between 0 and the gross interest "
            . "({$grossInterestMinor})."
        );
    }
}
