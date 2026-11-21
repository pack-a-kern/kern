<?php

declare(strict_types=1);

namespace Kern\Investments\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Kern\Investments\Enums\InstrumentKind;
use Kern\Investments\Enums\InstrumentStatus;
use Kern\Investments\Enums\Programme;
use Kern\Investments\Http\Views\MarketViews;
use Kern\Investments\Models\FinancialInstrument;
use Kern\Investments\Models\InstrumentPrice;
use Kern\Platform\Routing\PageSizes;

/**
 * `GET /api/v1/investments/instruments` — the market, filtered by kind, programme and status (active, by
 * default), searched by a symbol, an ISIN or a code exactly, or by words of a name; a numbered `page` of
 * `per_page` (KERN_PAGE_SIZES), each with its latest price — with how many there are in all, and how many
 * of each kind the other filters keep.
 *
 * Ordered by name, the id breaking a tie, so an instrument is on one page, never on two or none.
 */
final class ListInstrumentsController
{
    public function __invoke(Request $request, PageSizes $sizes): JsonResponse
    {
        $filters = $request->validate([
            'kind' => ['sometimes', Rule::enum(InstrumentKind::class)],
            'programme' => ['sometimes', Rule::enum(Programme::class)],
            'status' => ['sometimes', Rule::enum(InstrumentStatus::class)],
            'q' => ['sometimes', 'string', 'min:2', 'max:64'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => $sizes->rule(),
        ]);

        $latest = static fn (string $column): Builder => InstrumentPrice::query()
            ->select($column)
            ->whereColumn('instrument_id', 'ext_financial_instruments.id')
            ->orderByDesc('trading_date')
            ->limit(1);

        // What the status, programme and search keep, of every kind: the chips count from it, so each
        // says how many choosing it would show.
        $kept = FinancialInstrument::query()
            ->where('status', $filters['status'] ?? InstrumentStatus::ACTIVE->value)
            ->when($filters['programme'] ?? null, static fn (Builder $q, string $programme): Builder => $q->where('programme', $programme))
            ->when($filters['q'] ?? null, self::search(...));
        /** @var array<string, int|string> $counts */
        $counts = (clone $kept)->toBase()->selectRaw('kind, count(*) AS kept')->groupBy('kind')->pluck('kept', 'kind')->all();
        $query = $kept
            ->select('ext_financial_instruments.*')
            ->with('issuerProfile')
            ->addSelect(['latest_price' => $latest('price'), 'latest_trading_date' => $latest('trading_date')])
            ->when($filters['kind'] ?? null, static fn (Builder $q, string $kind): Builder => $q->where('kind', $kind))
            ->orderBy('name')
            ->orderBy('id');

        $page = $query->paginate($sizes->of($filters['per_page'] ?? null), ['*'], 'page', is_numeric($filters['page'] ?? null) ? (int) $filters['page'] : 1);

        return response()->json([
            'data' => array_map(MarketViews::instrument(...), $page->items()),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'perPage' => $page->perPage(),
            'counts' => array_map(intval(...), $counts),
            // Every kind together: the "all" tab's count.
            'countAll' => array_sum(array_map(intval(...), $counts)),
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
