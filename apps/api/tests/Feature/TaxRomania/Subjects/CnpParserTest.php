<?php

declare(strict_types=1);

use Kern\TaxRomania\Subjects\Enums\Sex;
use Kern\TaxRomania\Subjects\Exceptions\InvalidCnpException;
use Kern\TaxRomania\Subjects\Support\CnpParser;

/**
 * Reading a CNP.
 *
 * Fixtures come from `cnpWithCheckDigit()` in `tests/Pest.php` — constructed, never borrowed.
 */

it('reads sex, birth date and county from a CNP', function (): void {
    // 1 = male, born 1990-01-01, county 12 = Cluj.
    $cnp = cnpWithCheckDigit('190010112123');

    $details = CnpParser::parse($cnp);

    expect($details->sex)->toBe(Sex::MALE)
        ->and($details->birthDate)->toBe('1990-01-01')
        ->and($details->countyNumber)->toBe('12')
        ->and($details->countyCode)->toBe('CJ')
        ->and($details->foreignResident)->toBeFalse();
});

it('reads the century from the leading digit', function (int $leading, string $expectedYear): void {
    $details = CnpParser::parse(cnpWithCheckDigit((string) $leading . '05010112123'));

    expect(substr($details->birthDate, 0, 4))->toBe($expectedYear);
})->with([
    [1, '1905'],
    [2, '1905'],
    [3, '1805'],
    [4, '1805'],
    [5, '2005'],
    [6, '2005'],
]);

it('treats 7 and 8 as foreign residents', function (): void {
    $details = CnpParser::parse(cnpWithCheckDigit('790010112123'));

    expect($details->foreignResident)->toBeTrue()
        ->and($details->sex)->toBe(Sex::MALE);
});

it('reads a foreign resident\'s century as the latest one not ahead of today', function (string $first7, string $expected): void {
    $this->travelTo(new DateTimeImmutable('2026-11-05'));

    expect(CnpParser::parse(cnpWithCheckDigit($first7 . '12123'))->birthDate)->toBe($expected);
})->with([
    // The digit carries no century: 03 is 2003, not 1903.
    ['8030101', '2003-01-01'],
    // 85 cannot be 2085, so it is 1985.
    ['7850101', '1985-01-01'],
    // Today itself is not ahead of today; tomorrow is, and falls back a century.
    ['7261105', '2026-11-05'],
    ['7261106', '1926-11-06'],
]);

it('refuses a birth date that has not come yet', function (string $first7): void {
    $this->travelTo(new DateTimeImmutable('2026-11-05'));

    $reason = null;

    try {
        CnpParser::parse(cnpWithCheckDigit($first7 . '12123'));
    } catch (InvalidCnpException $exception) {
        $reason = $exception->reason;
    }

    expect($reason)->toBe('CNP_FUTURE_BIRTH_DATE');
})->with(['5900101', '6261106']);

it('maps every Bucharest sector to the city', function (string $county): void {
    $details = CnpParser::parse(cnpWithCheckDigit('1900101' . $county . '123'));

    expect($details->countyCode)->toBe('B');
})->with(['40', '41', '42', '43', '44', '45', '46', '47', '48']);

it('reads 70 as a CNP that names no county', function (): void {
    // Every CNP generated since 8 May 2024 carries 70, wherever the person lives.
    $details = CnpParser::parse(cnpWithCheckDigit('5240601' . '70' . '123'));

    expect($details->countyNumber)->toBe('70')
        ->and($details->countyCode)->toBeNull()
        ->and($details->locality)->toBeNull();
});

it('names the sector as a locality, for the six that have one', function (string $county, ?string $locality): void {
    expect(CnpParser::parse(cnpWithCheckDigit('1900101' . $county . '123'))->locality)->toBe($locality);
})->with([
    ['41', 'Sector 1'],
    ['46', 'Sector 6'],
    // Bucharest without a sector, and a county that is not a city at all.
    ['40', null],
    ['12', null],
]);

it('refuses a CNP whose check digit does not match', function (): void {
    $valid = cnpWithCheckDigit('190010112123');
    $broken = substr($valid, 0, 12) . (string) (((int) $valid[12] + 1) % 10);

    expect(fn () => CnpParser::parse($broken))
        ->toThrow(InvalidCnpException::class, 'check digit');
});

it('refuses a birth date that never existed', function (): void {
    // 30 February.
    expect(fn () => CnpParser::parse(cnpWithCheckDigit('190023012123')))
        ->toThrow(InvalidCnpException::class, 'birth date');
});

it('refuses a county the CNP scale does not have', function (string $county): void {
    expect(fn () => CnpParser::parse(cnpWithCheckDigit('1900101' . $county . '123')))
        ->toThrow(InvalidCnpException::class, 'county');
})->with([
    // The gap between Bucharest's sectors and Călărași, the one after Giurgiu, and 00.
    '49', '53', '00',
]);

it('refuses anything that is not thirteen digits', function (string $value): void {
    expect(fn () => CnpParser::parse($value))->toThrow(InvalidCnpException::class, '13 digits');
})->with(['', '123', '19001011212345']);

it('refuses a non-resident CNP with its own reason', function (): void {
    // The reason matters more than the message here — it is what the interface translates.
    $reason = null;

    try {
        CnpParser::parse(cnpWithCheckDigit('990010112123'));
    } catch (InvalidCnpException $exception) {
        $reason = $exception->reason;
    }

    expect($reason)->toBe('CNP_NON_RESIDENT');
});

it('tolerates the spaces and dashes people type', function (): void {
    $cnp = cnpWithCheckDigit('190010112123');
    $spaced = substr($cnp, 0, 1) . ' ' . substr($cnp, 1, 6) . '-' . substr($cnp, 7);

    expect(CnpParser::parse($spaced)->birthDate)->toBe('1990-01-01');
});
