<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Rental\Exceptions;

use InvalidArgumentException;

/**
 * An amendment's `effective_from` must be strictly after the currently in-force version's own
 * `valid_from`, and — if the contract already has an `end_date` — strictly before it. This is
 * what keeps `rental_contract_versions` a strictly-ordered, non-overlapping, gap-free timeline
 * (`Kern\TaxRomania\Rental\Actions\AmendRentalContract` never inserts into the middle of history).
 */
final class InvalidAmendmentDateException extends InvalidArgumentException
{
    public static function notAfterCurrentVersion(string $effectiveFrom, string $currentValidFrom): self
    {
        return new self(
            "Amendment effective date {$effectiveFrom} must be strictly after the current "
            . "version's start date {$currentValidFrom}."
        );
    }

    public static function notBeforeContractEnd(string $effectiveFrom, string $endDate): self
    {
        return new self("Amendment effective date {$effectiveFrom} must be strictly before the contract's end date {$endDate}.");
    }
}
