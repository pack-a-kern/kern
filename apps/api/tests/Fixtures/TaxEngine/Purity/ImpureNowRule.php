<?php

declare(strict_types=1);

namespace Tests\Fixtures\TaxEngine\Purity;

/**
 * DELIBERATELY IMPURE FIXTURE — never instantiated or autoload-relevant, scanned by
 * `tests/Unit/TaxRomania/TaxEngine/RulePuritySourceScannerTest.php` only, to prove
 * `Tests\Support\TaxEngineRulePurity\RulePuritySourceScanner` actually catches a wall-clock read.
 */
final class ImpureNowRule
{
    public function evaluate(): string
    {
        return (string) now();
    }
}
