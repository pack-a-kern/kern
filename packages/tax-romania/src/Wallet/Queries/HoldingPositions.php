<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Kern\TaxRomania\Wallet\DataTransferObjects\HoldingLine;
use Kern\TaxRomania\Wallet\DataTransferObjects\HoldingPosition;
use Kern\TaxRomania\Wallet\Models\InvestmentLot;
use Kern\TaxRomania\Wallet\Models\InvestmentLotPayout;

/**
 * The active taxpayer's holdings a page at a time, one position per instrument per account — open (units
 * still held) or sold.
 *
 * Two queries and one look at the market, for the page only: the positions are found and counted by
 * grouping the lots in SQL (account, instrument — keys and dates, no amounts), then only that page's lots
 * are loaded and added up here, with bcmath. Amounts stay out of SQL: they are exact decimal text, and a
 * `sum()` on SQLite is a float. Tenant-scoped through `InvestmentLot` and `InvestmentLotPayout`.
 */
final readonly class HoldingPositions
{
    private const int SCALE = 15;

    public function __construct(private Holdings $holdings)
    {
    }

    /**
     * @param positive-int $page
     * @param positive-int $perPage
     * @return array{positions: list<HoldingPosition>, total: int}
     */
    public function page(bool $open, int $page, int $perPage, ?CarbonImmutable $today = null): array
    {
        $groups = InvestmentLot::query()
            ->tap(self::state($open))
            ->select(['financial_account_id', 'instrument_id'])
            ->selectRaw('min(bought_on) AS first_bought_on, max(sold_on) AS last_sold_on')
            ->groupBy('financial_account_id', 'instrument_id');
        $total = (clone $groups)->toBase()->getCountForPagination();
        // Open: the position held longest first. Sold: the latest sale first. The keys break a tie.
        /** @var list<string> $keys */
        $keys = $groups
            ->when($open, static fn (Builder $q) => $q->orderBy('first_bought_on'), static fn (Builder $q) => $q->orderByDesc('last_sold_on'))
            ->orderBy('financial_account_id')
            ->orderBy('instrument_id')
            ->forPage($page, $perPage)
            ->get()
            ->map(static fn (InvestmentLot $group): string => self::key($group->financial_account_id, $group->instrument_id))
            ->values()
            ->all();

        if ($keys === []) {
            return ['positions' => [], 'total' => $total];
        }

        $split = array_map(static fn (string $key): array => explode('|', $key), $keys);
        /** @var list<InvestmentLot> $lots */
        $lots = InvestmentLot::query()
            ->tap(self::state($open))
            ->whereIn('financial_account_id', array_unique(array_column($split, 0)))
            ->whereIn('instrument_id', array_unique(array_column($split, 1)))
            ->orderBy('bought_on')
            ->orderBy('id')
            ->get()
            ->filter(static fn (InvestmentLot $lot): bool => in_array(self::key($lot->financial_account_id, $lot->instrument_id), $keys, true))
            ->values()
            ->all();
        $received = InvestmentLotPayout::query()
            ->whereIn('lot_id', array_map(static fn (InvestmentLot $lot): string => $lot->id, $lots))
            ->groupBy('lot_id')
            ->selectRaw('lot_id, sum(net_minor) AS received')
            ->pluck('received', 'lot_id')
            ->all();
        $byKey = [];

        foreach ($this->holdings->lines($lots, $today) as $line) {
            $byKey[self::key($line->accountId, $line->instrumentId)][] = $line;
        }

        return [
            'positions' => array_map(fn (string $key): HoldingPosition => $this->position($byKey[$key], $received), $keys),
            'total' => $total,
        ];
    }

    /**
     * @param non-empty-list<HoldingLine> $lines
     * @param array<string, mixed> $received by lot id
     */
    private function position(array $lines, array $received): HoldingPosition
    {
        $first = $lines[0];
        $units = '0';
        $cost = 0;
        $values = [];
        $proceeds = [];
        $paid = 0;

        foreach ($lines as $line) {
            assert(is_numeric($line->units));
            $units = bcadd($units, $line->units, 6);
            $cost += $line->costMinor;
            $values[] = $line->valueMinor;
            $proceeds[] = $line->proceedsMinor;
            $paid += is_numeric($received[$line->id] ?? null) ? (int) $received[$line->id] : 0;
        }

        // Worth something only when every purchase is: a total over some of them would be a wrong total.
        $value = in_array(null, $values, true) ? null : array_sum($values);
        $proceedsTotal = in_array(null, $proceeds, true) ? null : array_sum($proceeds);
        $result = $proceedsTotal !== null ? $proceedsTotal - $cost : ($value === null ? null : $value - $cost);
        $soldOn = array_filter(array_map(static fn (HoldingLine $line): ?string => $line->soldOn, $lines));
        $basis = $value === null ? null : $first->valuationBasis;

        return new HoldingPosition(
            accountId: $first->accountId,
            instrumentId: $first->instrumentId,
            instrumentName: $first->instrumentName,
            instrumentKind: $first->instrumentKind,
            programme: $first->programme,
            isin: $first->isin,
            symbol: $first->symbol,
            issueCode: $first->issueCode,
            maturityDate: $first->maturityDate,
            currency: $first->currency,
            heldAt: $first->heldAt,
            units: $units,
            costMinor: $cost,
            // The weighted average price paid per unit, fees in: the cost over the units, in major units.
            averagePrice: bcdiv(bcdiv((string) $cost, '100', self::SCALE), $units, 4),
            firstBoughtOn: $first->boughtOn,
            lastSoldOn: $soldOn === [] ? null : max($soldOn),
            valueMinor: $value,
            valuationBasis: $basis,
            valuedOn: $value === null ? null : $first->valuedOn,
            proceedsMinor: $proceedsTotal,
            resultMinor: $result,
            resultPercent: $result === null || $cost === 0 ? null : bcdiv(bcmul((string) $result, '100', self::SCALE), (string) $cost, 2),
            receivedMinor: $paid,
            lots: $lines,
        );
    }

    /** @return \Closure(Builder<InvestmentLot>): Builder<InvestmentLot> */
    private static function state(bool $open): \Closure
    {
        return static fn (Builder $query): Builder => $open ? $query->whereNull('sold_on') : $query->whereNotNull('sold_on');
    }

    private static function key(string $accountId, string $instrumentId): string
    {
        return "{$accountId}|{$instrumentId}";
    }
}
