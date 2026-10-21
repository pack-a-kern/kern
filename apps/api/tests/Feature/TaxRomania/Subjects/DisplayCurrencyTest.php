<?php

declare(strict_types=1);

use Kern\Platform\Money\CurrencyCode;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/*
 * A taxpayer's display currency is read one way, loaded or not: its own choice, upper-cased, or lei
 * where there is none.
 */

it('reads the taxpayer\'s own choice, loaded or by id', function (): void {
    $subject = TaxSubject::factory()->create(['display_currency' => 'eur']);

    expect($subject->displayCurrency())->toBe('EUR')
        ->and(TaxSubject::displayCurrencyOf($subject->id))->toBe('EUR');
});

it('falls back to lei for an empty choice, or a taxpayer that cannot be read', function (): void {
    $subject = TaxSubject::factory()->create(['display_currency' => '']);

    expect($subject->displayCurrency())->toBe(CurrencyCode::BASE)
        ->and(TaxSubject::displayCurrencyOf($subject->id))->toBe(CurrencyCode::BASE)
        ->and(TaxSubject::displayCurrencyOf('01900000-0000-7000-8000-000000000000'))->toBe(CurrencyCode::BASE);
});
