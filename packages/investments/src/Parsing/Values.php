<?php

declare(strict_types=1);

namespace Kern\Investments\Parsing;

use DateTimeImmutable;
use DateTimeZone;
use Kern\Legislation\Parsing\RomanianDateParser;

/**
 * Each value a source shows, read strictly and turned into what is stored — or a `ParseFailure` naming
 * where (ADR 0025 §6). A source writes figures the Romanian way: `3.305.313.609,00`, `-0,28`, `80,0560 %`;
 * dates as `09.11.2026`, `09.11.2026 17:59` or `27 ianuarie 2026`.
 */
final class Values
{
    private const string DECIMAL = '/\A(-?)(\d{1,3}(?:\.\d{3})*|\d+)(?:,(\d{1,12}))?\z/';

    /** `1.234,5` → `1234.5`; a percent sign after it is dropped. Nothing else is a decimal. */
    public static function decimal(string $text, string $where): string
    {
        if (preg_match(self::DECIMAL, rtrim($text, ' %'), $parts) !== 1) {
            throw ParseFailure::at($where, 'not a decimal');
        }

        $whole = ltrim(str_replace('.', '', $parts[2]), '0') ?: '0';

        // What a column holds (`Kern\Investments\Casts\Decimal`): a figure past it is not one a source means.
        if (strlen($whole) > 18) {
            throw ParseFailure::at($where, 'a decimal too large');
        }

        return $parts[1] . $whole . (($parts[3] ?? '') === '' ? '' : '.' . $parts[3]);
    }

    /** An empty cell is no value; anything else must be a decimal. */
    public static function optionalDecimal(string $text, string $where): ?string
    {
        return $text === '' ? null : self::decimal($text, $where);
    }

    /** `360.094.390` → 360094390. */
    public static function count(string $text, string $where): int
    {
        $decimal = self::decimal($text, $where);

        if (! ctype_digit($decimal) || strlen($decimal) > 15) {
            throw ParseFailure::at($where, 'not a whole number');
        }

        return (int) $decimal;
    }

    /** `09.11.2026`, a real day of the calendar. */
    public static function date(string $text, string $where): DateTimeImmutable
    {
        if (preg_match('/\A(\d{2})\.(\d{2})\.(\d{4})\z/', $text, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[1], (int) $parts[3])) {
            throw ParseFailure::at($where, 'not a date');
        }

        return new DateTimeImmutable("{$parts[3]}-{$parts[2]}-{$parts[1]}", new DateTimeZone('UTC'));
    }

    /** `09.11.2026 17:59` as the source's clock shows it, as the instant it was. */
    public static function dateTime(string $text, DateTimeZone $zone, string $where): DateTimeImmutable
    {
        if (preg_match('/\A(\d{2}\.\d{2}\.\d{4}) (\d{2}):(\d{2})(?::(\d{2}))?\z/', $text, $parts) !== 1 || (int) $parts[2] > 23 || (int) $parts[3] > 59) {
            throw ParseFailure::at($where, 'not a date and time');
        }

        $day = self::date($parts[1], $where)->format('Y-m-d');

        return (new DateTimeImmutable("{$day} {$parts[2]}:{$parts[3]}:" . ($parts[4] ?? '00'), $zone))->setTimezone(new DateTimeZone('UTC'));
    }

    /** `27 ianuarie 2026`, checked to be the day it says — not one rolled over from `31 februarie`. */
    public static function romanianDate(string $text, string $where): DateTimeImmutable
    {
        $date = RomanianDateParser::parse($text);

        if ($date === null || preg_match('/\A0?' . $date->format('j') . '\s/u', $text) !== 1) {
            throw ParseFailure::at($where, 'not a date');
        }

        return new DateTimeImmutable($date->format('Y-m-d'), new DateTimeZone('UTC'));
    }

    /** An ISIN: two letters, nine letters or digits, and the check digit those make (ISO 6166). */
    public static function isin(string $text, string $where): string
    {
        if (preg_match('/\A[A-Z]{2}[A-Z0-9]{9}\d\z/', $text) !== 1) {
            throw ParseFailure::at($where, 'not an ISIN');
        }

        // Letters become two digits (A = 10 … Z = 35), then Luhn over the whole run of digits.
        $digits = implode('', array_map(static fn (string $c): string => ctype_digit($c) ? $c : (string) (ord($c) - 55), str_split(substr($text, 0, 11))));
        $sum = 0;

        foreach (array_reverse(str_split($digits)) as $i => $digit) {
            $value = (int) $digit * ($i % 2 === 0 ? 2 : 1);
            $sum += intdiv($value, 10) + $value % 10;
        }

        if ((10 - $sum % 10) % 10 !== (int) $text[11]) {
            throw ParseFailure::at($where, 'an ISIN whose check digit is wrong');
        }

        return $text;
    }

    /** A ticker symbol, as BVB files one: capital letters and digits. */
    public static function symbol(string $text, string $where): string
    {
        if (preg_match('/\A[A-Z0-9]{1,16}\z/', $text) !== 1) {
            throw ParseFailure::at($where, 'not a symbol');
        }

        return $text;
    }

    /** Text a person reads — a name, a kind of meeting: present, and already bounded by `HtmlPage::text()`. */
    public static function name(string $text, string $where): string
    {
        if ($text === '') {
            throw ParseFailure::at($where, 'empty');
        }

        return $text;
    }
}
