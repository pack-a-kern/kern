<?php

declare(strict_types=1);

namespace Kern\Investments\Queries;

use DateTimeImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Kern\Investments\Contracts\MarketData;
use Kern\Investments\DataTransferObjects\InstrumentTerms;
use Kern\Investments\DataTransferObjects\LatestPrice;
use Kern\Investments\DataTransferObjects\PayoutTerms;
use Kern\Investments\Models\FinancialInstrument;
use Kern\Investments\Models\InstrumentPayout;
use Kern\Investments\Models\InstrumentPrice;

/**
 * The market read from this package's own tables. Each question is one query, served by an index the
 * tables already have: the primary key; (`instrument_id`, `kind`, `payment_date`); (`instrument_id`,
 * `trading_date`).
 */
final readonly class EloquentMarketData implements MarketData
{
    public function instruments(array $ids): array
    {
        $terms = [];

        foreach (FinancialInstrument::query()->whereKey($ids)->get() as $instrument) {
            $terms[$instrument->id] = new InstrumentTerms(
                id: $instrument->id,
                kind: $instrument->kind,
                programme: $instrument->programme,
                status: $instrument->status,
                name: $instrument->name,
                isin: $instrument->isin,
                symbol: $instrument->symbol,
                issueCode: $instrument->issue_code,
                currency: $instrument->currency,
                faceValue: $instrument->face_value,
                ratePercent: $instrument->rate,
                issueDate: self::date($instrument->issue_date),
                maturityDate: self::date($instrument->maturity_date),
            );
        }

        return $terms;
    }

    public function payouts(array $instrumentIds, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return InstrumentPayout::query()
            ->whereIn('instrument_id', $instrumentIds)
            // By date, not by the stored text: SQLite keeps a date written through a model with its time.
            ->whereDate('payment_date', '>=', $from->format('Y-m-d'))
            ->whereDate('payment_date', '<=', $to->format('Y-m-d'))
            ->orderBy('payment_date')
            ->get()
            ->map(static fn (InstrumentPayout $payout): PayoutTerms => new PayoutTerms(
                id: $payout->id,
                instrumentId: $payout->instrument_id,
                kind: $payout->kind,
                periodStart: self::date($payout->period_start),
                periodEnd: self::date($payout->period_end),
                exDate: self::date($payout->ex_date),
                recordDate: self::date($payout->record_date),
                paymentDate: $payout->payment_date->toDateTimeImmutable(),
                amountPerUnit: $payout->amount_per_unit,
                ratePercent: $payout->rate,
            ))
            ->values()
            ->all();
    }

    public function latestPrices(array $instrumentIds): array
    {
        $latest = InstrumentPrice::query()
            ->select('instrument_id')
            ->selectRaw('max(trading_date) as trading_date')
            ->whereIn('instrument_id', $instrumentIds)
            ->groupBy('instrument_id');

        $prices = [];

        foreach (InstrumentPrice::query()
            ->joinSub($latest, 'latest', static fn (JoinClause $join): JoinClause => $join
                ->on('ext_instrument_prices.instrument_id', '=', 'latest.instrument_id')
                ->on('ext_instrument_prices.trading_date', '=', 'latest.trading_date'))
            ->get(['ext_instrument_prices.*']) as $price) {
            $prices[$price->instrument_id] = new LatestPrice($price->instrument_id, $price->trading_date->toDateTimeImmutable(), $price->price);
        }

        return $prices;
    }

    private static function date(?Carbon $date): ?DateTimeImmutable
    {
        return $date?->toDateTimeImmutable();
    }
}
