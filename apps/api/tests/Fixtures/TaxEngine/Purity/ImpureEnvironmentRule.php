<?php

declare(strict_types=1);

namespace Tests\Fixtures\TaxEngine\Purity;

/**
 * DELIBERATELY IMPURE FIXTURE — see `ImpureNowRule.php`'s docblock. Proves the scanner catches an
 * environment read.
 */
final class ImpureEnvironmentRule
{
    public function evaluate(): string
    {
        return (string) env('APP_KEY');
    }
}
