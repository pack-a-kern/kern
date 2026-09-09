<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Enums;

/** `fiscal_parameters.value_type` (`BLUEPRINT.md` §6.8) — how `value_json` must be interpreted. */
enum FiscalParameterValueType: string
{
    case MONEY = 'MONEY';
    case DECIMAL = 'DECIMAL';
    case INTEGER = 'INTEGER';
    case DATE = 'DATE';
    case JSON = 'JSON';
}
