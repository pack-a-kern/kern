<?php

declare(strict_types=1);

namespace Tests\Fixtures\TaxEngine\Purity;

use DateTimeImmutable;

/**
 * DELIBERATELY IMPURE FIXTURE — see `ImpureNowRule.php`'s docblock. Proves the scanner catches
 * `new DateTimeImmutable()` with no argument — a wall-clock read by another name.
 */
final class ImpureNoArgumentDateTimeRule
{
    public function evaluate(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
