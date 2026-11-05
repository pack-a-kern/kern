<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\Support;

use DateTimeImmutable;
use Kern\TaxRomania\Subjects\Enums\Sex;
use Kern\TaxRomania\Subjects\Exceptions\InvalidCnpException;

/**
 * Reads a CNP: `S AA LL ZZ JJ NNN C`.
 *
 * | part | meaning |
 * |---|---|
 * | `S` | sex and century — 1/2 born 1900s, 3/4 1800s, 5/6 2000s, 7/8 foreign residents (no century), 9 non-resident |
 * | `AA LL ZZ` | birth date, two digits each; never in the future |
 * | `JJ` | county of registration, 01–52 (41–48 are Bucharest's sectors, 7 and 8 abolished); 70 since 8 May 2024, naming none |
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
        $today = now()->format('Y-m-d');

        // 7 and 8 are foreign residents, and the digit records the residence, not the century: a
        // resident born in 2003 has `03` as anyone born in 1903 would. The later century is the
        // one a living person can have been born in, unless it lies ahead of today.
        if ($leading === 7 || $leading === 8) {
            $recent = self::date(2000, $digits);

            return $recent <= $today ? $recent : self::notAfter(self::date(1900, $digits), $today);
        }

        $century = match ($leading) {
            1, 2 => 1900,
            3, 4 => 1800,
            5, 6 => 2000,
            default => throw InvalidCnpException::prefix(),
        };

        return self::notAfter(self::date($century, $digits), $today);
    }

    /** The `AA LL ZZ` digits in the given century, as `YYYY-MM-DD`, refused if no such day exists. */
    private static function date(int $century, string $digits): string
    {
        $candidate = sprintf('%04d-%s-%s', $century + (int) substr($digits, 1, 2), substr($digits, 3, 2), substr($digits, 5, 2));

        // Round-tripped rather than merely parsed: PHP happily reads "1990-02-31" and hands back
        // the 3rd of March. Comparing the formatted result is what rejects a day that never existed.
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $candidate);

        if ($date === false || $date->format('Y-m-d') !== $candidate) {
            throw InvalidCnpException::birthDate();
        }

        return $candidate;
    }

    /** No one is registered before they are born: a date after today is a mistyped CNP. */
    private static function notAfter(string $birthDate, string $today): string
    {
        if ($birthDate > $today) {
            throw InvalidCnpException::futureBirthDate();
        }

        return $birthDate;
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

    /** The county's letter code, or null for a number that names none (70). */
    private static function countyCode(string $countyNumber): ?string
    {
        $map = config('reference.counties.cnp');

        if (! is_array($map) || ! array_key_exists($countyNumber, $map)) {
            throw InvalidCnpException::county();
        }

        $code = $map[$countyNumber];

        if ($code !== null && (! is_string($code) || $code === '')) {
            throw InvalidCnpException::county();
        }

        return $code;
    }
}
