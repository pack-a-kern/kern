<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Rental\Exceptions;

use RuntimeException;

final class ContractAlreadyTerminatedException extends RuntimeException
{
    public static function forContract(string $rentalContractId): self
    {
        return new self("Rental contract {$rentalContractId} is already terminated.");
    }
}
