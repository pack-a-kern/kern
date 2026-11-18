<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Kern\Platform\Tenancy\TenantContext;

/**
 * Who this taxpayer banks with, read off their own transactions: one line per bank id, with how many
 * movements carry it, over what span, through how many accounts, and what moved each way per currency.
 *
 * ## Why this is not {@see WalletReports}'s by-bank panel
 *
 * That answers "how much came through each bank *this year*", for spending — it is period-bound, drops
 * transfers and cash, and reports money only. This answers a standing question a report does not: which
 * institutions appear in the ledger at all, since when, and how much of it is theirs. Every row counts,
 * transfers included, because the question is activity through a bank and not income or spending.
 *
 * ## Why only the id, never the name
 *
 * The same boundary {@see WalletReports} keeps: `Wallet` may not reach into `Banks`, so a bank id is
 * turned into a name, a code and a logo in the HTTP layer, where a controller is free to call
 * `Banks\Queries\BankDirectory`. This query stays a query over the ledger's own columns.
 *
 * ## Why figures stay per currency
 *
 * The same reason they do everywhere else: 100 EUR and 100 RON are not 200 of anything, so money in
 * and out are grouped by currency and never added across them.
 *
 * Nothing here decrypts: the counts are over columns, so no counterparty or description — the
 * encrypted fields — is read to build the rundown.
 */
final readonly class BankActivity
{
    public function __construct(private TenantContext $tenant)
    {
    }

    /**
     * @return array{
     *     banks: list<array{
     *         bank_id: ?string,
     *         transaction_count: int,
     *         account_count: int,
     *         first_booked_at: ?string,
     *         last_booked_at: ?string,
     *         flows: list<array{currency_code: string, in_minor: int, out_minor: int, count: int}>
     *     }>,
     *     totals: array{transaction_count: int, identified_count: int, unidentified_count: int}
     * }
     */
    public function rundown(): array
    {
        $spans = $this->spans();
        $flows = $this->flows();

        $rows = [];
        $identified = 0;
        $transactions = 0;

        foreach ($spans as $bankId => $span) {
            $transactions += $span['count'];

            if ($bankId !== '') {
                $identified += $span['count'];
            }

            $rows[] = [
                'bank_id' => $bankId === '' ? null : $bankId,
                'transaction_count' => $span['count'],
                'account_count' => $span['accounts'],
                'first_booked_at' => $span['first'],
                'last_booked_at' => $span['last'],
                'flows' => $flows[$bankId] ?? [],
            ];
        }

        // The busiest relationship first, so the rundown opens on the bank that holds most of the
        // ledger. The unidentified bucket sinks to the end wherever its count lands: it is a gap in the
        // data, not a bank, and reads as the footnote it is rather than the headline. The id is the
        // tie-break only to keep equal-count rows in a stable order — names are not known here.
        usort($rows, static function (array $a, array $b): int {
            if (($a['bank_id'] === null) !== ($b['bank_id'] === null)) {
                return $a['bank_id'] === null ? 1 : -1;
            }

            return $b['transaction_count'] <=> $a['transaction_count']
                ?: strcmp((string) $a['bank_id'], (string) $b['bank_id']);
        });

        return [
            'banks' => $rows,
            'totals' => [
                'transaction_count' => $transactions,
                'identified_count' => $identified,
                'unidentified_count' => $transactions - $identified,
            ],
        ];
    }

    /**
     * Per bank: how many movements carry it, across how many accounts, and the first and last dates.
     *
     * Keyed by bank id, with the empty string standing for "no bank" — a map key cannot be null, and
     * the caller turns it back into one. `count(distinct account_id)` is the one figure a second query
     * could not recover from the per-currency flows, which group by account too coarsely to count them.
     *
     * @return array<string, array{count: int, accounts: int, first: ?string, last: ?string}>
     */
    private function spans(): array
    {
        $rows = DB::table('financial_transactions')
            ->where('tax_subject_id', $this->tenant->currentTenantId())
            ->groupBy('bank_id')
            ->get([
                'bank_id',
                DB::raw('count(*) as rows_count'),
                DB::raw('count(distinct account_id) as accounts_count'),
                DB::raw('min(booked_at) as first_at'),
                DB::raw('max(booked_at) as last_at'),
            ]);

        $spans = [];

        foreach ($rows as $row) {
            $spans[$row->bank_id === null ? '' : (string) $row->bank_id] = [
                'count' => (int) $row->rows_count,
                'accounts' => (int) $row->accounts_count,
                'first' => self::date($row->first_at),
                'last' => self::date($row->last_at),
            ];
        }

        return $spans;
    }

    /**
     * Per bank and currency: what came in, what went out, and over how many movements.
     *
     * @return array<string, list<array{currency_code: string, in_minor: int, out_minor: int, count: int}>>
     */
    private function flows(): array
    {
        $rows = DB::table('financial_transactions')
            ->where('tax_subject_id', $this->tenant->currentTenantId())
            ->groupBy('bank_id', 'currency_code')
            ->orderBy('currency_code')
            ->get([
                'bank_id',
                'currency_code',
                DB::raw('sum(case when amount_minor > 0 then amount_minor else 0 end) as money_in'),
                DB::raw('sum(case when amount_minor < 0 then -amount_minor else 0 end) as money_out'),
                DB::raw('count(*) as rows_count'),
            ]);

        $flows = [];

        foreach ($rows as $row) {
            $flows[$row->bank_id === null ? '' : (string) $row->bank_id][] = [
                'currency_code' => (string) $row->currency_code,
                'in_minor' => (int) $row->money_in,
                'out_minor' => (int) $row->money_out,
                'count' => (int) $row->rows_count,
            ];
        }

        return $flows;
    }

    /**
     * A `min`/`max` date as `YYYY-MM-DD`, however the driver hands it back.
     *
     * A `date` column comes back as a string on both drivers; parsing it through Carbon gives one shape
     * to the client and keeps this query free of a driver-specific date function, so it runs on either
     * test lane. Anything that is not a string degrades to no date rather than a guess.
     */
    private static function date(mixed $value): ?string
    {
        return is_string($value) ? CarbonImmutable::parse($value)->toDateString() : null;
    }
}
