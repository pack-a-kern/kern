<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine\Enums;

/**
 * TaxEngine's own view of `fiscal_parameters.value_type` — how
 * {@see \App\Domain\TaxEngine\DataTransferObjects\ResolvedFiscalParameter}'s `valueJson` must be
 * interpreted (`asMoney()`/`asDecimalRate()`/`asInteger()`/`asDate()`/`asJson()`).
 *
 * Deliberately a second enum, not a reuse of `Kern\Legislation\Enums\FiscalParameterValueType`
 * — see `docs/adr/0010-tax-engine-ports-and-adapters.md`. `App\Domain\FiscalParameters\Providers\
 * EloquentFiscalParameterProvider` maps TaxKnowledge's enum to this one at the adapter boundary
 * (`self::from($tkValueType->value)`) — the case names and backing values are, and must remain,
 * identical between the two enums.
 */
enum FiscalParameterValueType: string
{
    case MONEY = 'MONEY';
    case DECIMAL = 'DECIMAL';
    case INTEGER = 'INTEGER';
    case DATE = 'DATE';
    case JSON = 'JSON';
}
