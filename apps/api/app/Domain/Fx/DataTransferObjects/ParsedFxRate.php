<?php

declare(strict_types=1);

namespace App\Domain\Fx\DataTransferObjects;

use App\Domain\Fx\ValueObjects\DecimalRate;

/**
 * One `<Rate>` entry for one currency on one day, exactly as published: `rateDecimal` is the RON
 * amount for `multiplier` units of `currencyCode`, never pre-divided (see `fx_rates` migration's
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
