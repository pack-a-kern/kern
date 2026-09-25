<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Fx\Enums;

/** `ext_fx_period_averages.period_type` (`BLUEPRINT.md` §6.9). */
enum PeriodType: string
{
    case MONTH = 'MONTH';
    case YEAR = 'YEAR';
}
