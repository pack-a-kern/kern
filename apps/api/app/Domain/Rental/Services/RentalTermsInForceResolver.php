<?php

declare(strict_types=1);

namespace App\Domain\Rental\Services;

use App\Domain\Rental\Exceptions\NoContractVersionInForceException;
use App\Domain\Rental\Models\RentalContractVersion;
use DateTimeImmutable;
use Illuminate\Support\Collection;

/**
 * "The terms in force on date X" — exactly one `rental_contract_versions` row, never a guess and
 * never a blend of two versions (this milestone's own stated hard requirement). A version with
 * `valid_to === null` is treated as open-ended (extends to `+infinity`).
 *
 * Date comparisons use `<=>`, never `==`/`!=`/`<`/`<=` directly on `Carbon`/`DateTimeImmutable`
 * mixed with plain comparison operators for equality — `docs/CODE-QUALITY.md` §4b: Pint's
 * `strict_comparison` fixer silently rewrites `==`/`!=` to `===`/`!==`, which is always-true/
 * always-false object-identity comparison for two distinct instances, regardless of value.
 */
final class RentalTermsInForceResolver
{
    /**
     * @param Collection<int, RentalContractVersion> $versions
     */
    public function resolve(Collection $versions, DateTimeImmutable $date): RentalContractVersion
    {
        foreach ($versions as $version) {
            $validFrom = $version->valid_from->toImmutable();
            $validTo = $version->valid_to?->toImmutable();

            $startsOnOrBefore = ($validFrom <=> $date) <= 0;
            $endsAfter = $validTo === null || ($validTo <=> $date) > 0;

            if ($startsOnOrBefore && $endsAfter) {
                return $version;
            }
        }

        $first = $versions->first();

        throw NoContractVersionInForceException::forDate(
            $first instanceof RentalContractVersion ? $first->rental_contract_id : 'unknown',
            $date->format('Y-m-d'),
        );
    }
}
