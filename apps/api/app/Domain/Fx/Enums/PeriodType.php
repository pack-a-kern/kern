<?php

declare(strict_types=1);

namespace App\Domain\Fx\Enums;

/** `fx_period_averages.period_type` (`BLUEPRINT.md` §6.9). */
enum PeriodType: string
{
    case MONTH = 'MONTH';
    case YEAR = 'YEAR';
}
