<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Rental\Services;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Kern\TaxRomania\Rental\Models\RentalContract;

/**
 * How far a contract's owed rent is worked out: to its end, or to the end of this year.
 *
 * Exclusive, like every period end in this module. An open-ended contract owes rent forever, so a
 * limit is needed, and the end of the current year is the one that answers every question a person
 * or a tax return asks — this year so far, and every year before it.
 */
final class RentalAccrualHorizon
{
    public static function for(RentalContract $contract, ?CarbonImmutable $today = null): DateTimeImmutable
    {
        $startOfNextYear = ($today ?? CarbonImmutable::now('UTC'))->startOfYear()->addYear();
        $end = $contract->end_date?->toImmutable();

        return $end !== null && $end < $startOfNextYear ? $end : $startOfNextYear;
    }
}
