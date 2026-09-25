<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Enums;

/** `deposits.interest_method` (`BLUEPRINT.md` §6.4) — verbatim enum literal. */
enum InterestMethod: string
{
    case SIMPLE = 'SIMPLE';
    case COMPOUND = 'COMPOUND';
}
