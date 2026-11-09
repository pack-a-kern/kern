<?php

declare(strict_types=1);

namespace Kern\Investments\Parsing\Treasury;

use DateTimeImmutable;
use DateTimeZone;
use Kern\Investments\Enums\Programme;
use Kern\Investments\Parsing\ParseFailure;
use RoundingMode;

/**
 * The Ministry of Finance's two subscription lists — Fidelis since 2020, Tezaur since 2018 — read from
 * the grid the processing service makes of each workbook (ADR 0025 §6).
 *
 * As the lists are laid out (2026-10): a heading row per year, each year's issues under it, its totals
 * after them; a round's month written on its first issue only, the rest under it blank; Tezaur a column
 * to the right. So the columns are found by their headers on every header row, a blank month is the
 * month above it, and a line is an issue when it holds figures under an issue's code — a total, a year
 * or an empty line is not. A line that holds figures and is none of those fails the list.
 */
final class SubscriptionListParser
{
    /** @var array<string, array{code: string, month: string, value: string, count: string, pattern: string}> */
    private const array LAYOUT = [
        'FIDELIS' => ['code' => 'Simbol emisiune', 'month' => 'Data emisiunii', 'value' => 'Valoare subscrisă', 'count' => 'Subscrieri', 'pattern' => '/\AR\d{4}[A-Z]{1,2}\z/'],
        'TEZAUR' => ['code' => 'Cod emisiune', 'month' => 'Dată emisiune', 'value' => 'Valoare subscrisă', 'count' => 'Nr. subscrieri', 'pattern' => '/\A\d{4}\z/'],
    ];

    /** How a list names a month it writes in words: "Apr - May 2023". */
    private const array MONTHS = ['Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6, 'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12];

    /**
     * @param list<list<string>> $rows the workbook's sheet, as `SpreadsheetGrid` hands it back
     *
     * @return list<SubscriptionFigures>
     */
    public static function parse(Programme $programme, array $rows): array
    {
        $layout = self::LAYOUT[$programme->value];
        $source = "{$programme->value} subscriptions";
        $columns = null;
        $months = null;
        $figures = [];

        foreach ($rows as $at => $row) {
            $where = "{$source}, row " . ($at + 1);
            $header = self::columns($row, $layout);

            if ($header !== null) {
                $columns = $header;

                continue;
            }

            if ($columns === null) {
                continue;
            }

            $code = $row[$columns['code']] ?? '';
            $value = $row[$columns['value']] ?? '';
            $count = $row[$columns['count']] ?? '';

            // A year heading or an empty line has no figures — and a Tezaur year, "2019", is shaped like a code.
            if ($value === '' && $count === '') {
                continue;
            }

            if (preg_match($layout['pattern'], $code) !== 1) {
                // A total is skipped; anything else with figures is a line this reading does not know.
                if (! str_starts_with($code, 'Total')) {
                    throw ParseFailure::at($where, 'figures on a line that is not an issue or a total');
                }

                continue;
            }

            $written = $row[$columns['month']] ?? '';
            $months = $written === '' ? $months : self::months($written, $where);

            $figures[] = new SubscriptionFigures(
                programme: $programme,
                code: $code,
                months: $months ?? throw ParseFailure::at($where, 'an issue with no month above it'),
                subscribedValueMinor: self::minor($value, $where),
                subscriptions: self::whole($count, $where),
            );
        }

        if ($figures === []) {
            throw ParseFailure::at($source, 'no issue found');
        }

        return $figures;
    }

    /**
     * @param list<string> $row
     * @param array{code: string, month: string, value: string, count: string, pattern: string} $layout
     *
     * @return array{code: int, month: int, value: int, count: int}|null
     */
    private static function columns(array $row, array $layout): ?array
    {
        $found = [];
        $texts = array_map(self::header(...), $row);

        foreach (['code', 'month', 'value', 'count'] as $column) {
            $at = array_search(self::header($layout[$column]), $texts, true);

            if (! is_int($at)) {
                return null;
            }

            $found[$column] = $at;
        }

        return $found;
    }

    /** A header as compared: case, dots and spacing aside — the list writes "Nr. subscrieri" one year, "Nr subscrieri" the next. */
    private static function header(string $text): string
    {
        return preg_replace('/\s+/u', ' ', trim(str_replace('.', '', mb_strtolower($text)))) ?? '';
    }

    /**
     * A date cell as the grid writes it, `2020-08-01 00:00:00`, or a round of two months in words.
     *
     * @return non-empty-list<DateTimeImmutable>
     */
    private static function months(string $written, string $where): array
    {
        $utc = new DateTimeZone('UTC');

        if (preg_match('/\A(\d{4})-(\d{2})-01 00:00:00\z/', $written, $date) === 1 && checkdate((int) $date[2], 1, (int) $date[1])) {
            return [new DateTimeImmutable("{$date[1]}-{$date[2]}-01", $utc)];
        }

        if (preg_match('/\A([A-Z][a-z]{2}) - ([A-Z][a-z]{2}) (\d{4})\z/', $written, $span) === 1 && isset(self::MONTHS[$span[1]], self::MONTHS[$span[2]]) && self::MONTHS[$span[1]] < self::MONTHS[$span[2]]) {
            return array_map(
                static fn (string $month): DateTimeImmutable => new DateTimeImmutable(sprintf('%s-%02d-01', $span[3], self::MONTHS[$month]), $utc),
                [$span[1], $span[2]],
            );
        }

        throw ParseFailure::at($where, 'not a month the list writes');
    }

    /**
     * An amount as the grid writes a number — `562376800`, `1500000.5` — in minor units, to the cent.
     *
     * Rounded, not cut: the workbook keeps a number as a float, and a whole 776504369 can arrive as
     * `776504368.99999994`, which is that amount, not a cent less.
     */
    private static function minor(string $value, string $where): int
    {
        if (preg_match('/\A\d{1,15}(?:\.\d{1,17})?\z/', $value) !== 1 || ! is_numeric($value)) {
            throw ParseFailure::at($where, 'not an amount');
        }

        return (int) bcmul(bcround($value, 2, RoundingMode::HalfAwayFromZero), '100', 0);
    }

    private static function whole(string $value, string $where): int
    {
        if (preg_match('/\A\d{1,9}\z/', $value) !== 1) {
            throw ParseFailure::at($where, 'not a number of subscriptions');
        }

        return (int) $value;
    }
}
