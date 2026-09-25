<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Rental\Exceptions;

use RuntimeException;

/**
 * Thrown when a query for "the terms in force on date X" (`Kern\TaxRomania\Rental\Services\
 * RentalTermsInForceResolver`) finds no `rental_contract_versions` row whose `[valid_from,
 * valid_to)` contains the requested date — never guessed or defaulted to the nearest version
 * (`docs/AGENTS.md` §2: no fabricated result).
 */
final class NoContractVersionInForceException extends RuntimeException
{
    public static function forDate(string $rentalContractId, string $date): self
    {
        return new self("No rental_contract_versions row is in force for contract {$rentalContractId} on {$date}.");
    }
}
