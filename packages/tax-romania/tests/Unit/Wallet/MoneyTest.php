<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Tests\Unit\Wallet\MoneyTest;

use Kern\TaxRomania\Wallet\ValueObjects\Exceptions\CurrencyMismatchException;
use Kern\TaxRomania\Wallet\ValueObjects\Exceptions\InvalidMoneyException;
use Kern\TaxRomania\Wallet\ValueObjects\Money;

it('stores an exact integer minor-unit amount, never a float', function (): void {
    $money = Money::fromMinorUnits(123_456, 'RON');

    expect($money->minorUnits())->toBe(123_456)
        ->and($money->currencyCode())->toBe('RON');
});

it('adds and subtracts same-currency amounts exactly', function (): void {
    $a = Money::fromMinorUnits(1000, 'RON');
    $b = Money::fromMinorUnits(250, 'RON');

    expect($a->add($b)->minorUnits())->toBe(1250)
        ->and($a->subtract($b)->minorUnits())->toBe(750);
});

it('refuses to combine amounts in different currencies', function (): void {
    $ron = Money::fromMinorUnits(1000, 'RON');
    $eur = Money::fromMinorUnits(1000, 'EUR');

    $ron->add($eur);
})->throws(CurrencyMismatchException::class);

it('rejects a currency code that is not exactly three uppercase letters', function (string $code): void {
    Money::fromMinorUnits(100, $code);
})->throws(InvalidMoneyException::class)->with(['ron', 'RO', 'RONN', '123']);

it('negates, and reports sign correctly', function (): void {
    $positive = Money::fromMinorUnits(500, 'RON');
    $negative = $positive->negated();

    expect($negative->minorUnits())->toBe(-500)
        ->and($positive->isPositive())->toBeTrue()
        ->and($negative->isNegative())->toBeTrue()
        ->and(Money::zero('RON')->isZero())->toBeTrue();
});

it('considers two amounts equal only when both minor units and currency match', function (): void {
    $a = Money::fromMinorUnits(100, 'RON');
    $b = Money::fromMinorUnits(100, 'RON');
    $c = Money::fromMinorUnits(100, 'EUR');

    expect($a->equals($b))->toBeTrue()
        ->and($a->equals($c))->toBeFalse();
});
