<?php

declare(strict_types=1);

namespace Kern\Investments\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Kern\Investments\Config\InvestmentsConfig;
use Kern\Investments\Enums\InstrumentKind;
use Kern\Investments\Enums\InstrumentStatus;
use Kern\Investments\Enums\Programme;
use Kern\Investments\Http\Views\MarketViews;
use Kern\Investments\Models\FinancialInstrument;
use Kern\Investments\Models\InstrumentPrice;

/**
 * `GET /api/v1/investments/instruments` — the market, filtered by kind, programme and status (active, by
 * default), searched by a symbol, an ISIN or a code exactly, or by words of a name; a page at a time by
 * cursor, each with its latest price.
 *
 * Ordered by name and id: both compare as text on either database, so a cursor reads the same on both.
 */
final class ListInstrumentsController
{
    public function __invoke(Request $request, InvestmentsConfig $config): JsonResponse
    {
        $filters = $request->validate([
            'kind' => ['sometimes', Rule::enum(InstrumentKind::class)],
            'programme' => ['sometimes', Rule::enum(Programme::class)],
            'status' => ['sometimes', Rule::enum(InstrumentStatus::class)],
            'q' => ['sometimes', 'string', 'min:2', 'max:64'],
        ]);

        $latest = static fn (string $column): Builder => InstrumentPrice::query()
            ->select($column)
            ->whereColumn('instrument_id', 'ext_financial_instruments.id')
            ->orderByDesc('trading_date')
            ->limit(1);

        $query = FinancialInstrument::query()
            ->select('ext_financial_instruments.*')
            ->addSelect(['latest_price' => $latest('price'), 'latest_trading_date' => $latest('trading_date')])
            ->where('status', $filters['status'] ?? InstrumentStatus::ACTIVE->value)
            ->when($filters['kind'] ?? null, static fn (Builder $q, string $kind): Builder => $q->where('kind', $kind))
            ->when($filters['programme'] ?? null, static fn (Builder $q, string $programme): Builder => $q->where('programme', $programme))
            ->when($filters['q'] ?? null, self::search(...))
            ->orderBy('name')
            ->orderBy('id');

        $page = $query->cursorPaginate($config->api->pageSize);

        return response()->json([
            'data' => array_map(MarketViews::instrument(...), $page->items()),
            'nextCursor' => $page->nextCursor()?->encode(),
        ]);
    }

    /**
     * A code exactly — symbol, ISIN, Tezaur code — or words of a name, accents and case aside: on Postgres
     * through the name's trigram index, on SQLite by its lower case.
     *
     * @param Builder<FinancialInstrument> $query
     *
     * @return Builder<FinancialInstrument>
     */
    private static function search(Builder $query, string $words): Builder
    {
        $code = strtoupper(trim($words));
        $like = '%' . addcslashes(mb_strtolower(trim($words)), '%_\\') . '%';
        $name = DB::connection()->getDriverName() === 'pgsql'
            ? ['kern_unaccent(lower(name)) LIKE kern_unaccent(?)', [$like]]
            : ['lower(name) LIKE ?', [$like]];

        return $query->where(static fn (Builder $q): Builder => $q
            ->where('symbol', $code)
            ->orWhere('isin', $code)
            ->orWhere('issue_code', trim($words))
            ->orWhereRaw(...$name));
    }
}
