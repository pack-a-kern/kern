<?php

declare(strict_types=1);

use Kern\TaxRomania\FiscalParameters\Enums\FiscalParameterValueType;
use Kern\TaxRomania\FiscalParameters\Queries\FiscalValueDisplay;

it('says how each stored value is shown', function (FiscalParameterValueType $type, array $value, array $display): void {
    expect(FiscalValueDisplay::of($type, $value))->toBe($display);
})->with([
    'an amount' => [FiscalParameterValueType::MONEY, ['amount_minor' => 405_000, 'currency' => 'RON'], ['kind' => 'money', 'amount_minor' => 405_000, 'currency' => 'RON']],
    'a rate' => [FiscalParameterValueType::DECIMAL, ['value' => '0.10'], ['kind' => 'percent', 'ratio' => '0.10']],
    'a deadline' => [FiscalParameterValueType::JSON, ['day' => 25, 'month' => 5], ['kind' => 'day_month', 'day' => 25, 'month' => 5]],
    'an exemption' => [FiscalParameterValueType::JSON, ['value' => true], ['kind' => 'flag', 'holds' => true]],
    'a period' => [FiscalParameterValueType::JSON, ['days' => 30], ['kind' => 'days', 'days' => 30]],
    'a range of wages' => [FiscalParameterValueType::JSON, ['floor' => 6, 'cap' => 72], ['kind' => 'wages_range', 'floor' => 6, 'cap' => 72]],
    'a range that cannot move' => [FiscalParameterValueType::JSON, ['floor' => 12, 'cap' => 12], ['kind' => 'wages', 'wages' => [12]]],
    'several thresholds' => [FiscalParameterValueType::JSON, ['wages' => [6, 12, 24]], ['kind' => 'wages', 'wages' => [6, 12, 24]]],
    'one threshold' => [FiscalParameterValueType::INTEGER, ['value' => 12], ['kind' => 'wages', 'wages' => [12]]],
]);

it('marks only the checks that inform as quiet', function (): void {
    expect(FiscalValueDisplay::tone('in_force_later'))->toBe('idle')
        ->and(FiscalValueDisplay::tone('several_paragraphs'))->toBe('idle')
        ->and(FiscalValueDisplay::tone('two_values'))->toBe('warn');
});
