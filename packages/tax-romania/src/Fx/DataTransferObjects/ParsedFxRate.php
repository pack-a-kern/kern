<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Fx\DataTransferObjects;

use Kern\TaxRomania\Fx\ValueObjects\DecimalRate;

/**
 * One `<Rate>` entry for one currency on one day, exactly as published: `rateDecimal` is the RON
 * amount for `multiplier` units of `currencyCode`, never pre-divided (see `ext_fx_rates` migration's
 * docblock for why that division is deferred to every read instead).
 */
final readonly class ParsedFxRate
{
    public function __construct(
        public string $currencyCode,
        public int $multiplier,
        public DecimalRate $rateDecimal,
    ) {
    }
}
