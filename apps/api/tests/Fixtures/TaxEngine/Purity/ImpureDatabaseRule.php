<?php

declare(strict_types=1);

namespace Tests\Fixtures\TaxEngine\Purity;

use Illuminate\Support\Facades\DB;

/**
 * DELIBERATELY IMPURE FIXTURE — see `ImpureNowRule.php`'s docblock. Proves the scanner catches a
 * direct database read.
 */
final class ImpureDatabaseRule
{
    public function evaluate(): int
    {
        return DB::table('tax_facts')->count();
    }
}
