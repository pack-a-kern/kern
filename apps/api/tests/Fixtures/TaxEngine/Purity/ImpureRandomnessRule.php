<?php

declare(strict_types=1);

namespace Tests\Fixtures\TaxEngine\Purity;

/**
 * DELIBERATELY IMPURE FIXTURE — see `ImpureNowRule.php`'s docblock. Proves the scanner catches
 * randomness.
 */
final class ImpureRandomnessRule
{
    public function evaluate(): int
    {
        return mt_rand(1, 100);
    }
}
