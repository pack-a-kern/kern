<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Enums;

/**
 * How `Kern\TaxRomania\TaxEngine\ValueObjects\Money` resolves a fractional minor-unit result to an
 * integer (`BLUEPRINT.md` §11.3 — "rounding rule explicit per computation", never an implicit
 * default baked into `Money` itself). Every `Money` method that can produce a fraction
 * (`multipliedByDecimal()`, `convertedTo()`, `allocate()`) takes one of these explicitly.
 *
 * `HALF_UP`/`HALF_DOWN` round the fractional half away from / towards zero respectively (the
 * common "round half away from zero" convention — symmetric for negative amounts, not "round
 * towards positive infinity"). `HALF_EVEN` is banker's rounding. `UP`/`DOWN` always round away
 * from / towards zero regardless of the fraction's size (ceiling/floor of the absolute value).
 */
enum RoundingMode: string
{
    case HALF_UP = 'HALF_UP';
    case HALF_DOWN = 'HALF_DOWN';
    case HALF_EVEN = 'HALF_EVEN';
    case UP = 'UP';
    case DOWN = 'DOWN';
}
