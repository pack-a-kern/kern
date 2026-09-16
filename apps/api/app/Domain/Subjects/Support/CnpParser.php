<?php

declare(strict_types=1);

namespace App\Domain\Subjects\Support;

use App\Domain\Subjects\Enums\Sex;
use App\Domain\Subjects\Exceptions\InvalidCnpException;
use DateTimeImmutable;

/**
 * Reads a CNP: `S AA LL ZZ JJ NNN C`.
 *
 * | part | meaning |
 * |---|---|
 * | `S` | sex and century — 1/2 born 1900s, 3/4 1800s, 5/6 2000s, 7/8 foreign residents, 9 non-resident |
 * | `AA LL ZZ` | birth date, two digits each |
 * | `JJ` | county of registration, 01–52 (41–46 are Bucharest's sectors) |
 * | `NNN` | serial within that county and day |
 * | `C` | check digit |
 *
 * ## Why this exists rather than a regex
 *
 * A CNP carries the birth date and the county inside it. Asking a person to type those again is
 * asking for two fields that can disagree with the number that produced them — and the one thing
 * worse than a missing birth date is a birth date that contradicts the identifier it belongs to.
 *
 * The check digit catches what a length rule cannot: a transposed pair, a mistyped digit. It is
 * fixed arithmetic — weights `279146358279`, sum modulo 11, with a remainder of 10 meaning 1 — so
 * it lives here and not in configuration, where a deployment could break every check by editing a
 * string.
 *
 * The county *table* is the opposite: it is data about Romania, not arithmetic, and it is shared
 * with the address field, so it lives in `config/reference/counties.php`.
 */
final readonly class CnpParser
{
    /** The control key, as the standard defines it. Not configurable — it is the algorithm. */
    private const string CONTROL_KEY = '279146358279';

    /**
     * @throws InvalidCnpException
     */
    public static function parse(string $cnp): CnpDetails
    {
        $digits = preg_replace('/\D+/', '', $cnp) ?? '';

        if (strlen($digits) !== 13) {
            throw InvalidCnpException::length();
        }

        self::assertChecksum($digits);

        $leading = (int) $digits[0];

        if ($leading === 0) {
            throw InvalidCnpException::prefix();
        }

        if ($leading === 9) {
            throw InvalidCnpException::nonResident();
        }

        $countyNumber = substr($digits, 7, 2);
        $countyCode = self::countyCode($countyNumber);

        return new CnpDetails(
            sex: $leading % 2 === 1 ? Sex::MALE : Sex::FEMALE,
            foreignResident: $leading === 7 || $leading === 8,
            birthDate: self::birthDate($digits, $leading),
            countyNumber: $countyNumber,
            countyCode: $countyCode,
            locality: self::locality($countyNumber),
            serial: substr($digits, 9, 3),
        );
    }

    /** True when the CNP is one this application accepts, for callers that do not want an exception. */
    public static function isValid(string $cnp): bool
    {
        try {
            self::parse($cnp);

            return true;
        } catch (InvalidCnpException) {
            return false;
        }
    }

    private static function assertChecksum(string $digits): void
    {
        $sum = 0;

        for ($position = 0; $position < 12; $position++) {
            $sum += (int) $digits[$position] * (int) self::CONTROL_KEY[$position];
        }

        $remainder = $sum % 11;
        $expected = $remainder === 10 ? 1 : $remainder;

        if ($expected !== (int) $digits[12]) {
            throw InvalidCnpException::checksum();
        }
    }

    private static function birthDate(string $digits, int $leading): string
    {
        // 7 and 8 are foreign residents, whose series the standard places in the 1900s alongside
        // 1 and 2 — the residence permit is what the digit records, not a different era.
        $century = match ($leading) {
            1, 2, 7, 8 => 1900,
            3, 4 => 1800,
            5, 6 => 2000,
            default => throw InvalidCnpException::prefix(),
        };

        $year = $century + (int) substr($digits, 1, 2);
        $month = substr($digits, 3, 2);
        $day = substr($digits, 5, 2);
        $candidate = sprintf('%04d-%s-%s', $year, $month, $day);

        // Round-tripped rather than merely parsed: PHP happily reads "1990-02-31" and hands back
        // the 3rd of March. Comparing the formatted result is what rejects a day that never existed.
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $candidate);

        if ($date === false || $date->format('Y-m-d') !== $candidate) {
            throw InvalidCnpException::birthDate();
        }

        return $candidate;
    }

    /**
     * The locality a CNP names, which is only ever a Bucharest sector.
     *
     * 41–46 are sectors 1–6. Configured alongside the county map rather than computed from the
     * number, because "41 means Sector 1" is a fact about Romania, not arithmetic.
     */
    private static function locality(string $countyNumber): ?string
    {
        $map = config('reference.counties.cnp_localities');
        $locality = is_array($map) ? ($map[$countyNumber] ?? null) : null;

        return is_string($locality) && $locality !== '' ? $locality : null;
    }

    private static function countyCode(string $countyNumber): string
    {
        $map = config('reference.counties.cnp');
        $code = is_array($map) ? ($map[$countyNumber] ?? null) : null;

        if (! is_string($code) || $code === '') {
            throw InvalidCnpException::county();
        }

        return $code;
    }
}
