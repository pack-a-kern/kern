<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Exports;

use Kern\Platform\Text\CsvFile;

/**
 * A wallet report as four CSVs — the four panels the screen is made of.
 *
 * ## Why four and not one
 *
 * They have different columns and different readers. The months are what somebody sends an
 * accountant; the breakdown is what they look at themselves; the movement is what reconciles against
 * a bank's own statement; the largest payments are what gets spot-checked. One sheet with four
 * tables stacked in it is none of those, and a spreadsheet cannot sort a file like that.
 *
 * ## Every currency, always
 *
 * The screen shows one currency at a time because a reader can only hold one; a file has a column
 * for it instead and carries them all. A download that silently dropped the EUR rows would be
 * impossible to notice afterwards — and 100 EUR and 100 RON are still never added together here,
 * which is the one property a report of two currencies has to keep.
 *
 * ## The same figures the screen ranks
 *
 * These come from `WalletReports`, so the file says exactly what the page says, including its limits
 * — ten rows to a breakdown, five payments each way. The point of an export is a second copy of the
 * report, not a second query that might disagree with it.
 */
final readonly class WalletReportsCsv
{
    /**
     * The year by month: what came in, what went out, and what was left.
     *
     * @param  list<array{month: string, currency_code: string, in_minor: int, out_minor: int, count: int, top_category: ?string}>  $cashFlow
     */
    public function months(array $cashFlow): string
    {
        $rows = [['month', 'currency', 'money_in', 'money_out', 'net', 'transactions', 'top_category']];

        foreach ($cashFlow as $row) {
            $rows[] = [
                $row['month'],
                $row['currency_code'],
                CsvFile::decimal($row['in_minor']),
                CsvFile::decimal($row['out_minor']),
                CsvFile::decimal($row['in_minor'] - $row['out_minor']),
                (string) $row['count'],
                $row['top_category'] ?? '',
            ];
        }

        return CsvFile::fromRows($rows);
    }

    /**
     * Spending ranked three ways, in one file with the dimension named in its own column.
     *
     * Three files would have been three downloads of the same question — "what did the money go on" —
     * answered by tag, by category and by counterparty. A column says which, so a pivot table can
     * take them apart again and a reader scrolling does not lose track of what they are looking at.
     *
     * @param  list<array{tag_id: string, name: string, currency_code: string, total_minor: int, months: list<array{month: string, amount_minor: int}>}>  $byTag
     * @param  list<array{category_code: string, currency_code: string, total_minor: int}>  $byCategory
     * @param  list<array{name: ?string, currency_code: string, total_minor: int, count: int}>  $byCounterparty
     */
    public function breakdown(array $byTag, array $byCategory, array $byCounterparty): string
    {
        $rows = [['dimension', 'label', 'currency', 'total', 'transactions']];

        foreach ($byTag as $row) {
            $rows[] = ['tag', $row['name'], $row['currency_code'], CsvFile::decimal($row['total_minor']), ''];
        }

        foreach ($byCategory as $row) {
            $rows[] = [
                'category',
                $row['category_code'],
                $row['currency_code'],
                CsvFile::decimal($row['total_minor']),
                '',
            ];
        }

        foreach ($byCounterparty as $row) {
            $rows[] = [
                'counterparty',
                $row['name'] ?? '',
                $row['currency_code'],
                CsvFile::decimal($row['total_minor']),
                (string) $row['count'],
            ];
        }

        return CsvFile::fromRows($rows);
    }

    /**
     * Where the money moved: by bank, by account, and the period's own totals per currency.
     *
     * @param  list<array{bank_id: ?string, name: ?string, currency_code: string, in_minor: int, out_minor: int}>  $byBank
     * @param  list<array{account_id: string, name: ?string, kind: string, currency_code: string, in_minor: int, out_minor: int}>  $byAccount
     * @param  list<array{currency_code: string, in_minor: int, out_minor: int, count: int}>  $totals
     */
    public function movement(array $byBank, array $byAccount, array $totals): string
    {
        $rows = [['dimension', 'source', 'kind', 'currency', 'money_in', 'money_out']];

        foreach ($byBank as $row) {
            $rows[] = [
                'bank',
                $row['name'] ?? '',
                '',
                $row['currency_code'],
                CsvFile::decimal($row['in_minor']),
                CsvFile::decimal($row['out_minor']),
            ];
        }

        foreach ($byAccount as $row) {
            $rows[] = [
                'account',
                $row['name'] ?? '',
                $row['kind'],
                $row['currency_code'],
                CsvFile::decimal($row['in_minor']),
                CsvFile::decimal($row['out_minor']),
            ];
        }

        foreach ($totals as $row) {
            $rows[] = [
                'currency',
                $row['currency_code'],
                '',
                $row['currency_code'],
                CsvFile::decimal($row['in_minor']),
                CsvFile::decimal($row['out_minor']),
            ];
        }

        return CsvFile::fromRows($rows);
    }

    /**
     * The biggest movements each way, with the id that reaches the transaction itself.
     *
     * The counterparty and the description are in here, unlike the tax assessment's evidence file,
     * and the difference is deliberate: these are the same two fields this screen already shows to
     * the same reader under the same permission, so the file carries no more than the page it came
     * from. The download is recorded in the audit chain either way.
     *
     * @param  list<array{id: string, booked_at: string, counterparty: ?string, description: ?string, currency_code: string, amount_minor: int}>  $largest
     */
    public function largest(array $largest): string
    {
        $rows = [['booked_at', 'counterparty', 'description', 'currency', 'amount', 'transaction_id']];

        foreach ($largest as $row) {
            $rows[] = [
                $row['booked_at'],
                $row['counterparty'] ?? '',
                $row['description'] ?? '',
                $row['currency_code'],
                CsvFile::decimal($row['amount_minor']),
                $row['id'],
            ];
        }

        return CsvFile::fromRows($rows);
    }
}
