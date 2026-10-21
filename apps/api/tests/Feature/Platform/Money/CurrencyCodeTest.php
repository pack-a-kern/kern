<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Kern\Platform\Money\CurrencyCode;

/*
 * One shape for a currency everywhere — the money value, the forms, the BNR parser, the settings —
 * so "eur" cannot be a currency in one place and a refusal in another.
 */

it('accepts three capital letters', function (string $code): void {
    expect(CurrencyCode::isValid($code))->toBeTrue()
        ->and(Validator::make(['c' => $code], ['c' => ['required', ...CurrencyCode::rules()]])->passes())->toBeTrue();
})->with(['RON', 'EUR', 'USD']);

it('refuses anything else, in code and in a form alike', function (string $code): void {
    expect(CurrencyCode::isValid($code))->toBeFalse()
        ->and(Validator::make(['c' => $code], ['c' => ['required', ...CurrencyCode::rules()]])->passes())->toBeFalse();
})->with(['lower case' => 'eur', 'two letters' => 'EU', 'four letters' => 'EURO', 'a digit' => 'EU1', 'a symbol' => '€']);

it('is denominated in lei', function (): void {
    expect(CurrencyCode::BASE)->toBe('RON')->and(CurrencyCode::isValid(CurrencyCode::BASE))->toBeTrue();
});
