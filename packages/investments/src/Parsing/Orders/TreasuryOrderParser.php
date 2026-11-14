<?php

declare(strict_types=1);

namespace Kern\Investments\Parsing\Orders;

use DateTimeImmutable;
use Kern\Investments\Enums\PayoutKind;
use Kern\Investments\Enums\Programme;
use Kern\Investments\Parsing\ParseFailure;
use Kern\Investments\Parsing\Values;
use Kern\Legislation\Enums\SectionType;
use Kern\Legislation\Parsing\ParsedSection;
use Kern\Legislation\Provisions\StoredProvision;

/**
 * The terms of the issues a Ministry of Finance order launches, read from the order as
 * `kern/legislation` parses it (ADR 0025 §5) — the text and the tables, as the Official Gazette prints
 * them (2026 layout):
 *
 * - **Fidelis** — "Termenii finali": one annex per issue, its date, ISIN and symbol first, the rest a table
 *   of label and value — the currency, the face value, the rate, the period, record and payment dates of
 *   each coupon, the maturity. The periods under "Perioada de dobândă", each from and to: a line of both
 *   dates per coupon — under the heading in its own cell, in a row each, or stacked in one cell — or the
 *   froms in one cell and the tos in the next. An order that lists fewer periods than coupons — the Official
 *   Gazette leaving one out — gives each listed one to its coupon, in order, where each ends before its
 *   coupon pays and after the one before paid; the coupon it left out has none, never one made up. Read by label wherever the annex prints it: those first three a line each in the
 *   text, a block of labels and then their values, or a table of their own; the terms in one table or
 *   several, one of them under a list item; a label in either case, with or without its colon.
 * - **Tezaur** — the prospectus: in its annex, a table of the issues (code, dates, rate), one schedule
 *   table per issue (each coupon's period and payment date, then the repayment), and the face value in
 *   the text.
 *
 * An order with no such terms — one amending a prospectus, say — reads as no issue. One that has them
 * and does not read fails, saying where: the terms of a bond are never half-read.
 */
final class TreasuryOrderParser
{
    /** How a Fidelis annex of terms opens — after a mark, in some, of no letter: "+ TERMENI FINALI". */
    private const string FIDELIS_TERMS = '/\A[^\p{L}]*TERMENI FINALI\b/u';

    private const array CURRENCIES = ['lei' => 'RON', 'euro' => 'EUR'];

    /** How a Tezaur prospectus' table of issues opens: "Codul emisiunii", "Cod emisiune", "Codulemisiunii". */
    private const string TEZAUR_ISSUES = '/\Acod(?:ul)?\s?emisiun(?:e|ii)\z/u';

    /** A day as an order writes it: "19 august 2026". */
    private const string DAY = '\d{1,2}\s+\p{L}+\s+\d{4}';

    /** The label the coupon periods stand under, as {@see self::label()} writes it. */
    private const string PERIODS = 'perioada de dobândă';

    /** The labels a Fidelis annex opens with, before its table of terms — as {@see self::label()} writes them. */
    private const array OPENING = ['perioada ofertei', 'preț de emisiune', 'data emisiunii', 'isin', 'cod cfi', 'simbol'];

    /**
     * @param  list<ParsedSection|StoredProvision>  $order  the order's first provisions, each with what stands under it — as stored, or just read
     * @return list<OrderIssue>
     */
    public static function fidelis(array $order): array
    {
        $issues = [];

        foreach (self::annexes($order) as $annex) {
            if (preg_match(self::FIDELIS_TERMS, $annex->text) !== 1) {
                continue;
            }

            $where = "Fidelis order, {$annex->citationLabel}";
            $rows = self::terms($annex, $where);
            $rate = self::percent(self::cell($rows, $where, 'Rata dobânzii (rata cuponului)'), $where);
            $records = self::dates(self::cell($rows, $where, 'Data de înregistrare'), $where);
            $payments = self::dates(self::cell($rows, $where, 'Data de plată a dobânzii (cuponului)', 'Datele de plată a dobânzii (cuponului)'), $where);
            $maturity = Values::romanianDate(self::cell($rows, $where, 'Rambursare la scadență (data scadenței)'), $where);
            [$face, $currency] = self::amount(self::cell($rows, $where, 'Valoarea nominală a unui titlu de stat'), $where);

            $periods = self::periods(self::tablesIn($annex), $where);

            if (count($records) !== count($payments)) {
                throw ParseFailure::at($where, 'a record date for each coupon, and not as many');
            }

            $periods = self::matched($periods, $payments);

            $issues[] = new OrderIssue(
                programme: Programme::FIDELIS,
                isin: Values::isin(self::cell($rows, $where, 'ISIN'), $where),
                symbol: Values::symbol(self::cell($rows, $where, 'Simbol'), $where),
                issueCode: null,
                currency: $currency,
                faceValue: $face,
                ratePercent: $rate,
                issueDate: Values::romanianDate(self::cell($rows, $where, 'Data emisiunii'), $where),
                maturityDate: $maturity,
                payouts: [
                    ...array_map(
                        static fn (DateTimeImmutable $paid, DateTimeImmutable $record, ?array $period): OrderPayout => new OrderPayout(PayoutKind::COUPON, $paid, $period[0] ?? null, $period[1] ?? null, $record),
                        $payments,
                        $records,
                        $periods,
                    ),
                    new OrderPayout(PayoutKind::PRINCIPAL, $maturity),
                ],
            );
        }

        return $issues;
    }

