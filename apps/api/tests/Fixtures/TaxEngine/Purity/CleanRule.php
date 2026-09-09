<?php

declare(strict_types=1);

namespace Tests\Fixtures\TaxEngine\Purity;

use DateTimeImmutable;

/**
 * CLEAN FIXTURE — see `ImpureNowRule.php`'s docblock. Deliberately includes
 * `new DateTimeImmutable($explicitDate)` (an argument supplied, not a wall-clock read) to prove
 * `RulePuritySourceScanner` does not flag that shape.
 */
final class CleanRule
{
    public function evaluate(string $explicitDate): DateTimeImmutable
    {
        return new DateTimeImmutable($explicitDate);
    }
}
