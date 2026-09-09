<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine\Enums;

/**
 * `BLUEPRINT.md` §6.9's closing note: "Nu presupunem că orice regulă fiscală folosește automat media
 * anuală... `TaxRule` trebuie să aleagă explicit: spot/day; previous banking day; monthly average;
 * annual average". This is why `App\Domain\TaxEngine\Services\FxConversionPolicy::convert()` takes
 * this as a mandatory, explicit parameter — never a default — supplied by whichever
 * `TaxRuleContract` implementation needs the conversion, never chosen by the policy itself.
 */
enum FxConversionMethod: string
{
    /** The published rate for the exact date requested. Throws if that date has none. */
    case SPOT = 'SPOT';

    /** The published rate for the nearest preceding date that has one (weekends/holidays). */
    case PREVIOUS_BANKING_DAY = 'PREVIOUS_BANKING_DAY';

    /** Arithmetic mean of the calendar month's published daily rates (`BLUEPRINT.md` §7.4). */
    case MONTHLY_AVERAGE = 'MONTHLY_AVERAGE';

    /** Arithmetic mean of the calendar year's 12 monthly averages, never of daily rates directly
     *  (`BLUEPRINT.md` §7.4: "cursul mediu anual ca media aritmetică simplă a cursurilor medii
     *  lunare"). */
    case ANNUAL_AVERAGE = 'ANNUAL_AVERAGE';
}
