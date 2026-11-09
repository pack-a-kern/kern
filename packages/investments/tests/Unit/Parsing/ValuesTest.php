<?php

declare(strict_types=1);

namespace Kern\Investments\Tests\Unit\Parsing\ValuesTest;

use DateTimeZone;
use Kern\Investments\Parsing\ParseFailure;
use Kern\Investments\Parsing\Values;

it('reads a decimal written the Romanian way', function (string $text, string $decimal): void {
    expect(Values::decimal($text, 'x'))->toBe($decimal);
})->with([
    ['37,0000', '37.0000'],
    ['-0,28', '-0.28'],
    ['0,5550', '0.5550'],
    ['3.305.313.609,00', '3305313609.00'],
    ['80,0560 %', '80.0560'],
    ['100', '100'],
    ['007,5', '7.5'],
]);

it('refuses anything else as a decimal, saying where', function (string $text): void {
    expect(fn () => Values::decimal($text, 'BVB bonds, row 3, Pret (%)'))->toThrow(ParseFailure::class, 'BVB bonds, row 3, Pret (%): not a decimal');
})->with(['', 'abc', '6.95E-2', '1,2,3', '1.23,4', '12.3456', '1 000', '--1', '<b>1</b>', '1,1234567890123']);

it('refuses a decimal larger than a column holds', function (): void {
    expect(fn () => Values::decimal(str_repeat('9', 19), 'x'))->toThrow(ParseFailure::class, 'too large');
});

it('reads a whole number of shares, and nothing with a fraction', function (): void {
    expect(Values::count('360.094.390', 'x'))->toBe(360094390)
        ->and(fn () => Values::count('1,5', 'x'))->toThrow(ParseFailure::class, 'not a whole number')
        ->and(fn () => Values::count('-3', 'x'))->toThrow(ParseFailure::class);
});

it('reads a calendar date, and no day the calendar does not have', function (): void {
    expect(Values::date('23.08.2029', 'x')->format('Y-m-d'))->toBe('2029-08-23')
        ->and(Values::date('29.02.2028', 'x')->format('Y-m-d'))->toBe('2028-02-29')
        ->and(fn () => Values::date('29.02.2027', 'x'))->toThrow(ParseFailure::class)
        ->and(fn () => Values::date('31.04.2026', 'x'))->toThrow(ParseFailure::class)
        ->and(fn () => Values::date('2026-11-09', 'x'))->toThrow(ParseFailure::class);
});

it('reads a time on the source’s clock as the instant it was, summer and winter', function (): void {
    $bucharest = new DateTimeZone('Europe/Bucharest');

    expect(Values::dateTime('09.11.2026 17:59', $bucharest, 'x')->format('Y-m-d H:i'))->toBe('2026-11-09 15:59')
        ->and(Values::dateTime('15.07.2026 17:59:30', $bucharest, 'x')->format('Y-m-d H:i:s'))->toBe('2026-07-15 14:59:30')
        ->and(fn () => Values::dateTime('09.11.2026 24:00', $bucharest, 'x'))->toThrow(ParseFailure::class)
        ->and(fn () => Values::dateTime('09.11.2026', $bucharest, 'x'))->toThrow(ParseFailure::class);
});

it('reads a date written in words, and refuses one the calendar would roll over', function (): void {
    expect(Values::romanianDate('27 ianuarie 2026', 'x')->format('Y-m-d'))->toBe('2026-01-27')
        ->and(Values::romanianDate('3 iunie 2026', 'x')->format('Y-m-d'))->toBe('2026-06-03')
        ->and(fn () => Values::romanianDate('31 februarie 2026', 'x'))->toThrow(ParseFailure::class)
        ->and(fn () => Values::romanianDate('27 brumarel 2026', 'x'))->toThrow(ParseFailure::class);
});

it('takes an ISIN only with the check digit its other characters make', function (): void {
    expect(Values::isin('ROTLVAACNOR1', 'x'))->toBe('ROTLVAACNOR1')
        ->and(Values::isin('RO4Q0Z5RO1B6', 'x'))->toBe('RO4Q0Z5RO1B6')
        ->and(Values::isin('US0378331005', 'x'))->toBe('US0378331005')
        ->and(fn () => Values::isin('ROTLVAACNOR2', 'x'))->toThrow(ParseFailure::class, 'check digit')
        ->and(fn () => Values::isin('rotlvaacnor1', 'x'))->toThrow(ParseFailure::class)
        ->and(fn () => Values::isin('ROTLVAACNOR', 'x'))->toThrow(ParseFailure::class);
});

it('takes a symbol as BVB files one', function (): void {
    expect(Values::symbol('R2707B', 'x'))->toBe('R2707B')
        ->and(fn () => Values::symbol('', 'x'))->toThrow(ParseFailure::class)
        ->and(fn () => Values::symbol('TLV;DROP', 'x'))->toThrow(ParseFailure::class)
        ->and(fn () => Values::symbol(str_repeat('A', 17), 'x'))->toThrow(ParseFailure::class);
});
