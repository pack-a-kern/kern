<?php

declare(strict_types=1);

use Kern\TaxRomania\Rental\Services\OwnershipShareCalculator;

/**
 * `BLUEPRINT.md` §33's co-ownership constraint made concrete: every value here is hand-computed, not
 * approximated (`docs/AGENTS.md` §2).
 */
beforeEach(function (): void {
    $this->calculator = new OwnershipShareCalculator();
});

it('computes an exact half share with no rounding needed', function (): void {
    // 200000 * 50.00 / 100 = 100000.00 exactly.
    expect($this->calculator->shareOf(200_000, '50.00'))->toBe(100_000);
});

it('computes an exact share for a non-round percentage', function (): void {
    // 100000 * 33.33 / 100 = 33330.00 exactly.
    expect($this->calculator->shareOf(100_000, '33.33'))->toBe(33_330);
});

it('rounds a fractional minor-unit share half up', function (): void {
    // 1 * 50.00 / 100 = 0.50 -> rounds up to 1.
    expect($this->calculator->shareOf(1, '50.00'))->toBe(1);

    // 3 * 33.33 / 100 = 0.9999 -> rounds up to 1.
    expect($this->calculator->shareOf(3, '33.33'))->toBe(1);

    // 100 * 0.4 / 100 = 0.40 -> rounds down to 0.
    expect($this->calculator->shareOf(100, '0.40'))->toBe(0);
});

it('returns the full amount for full (100%) ownership', function (): void {
    expect($this->calculator->shareOf(150_000, '100.00'))->toBe(150_000);
});

it('returns zero for a zero rent amount regardless of the share', function (): void {
    expect($this->calculator->shareOf(0, '50.00'))->toBe(0);
});