    /**
     * @param  list<ParsedSection|StoredProvision>  $order  the order's first provisions, each with what stands under it — as stored, or just read
     * @return list<OrderIssue>
     */
    public static function tezaur(array $order): array
    {
        $issues = [];

        foreach (self::annexes($order) as $annex) {
            $tables = self::tablesIn($annex);
            $list = array_values(array_filter($tables, static fn (array $table): bool => preg_match(self::TEZAUR_ISSUES, self::label($table[0][0] ?? '')) === 1));

            if ($list === []) {
                continue;
            }

            $where = "Tezaur order, {$annex->citationLabel}";
            $schedules = self::schedules($tables, $where);
            [$face, $currency] = self::amount(self::after(self::fullText($annex), 'o valoare nominală de', $where), $where);

            foreach (array_slice($list[0], 1) as $at => $row) {
                $at = "{$where}, issue " . ($at + 1);

                if (count($row) !== 7 || preg_match('/\A\d{4}\z/', $row[0]) !== 1) {
                    throw ParseFailure::at($at, 'not a line of the issues table');
                }

                $issues[] = new OrderIssue(
                    programme: Programme::TEZAUR,
                    isin: null,
                    symbol: null,
                    issueCode: $row[0],
                    currency: $currency,
                    faceValue: $face,
                    ratePercent: self::percent($row[6], $at),
                    issueDate: Values::date($row[3], $at),
                    maturityDate: Values::date($row[4], $at),
                    payouts: $schedules[$row[0]] ?? throw ParseFailure::at($at, "no schedule for issue {$row[0]}"),
                );
            }
        }

        return $issues;
    }

    /**
     * @param  list<ParsedSection|StoredProvision>  $order
     * @return list<ParsedSection|StoredProvision>
     */
    private static function annexes(array $order): array
    {
        return array_values(array_filter($order, static fn (ParsedSection|StoredProvision $s): bool => $s->sectionType === SectionType::ANEXA));
    }

    /** @return list<list<list<string>>> every table in a section and under it, in reading order */
    private static function tablesIn(ParsedSection|StoredProvision $section): array
    {
        return [...$section->tables, ...array_merge([], ...array_map(self::tablesIn(...), $section->children))];
    }

    private static function fullText(ParsedSection|StoredProvision $section): string
    {
        return implode("\n", [$section->text, ...array_map(self::fullText(...), $section->children)]);
    }

    /**
     * A Tezaur prospectus' schedules: per issue, a heading "Cod emisiune 0228", its coupons — number,
     * period, payment date — then the date the face value is repaid. A prospectus prints them a table each
     * or one after another in a single table, so a table is read as blocks, each starting at its heading.
     *
     * @param list<list<list<string>>> $tables
     *
     * @return array<array-key, non-empty-list<OrderPayout>> by issue code — PHP keeps a code with no leading zero as an int
     */
    private static function schedules(array $tables, string $where): array
    {
        $schedules = [];

        foreach (self::blocks($tables) as [$code, $table]) {
            $at = "{$where}, schedule of {$code}";
            $payouts = [];
            $repaid = null;

            foreach (array_slice($table, 1) as $i => $row) {
                if (count($row) === 3 && ctype_digit($row[0])) {
                    if (preg_match('/\A(.+\d{4})\s*-\s*(\d.+\d{4})\z/u', $row[1], $period) !== 1) {
                        throw ParseFailure::at($at, 'a coupon whose period is not two dates');
                    }

                    $payouts[] = new OrderPayout(PayoutKind::COUPON, Values::romanianDate($row[2], $at), Values::romanianDate($period[1], $at), Values::romanianDate($period[2], $at));
                } elseif (count($row) === 1 && ($table[$i][0] ?? '') === 'Plata valorii nominale') {
                    $repaid = Values::romanianDate($row[0], $at);
                }
            }

            if ($payouts === [] || $repaid === null) {
                throw ParseFailure::at($at, 'a schedule without its coupons or its repayment');
            }

            $schedules[$code] = [...$payouts, new OrderPayout(PayoutKind::PRINCIPAL, $repaid)];
        }

        return $schedules;
    }

