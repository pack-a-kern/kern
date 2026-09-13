<?php

declare(strict_types=1);

use App\Domain\Subjects\Enums\Sex;
use App\Domain\Subjects\Exceptions\InvalidCnpException;
use App\Domain\Subjects\Support\CnpParser;

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
    $details = CnpParser::parse(cnpWithCheckDigit((string) $leading . '90010112123'));

    expect(substr($details->birthDate, 0, 4))->toBe($expectedYear);
})->with([
    [1, '1990'],
    [2, '1990'],
    [3, '1890'],
    [4, '1890'],
    [5, '2090'],
    [6, '2090'],
]);

it('treats 7 and 8 as foreign residents born in the 1900s', function (): void {
    $details = CnpParser::parse(cnpWithCheckDigit('790010112123'));

    expect($details->foreignResident)->toBeTrue()
        ->and($details->sex)->toBe(Sex::MALE)
        ->and($details->birthDate)->toBe('1990-01-01');
});

it('maps every Bucharest sector to the city', function (string $county): void {
    $details = CnpParser::parse(cnpWithCheckDigit('1900101' . $county . '123'));

    expect($details->countyCode)->toBe('B');
})->with(['40', '41', '42', '43', '44', '45', '46']);

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

it('refuses a county the CNP scale does not have', function (): void {
    // 47 sits in the gap between the Bucharest sectors and Călărași.
    expect(fn () => CnpParser::parse(cnpWithCheckDigit('190010147123')))
        ->toThrow(InvalidCnpException::class, 'county');
});

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
