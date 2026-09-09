<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Enums;

/** `deposits.rate_type` (`BLUEPRINT.md` §6.4) — verbatim enum literal. */
enum RateType: string
{
    case FIXED = 'FIXED';
    case VARIABLE = 'VARIABLE';
}
