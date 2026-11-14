<?php

declare(strict_types=1);

use Kern\Platform\Money\Shares;

it('gives each part its share of the whole, by its key, to a hundredth of a percent', function (): void {
    expect(Shares::of(['deposits' => 15_000_000, 'general' => 5_000_000, 'cash' => 0]))
        ->toBe(['deposits' => '0.7500', 'general' => '0.2500', 'cash' => '0.0000'])
        ->and(Shares::of([1, 2]))->toBe(['0.3333', '0.6666']);
});

it('counts a part below zero as none, in the part and the whole', function (): void {
    expect(Shares::of(['general' => -7_000_000, 'deposits' => 3_000_000]))->toBe(['general' => '0.0000', 'deposits' => '1.0000']);
});

it('answers none for every part when there is nothing to divide', function (): void {
    expect(Shares::of(['deposits' => 0, 'general' => -1]))->toBe(['deposits' => '0', 'general' => '0']);
});
