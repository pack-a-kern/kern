<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine\DataTransferObjects;

use App\Domain\Fx\ValueObjects\DecimalRate;
use App\Domain\TaxEngine\ValueObjects\Money;

/**
 * What a `App\Domain\TaxEngine\Contracts\TaxRuleContract` implementation returns — everything
 * `tax_assessment_lines` (`BLUEPRINT.md` §6.7) needs, plus the {@see CalculationTrace} that justifies
 * it. A rule builds this itself; nothing downstream recomputes `tax`/`taxableBase` from anything
 * else.
 */
final readonly class TaxRuleOutcome
{
    public function __construct(
        public string $taxCode,
        public string $categoryCode,
        public Money $taxableBase,
        public ?DecimalRate $rate,
        public Money $tax,
        public string $explanationKey,
        public CalculationTrace $trace,
    ) {
    }
}