    /**
     * The schedule blocks of these tables: from each "Cod emisiune" heading to the next, or to its table's end.
     *
     * @param list<list<list<string>>> $tables
     *
     * @return list<array{string, non-empty-list<list<string>>}> each issue's code, and its rows from its heading on
     */
    private static function blocks(array $tables): array
    {
        $blocks = [];

        foreach ($tables as $table) {
            $block = null;

            foreach ($table as $row) {
                if (count($row) === 1 && preg_match('/\ACod emisiune (\d{4})\z/', $row[0], $heading) === 1) {
                    if ($block !== null) {
                        $blocks[] = $block;
                    }

                    $block = [$heading[1], [$row]];
                } elseif ($block !== null) {
                    $block[1][] = $row;
                }
            }

            if ($block !== null) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /**
     * A Fidelis annex's terms by label: every table in it and under it, the first value a label has kept,
     * and its opening lines, a label and its value each or a block of labels and then their values.
     *
     * @return array<string, string> by {@see self::label()}
     */
    private static function terms(ParsedSection|StoredProvision $annex, string $where): array
    {
        $tables = self::tablesIn($annex);

        if ($tables === []) {
            throw ParseFailure::at($where, 'no table of terms');
        }

        $rows = [];

        foreach ([...array_merge([], ...$tables), ...self::opening($annex->text)] as $row) {
            if (count($row) >= 2 && trim($row[1]) !== '') {
                $rows[self::label($row[0])] ??= trim($row[1]);
            }
        }

        return $rows;
    }

    /**
     * The opening lines of a Fidelis annex as label and value: "ISIN: ROQEJCC1VXP7" a line, or the labels
     * one under another and then, in their order, their values.
     *
     * @return list<array{string, string}>
     */
    private static function opening(string $text): array
    {
        $lines = array_values(array_filter(array_map(trim(...), explode("\n", $text)), static fn (string $line): bool => $line !== ''));
        $pairs = [];

        for ($at = 0; $at < count($lines); $at++) {
            if (preg_match('/\A([^:]+):\s*(\S.*)\z/u', $lines[$at], $inline) === 1 && in_array(self::label($inline[1]), self::OPENING, true)) {
                $pairs[] = [$inline[1], $inline[2]];

                continue;
            }

            $labels = [];

            while (isset($lines[$at]) && in_array(self::label($lines[$at]), self::OPENING, true)) {
                $labels[] = $lines[$at++];
            }

            foreach ($labels as $i => $label) {
                $pairs[] = [$label, $lines[$at + $i] ?? ''];
            }

            $at += max(count($labels) - 1, 0);
        }

        return $pairs;
    }

    /** A label as compared: in lower case, without its colon, its spaces one each. */
    private static function label(string $text): string
    {
        return (string) preg_replace('/\s+/u', ' ', trim(rtrim(trim(mb_strtolower($text)), ':')));
    }

    /**
     * The coupon periods a Fidelis annex lists under "Perioada de dobândă", in order: none where it has no
     * such row — an older layout — and a failure where it has one that does not read.
     *
     * @param list<list<list<string>>> $tables
     *
     * @return list<array{DateTimeImmutable, DateTimeImmutable}>
     */
    private static function periods(array $tables, string $where): array
    {
        $periods = [];
        $under = false;
        $day = static fn (string $text): DateTimeImmutable => Values::romanianDate($text, "{$where}, " . self::PERIODS);

        foreach (array_merge([], ...$tables) as $row) {
            if (! $under) {
                $under = self::label($row[0] ?? '') === self::PERIODS;
                // Some print the periods under the heading, in the heading's own cell (order 1.178/2026).
                $below = array_slice(explode("\n", trim($row[1] ?? '')), 1);

                foreach ($under && $below !== [] ? self::periodPairs([implode("\n", $below)]) ?? [] : [] as [$from, $to]) {
                    $periods[] = [$day($from), $day($to)];
                }

                continue;
            }

            $read = self::periodPairs(array_values(array_filter(array_map(trim(...), $row), static fn (string $cell): bool => $cell !== '')));

            // The first row that is not periods is the next term.
            if ($read === null) {
                break;
            }

            foreach ($read as [$from, $to]) {
                $periods[] = [$day($from), $day($to)];
            }
        }

        if ($under && $periods === []) {
            throw ParseFailure::at($where, 'a "Perioada de dobândă" with no period that reads');
        }

        return $periods;
    }

    /**
     * Each coupon's period, in order: those the order lists, one per coupon from the first, while each ends
     * before its coupon pays and after the one before it paid; none for a coupon past the last listed
     * (order 1.005/2026, annex 5, lists three for four). Periods that do not line up so, or more of them than
     * coupons, are no coupon's.
     *
     * @param list<array{DateTimeImmutable, DateTimeImmutable}> $periods
     * @param list<DateTimeImmutable> $payments
     *
     * @return list<array{DateTimeImmutable, DateTimeImmutable}|null> one per payment
     */
    private static function matched(array $periods, array $payments): array
    {
        $none = array_fill(0, count($payments), null);

        if (count($periods) > count($payments)) {
            return $none;
        }

        foreach ($periods as $at => [, $to]) {
            if ($to >= $payments[$at] || ($at > 0 && $to < $payments[$at - 1])) {
                return $none;
            }
        }

        return array_replace($none, $periods);
    }

    /**
     * A row of periods as from/to pairs: one cell, a line of both days per period, or two, the froms a line
     * each and the tos beside them; null for a row of anything else.
     *
     * @param list<string> $cells
     *
     * @return non-empty-list<array{string, string}>|null
     */
    private static function periodPairs(array $cells): ?array
    {
        $lines = array_map(static fn (string $cell): array => array_map(trim(...), explode("\n", $cell)), $cells);
        $day = static fn (string $text): bool => preg_match('/\A' . self::DAY . '\z/u', $text) === 1;
        $pairs = match (true) {
            count($lines) === 1 => array_map(static fn (string $line): ?array => preg_match('/\A(' . self::DAY . ')\s+(' . self::DAY . ')\z/u', $line, $both) === 1 ? [$both[1], $both[2]] : null, $lines[0]),
            count($lines) === 2 && count($lines[0]) === count($lines[1]) => array_map(static fn (string $from, string $to): ?array => $day($from) && $day($to) ? [$from, $to] : null, $lines[0], $lines[1]),
            default => [null],
        };

        return in_array(null, $pairs, true) ? null : $pairs;
    }

    /**
     * The value of the first of these labels the terms have.
     *
     * @param array<string, string> $rows
     */
    private static function cell(array $rows, string $where, string ...$labels): string
    {
        foreach ($labels as $label) {
            if (isset($rows[self::label($label)])) {
                return $rows[self::label($label)];
            }
        }

        throw ParseFailure::at($where, "no \"{$labels[0]}\"");
    }

    /** What follows a label on its own line of the text: "ISIN: ROQEJCC1VXP7". */
    private static function after(string $text, string $label, string $where): string
    {
        if (preg_match('/' . preg_quote($label, '/') . '\s*([^\n]+)/u', $text, $value) !== 1) {
            throw ParseFailure::at($where, "no \"{$label}\"");
        }

        return trim($value[1], " \t.,;");
    }

    /**
     * "7,60% pe an", "6,75 %", "4% pe an", "7.00% pe an" → "7.60", "6.75", "4", "7.00". A rate's dot is its
     * decimal point, as some orders print it: a rate never runs to thousands.
     *
     * @return numeric-string
     */
    private static function percent(string $text, string $where): string
    {
        if (preg_match('/\A(\d{1,2}(?:[,.]\d{1,4})?)\s*%/', $text, $rate) !== 1) {
            throw ParseFailure::at($where, 'not a rate');
        }

        return Values::decimal(str_replace('.', ',', $rate[1]), $where);
    }

    /**
     * "100 lei", "1 leu", "100 Euro" → the amount and its currency.
     *
     * @return array{numeric-string, string}
     */
    private static function amount(string $text, string $where): array
    {
        if (preg_match('/\A(\d[\d.,]*)\s+(lei|leu|euro)\b/iu', $text, $amount) !== 1) {
            throw ParseFailure::at($where, 'not a face value');
        }

        $unit = mb_strtolower($amount[2]);

        return [Values::decimal($amount[1], $where), self::CURRENCIES[$unit === 'leu' ? 'lei' : $unit]];
    }

    /**
     * "14 octombrie 2027, 14 octombrie 2028", or "…, respectiv 4 decembrie 2023" → each date.
     *
     * @return non-empty-list<DateTimeImmutable>
     */
    private static function dates(string $text, string $where): array
    {
        return array_map(
            static fn (string $date): DateTimeImmutable => Values::romanianDate((string) preg_replace('/\Arespectiv\s+/u', '', trim($date)), $where),
            explode(',', $text),
        );
    }
}
