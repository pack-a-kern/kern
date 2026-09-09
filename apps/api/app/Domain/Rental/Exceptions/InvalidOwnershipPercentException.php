<?php

declare(strict_types=1);

namespace App\Domain\Rental\Exceptions;

use InvalidArgumentException;

/**
 * `properties.ownership_percent_decimal` must be a decimal string in `(0, 100]` — a property with
 * `0%` ownership is not this subject's property to record, and one over `100%` is not a valid
 * share of anything (`BLUEPRINT.md` §33: co-ownership is a first-class design constraint, so this
 * value must always be trustworthy for `App\Domain\Rental\Services\OwnershipShareCalculator`).
 */
final class InvalidOwnershipPercentException extends InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self("Ownership percent must be a decimal string in (0, 100], got \"{$value}\".");
    }
}
