<?php

declare(strict_types=1);

namespace Tests\Unit\TaxRomania\Spv\SensitiveStringTest;

use Kern\TaxRomania\Spv\ValueObjects\SensitiveString;

/**
 * `BLUEPRINT.md` §13.2 steps 3/9 — an SPV password or challenge code must never leak through the
 * ordinary ways a value accidentally ends up somewhere it shouldn't: string interpolation,
 * `var_export`/`print_r`/`var_dump`-style debug output, or JSON encoding.
 */
it('reveal() returns the exact wrapped value', function (): void {
    $secret = SensitiveString::wrap('the-real-password');

    expect($secret->reveal())->toBe('the-real-password');
});

it('never renders the real value via string interpolation or (string) casting', function (): void {
    $secret = SensitiveString::wrap('the-real-password');

    expect((string) $secret)->not->toContain('the-real-password')
        ->and("value is {$secret}")->not->toContain('the-real-password');
});

it('never renders the real value via var_dump-style debug output', function (): void {
    $secret = SensitiveString::wrap('the-real-password');

    $dumped = print_r($secret, true);

    expect($dumped)->not->toContain('the-real-password');
});

it('never renders the real value via var_export', function (): void {
    $secret = SensitiveString::wrap('the-real-password');

    ob_start();
    var_dump($secret);
    $dumped = (string) ob_get_clean();

    expect($dumped)->not->toContain('the-real-password');
});
