<?php

declare(strict_types=1);

use Kern\TaxRomania\TaxEngine\ValueObjects\Money;

/**
 * Amounts leave the assessment endpoint as minor units.
 *
 * The presenter reads them out of a trace, where they are `Money::toString()` — a fixed machine form that is
 * right for a record read in five years and wrong on a screen, which put `2177598.46 RON` beside
 * `2.177.598,46 RON` on one row. Parsing a formatted string is usually a mistake; this one is defended by
 * asserting against `Money`'s own output rather than against a string somebody typed, so a change to that
 * format fails here instead of silently rendering a dash.
 */
it('reads back every shape Money writes', function (int $minor, string $currency): void {
    $written = (string) Money::ofMinorUnits($minor, $currency);

    $parse = new ReflectionMethod(
        Kern\TaxRomania\Http\Presenters\TaxEngine\AssessmentPresenter::class,
        'minorUnitsOf',
    );

    expect($parse->invoke(app(Kern\TaxRomania\Http\Presenters\TaxEngine\AssessmentPresenter::class), $written))
        ->toBe($minor);
})->with([
    'zero' => [0, 'RON'],
    'a few bani' => [7, 'RON'],
    'under a leu' => [99, 'RON'],
    'the freelance income' => [217759846, 'RON'],
    'negative' => [-4250, 'RON'],
    'another currency' => [120000, 'EUR'],
]);

it('returns nothing for a string that is not an amount', function (string $notMoney): void {
    $parse = new ReflectionMethod(
        Kern\TaxRomania\Http\Presenters\TaxEngine\AssessmentPresenter::class,
        'minorUnitsOf',
    );

    expect($parse->invoke(app(Kern\TaxRomania\Http\Presenters\TaxEngine\AssessmentPresenter::class), $notMoney))
        ->toBeNull();
})->with([
    'a threshold count' => ['24'],
    'a rate' => ['0.10'],
    'a word the trace uses' => ['no_income'],
    'an amount with no currency' => ['103800.00'],
    // Would parse as 10380000 if the places were not pinned, which is a hundredfold error in somebody's tax.
    'three decimal places' => ['103800.000 RON'],
]);
