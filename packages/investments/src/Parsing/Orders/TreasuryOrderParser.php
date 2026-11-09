<?php

declare(strict_types=1);

namespace Kern\Investments\Parsing\Orders;

use DateTimeImmutable;
use Kern\Investments\Enums\PayoutKind;
use Kern\Investments\Enums\Programme;
use Kern\Investments\Parsing\ParseFailure;
use Kern\Investments\Parsing\Values;
use Kern\Legislation\Enums\SectionType;
use Kern\Legislation\Parsing\ParsedLegislationDocument;
use Kern\Legislation\Parsing\ParsedSection;

/**
 * The terms of the issues a Ministry of Finance order launches, read from the order as
 * `kern/legislation` parses it (ADR 0025 §5) — the text and the tables, as the Official Gazette prints
 * them (2026 layout):
 *
 * - **Fidelis** — "Termenii finali": one annex per issue, its date, ISIN and symbol in the text, the rest
 *   a table of label and value — the currency, the face value, the rate, the record and payment dates of
 *   each coupon, the maturity.
 * - **Tezaur** — the prospectus: in its annex, a table of the issues (code, dates, rate), one schedule
 *   table per issue (each coupon's period and payment date, then the repayment), and the face value in
 *   the text.
 *
 * An order with no such terms — one amending a prospectus, say — reads as no issue. One that has them
 * and does not read fails, saying where: the terms of a bond are never half-read.
 */
final class TreasuryOrderParser
{
    private const string FIDELIS_TERMS = 'TERMENI FINALI';

    private const array CURRENCIES = ['lei' => 'RON', 'euro' => 'EUR'];

    /** @return list<OrderIssue> */
    public static function fidelis(ParsedLegislationDocument $order): array
    {
        $issues = [];

        foreach (self::annexes($order) as $annex) {
            if (! str_starts_with(trim($annex->text), self::FIDELIS_TERMS)) {
                continue;
            }

            $where = "Fidelis order, {$annex->citationLabel}";
            $rows = self::labelled($annex->tables, $where);
            $rate = self::percent(self::cell($rows, 'Rata dobânzii (rata cuponului)', $where), $where);
            $records = self::dates(self::cell($rows, 'Data de înregistrare', $where), $where);
            $payments = self::dates(self::cell($rows, 'Data de plată a dobânzii (cuponului)', $where), $where);
            $maturity = Values::romanianDate(self::cell($rows, 'Rambursare la scadență (data scadenței)', $where), $where);
            [$face, $currency] = self::amount(self::cell($rows, 'Valoarea nominală a unui titlu de stat', $where), $where);

            if (count($records) !== count($payments)) {
                throw ParseFailure::at($where, 'a record date for each coupon, and not as many');
            }

            $issues[] = new OrderIssue(
                programme: Programme::FIDELIS,
                isin: Values::isin(self::after($annex->text, 'ISIN:', $where), $where),
                symbol: Values::symbol(self::after($annex->text, 'Simbol:', $where), $where),
                issueCode: null,
                currency: $currency,
                faceValue: $face,
                ratePercent: $rate,
                issueDate: Values::romanianDate(self::after($annex->text, 'Data emisiunii:', $where), $where),
                maturityDate: $maturity,
                payouts: [
                    ...array_map(static fn (DateTimeImmutable $paid, DateTimeImmutable $record): OrderPayout => new OrderPayout(PayoutKind::COUPON, $paid, recordDate: $record), $payments, $records),
                    new OrderPayout(PayoutKind::PRINCIPAL, $maturity),
                ],
            );
        }

        return $issues;
    }

    /** @return list<OrderIssue> */
    public static function tezaur(ParsedLegislationDocument $order): array
    {
        $issues = [];

        foreach (self::annexes($order) as $annex) {
            $tables = self::tablesIn($annex);
            $list = array_values(array_filter($tables, static fn (array $table): bool => ($table[0][0] ?? '') === 'Codul emisiunii'));

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

    /** @return list<ParsedSection> */
    private static function annexes(ParsedLegislationDocument $order): array
    {
        return array_values(array_filter($order->topLevelSections, static fn (ParsedSection $s): bool => $s->sectionType === SectionType::ANEXA));
    }

    /** @return list<list<list<string>>> every table in a section and under it, in reading order */
    private static function tablesIn(ParsedSection $section): array
    {
        return [...$section->tables, ...array_merge([], ...array_map(self::tablesIn(...), $section->children))];
    }

    private static function fullText(ParsedSection $section): string
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
     * A table of label and value, by label.
     *
     * @param list<list<list<string>>> $tables
     *
     * @return array<string, string>
     */
    private static function labelled(array $tables, string $where): array
    {
        if ($tables === []) {
            throw ParseFailure::at($where, 'no table of terms');
        }

        $rows = [];

        foreach ($tables[0] as $row) {
            if (count($row) >= 2) {
                $rows[trim($row[0])] = trim($row[1]);
            }
        }

        return $rows;
    }

    /** @param array<string, string> $rows */
    private static function cell(array $rows, string $label, string $where): string
    {
        return ($rows[$label] ?? '') !== '' ? $rows[$label] : throw ParseFailure::at($where, "no \"{$label}\"");
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
     * "7,60% pe an", "6,50%" → "7.60", "6.50".
     *
     * @return numeric-string
     */
    private static function percent(string $text, string $where): string
    {
        if (preg_match('/\A(\d{1,2},\d{1,4})\s*%/', $text, $rate) !== 1) {
            throw ParseFailure::at($where, 'not a rate');
        }

        return Values::decimal($rate[1], $where);
    }

    /**
     * "100 lei", "1 leu", "100 euro" → the amount and its currency.
     *
     * @return array{numeric-string, string}
     */
    private static function amount(string $text, string $where): array
    {
        if (preg_match('/\A(\d[\d.,]*)\s+(lei|leu|euro)\b/u', $text, $amount) !== 1) {
            throw ParseFailure::at($where, 'not a face value');
        }

        return [Values::decimal($amount[1], $where), self::CURRENCIES[$amount[2] === 'leu' ? 'lei' : $amount[2]]];
    }

    /**
     * "14 octombrie 2027, 14 octombrie 2028" → each date.
     *
     * @return non-empty-list<DateTimeImmutable>
     */
    private static function dates(string $text, string $where): array
    {
        return array_map(static fn (string $date): DateTimeImmutable => Values::romanianDate(trim($date), $where), explode(',', $text));
    }
}
