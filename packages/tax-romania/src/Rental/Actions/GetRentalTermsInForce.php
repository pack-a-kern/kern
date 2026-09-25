<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Rental\Actions;

use DateTimeImmutable;
use Kern\TaxRomania\Rental\Models\RentalContract;
use Kern\TaxRomania\Rental\Models\RentalContractVersion;
use Kern\TaxRomania\Rental\Services\RentalTermsInForceResolver;

/**
 * The public entry point for "what were this contract's terms in force on date X" — exactly one
 * `rental_contract_versions` row, never a blend of two (`Kern\TaxRomania\Rental\Services\
 * RentalTermsInForceResolver`, `Kern\TaxRomania\Rental\Exceptions\NoContractVersionInForceException`
 * if none covers the date). A future controller/report/RAG-explanation surface calls this rather
 * than re-deriving the temporal-resolution logic itself.
 */
final readonly class GetRentalTermsInForce
{
    public function __construct(
        private RentalTermsInForceResolver $resolver,
    ) {
    }

    public function __invoke(RentalContract $contract, DateTimeImmutable $date): RentalContractVersion
    {
        return $this->resolver->resolve($contract->versions()->get(), $date);
    }
}
