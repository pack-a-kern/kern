<?php

declare(strict_types=1);

namespace Kern\Investments\Http\Views;

use Illuminate\Support\Carbon;
use Kern\Investments\Models\FinancialInstrument;
use Kern\Investments\Models\InstrumentPayout;
use Kern\Investments\Models\InstrumentPrice;
use Kern\Investments\Models\IssuerProfile;

/**
 * What each endpoint answers, shaped for the screen (ADR 0025 §7): dates as `Y-m-d`, decimals as the text
 * they are stored as, enums by value. Nothing is left for the browser to parse or compute.
 */
final class MarketViews
{
    /** @return array<string, mixed> */
    public static function instrument(FinancialInstrument $instrument): array
    {
        $price = $instrument->getAttribute('latest_price');
        $priced = $instrument->getAttribute('latest_trading_date');

        return [
            'id' => $instrument->id,
            'kind' => $instrument->kind->value,
            'programme' => $instrument->programme?->value,
            'isin' => $instrument->isin,
            'symbol' => $instrument->symbol,
            'issueCode' => $instrument->issue_code,
            'name' => $instrument->name,
            'issuer' => $instrument->issuer_name,
            'currency' => $instrument->currency,
            'faceValue' => $instrument->face_value,
            'ratePercent' => $instrument->rate,
            'issueDate' => $instrument->issue_date?->format('Y-m-d'),
            'maturityDate' => $instrument->maturity_date?->format('Y-m-d'),
            'market' => $instrument->market?->value,
            'status' => $instrument->status->value,
            'subscribedValueMinor' => $instrument->subscribed_value_minor,
            'subscriptions' => $instrument->subscriptions_count,
            'latestPrice' => is_string($price) ? ['price' => $price, 'tradingDate' => is_string($priced) ? substr($priced, 0, 10) : null] : null,
            'maturesIn' => self::until($instrument->maturity_date),
        ];
    }

    /**
     * How a price moved on its day, its sign written and its direction named: "+0.35" up, "-0.12" down,
     * "0.00" flat; none for a price BVB gave no change for.
     *
     * @return array{percent: string, trend: 'UP'|'DOWN'|'FLAT'}|null
     */
    public static function change(?string $percent): ?array
    {
        // Stored as a decimal's text: anything else is no move to tell.
        if (! is_numeric($percent)) {
            return null;
        }

        $sign = bccomp($percent, '0', 6);

        return [
            'percent' => $sign > 0 ? "+{$percent}" : $percent,
            'trend' => match ($sign) {
                1 => 'UP',
                -1 => 'DOWN',
                default => 'FLAT',
            },
        ];
    }

    /**
     * How far off a maturity still to come is, in whole years from a year on and in months before:
     * `{value: 3, unit: 'year'}`. None for no maturity, or one already past — the status says matured.
     *
     * @return array{value: int, unit: 'year'|'month'}|null
     */
    private static function until(?Carbon $maturity): ?array
    {
        $today = Carbon::today('UTC');

        if ($maturity === null || $maturity->lessThan($today)) {
            return null;
        }

        $months = ($maturity->year - $today->year) * 12 + ($maturity->month - $today->month);

        return $months >= 12 ? ['value' => (int) round($months / 12), 'unit' => 'year'] : ['value' => $months, 'unit' => 'month'];
    }

    /** @return array<string, mixed> */
    public static function payout(InstrumentPayout $payout): array
    {
        return [
            'id' => $payout->id,
            'instrumentId' => $payout->instrument_id,
            'kind' => $payout->kind->value,
            'periodStart' => $payout->period_start?->format('Y-m-d'),
            'periodEnd' => $payout->period_end?->format('Y-m-d'),
            'exDate' => $payout->ex_date?->format('Y-m-d'),
            'recordDate' => $payout->record_date?->format('Y-m-d'),
            'paymentDate' => $payout->payment_date->format('Y-m-d'),
            'amountPerUnit' => $payout->amount_per_unit,
            'ratePercent' => $payout->rate,
            'financialYear' => $payout->financial_year,
            'status' => $payout->status->value,
            'source' => $payout->source->value,
        ];
    }

    /** @return array<string, mixed> */
    public static function price(InstrumentPrice $price): array
    {
        return [
            'tradingDate' => $price->trading_date->format('Y-m-d'),
            'price' => $price->price,
            'changePercent' => $price->change_percent,
            'pricedAt' => $price->priced_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function profile(IssuerProfile $profile, ?string $termsRate): array
    {
        return [
            'issuerKey' => $profile->issuer_key,
            'meetings' => self::dated($profile->meetings),
            'shareholders' => $profile->shareholders,
            'shareholdersAsOf' => $profile->shareholders_as_of?->format('Y-m-d'),
            'financialCalendar' => self::dated($profile->financial_calendar ?? []),
            'figures' => [
                'marketCap' => $profile->market_cap,
                'priceEarnings' => $profile->price_earnings,
                'priceBook' => $profile->price_book,
                'earningsPerShare' => $profile->earnings_per_share,
                'dividendYield' => $profile->dividend_yield,
                'lastDividend' => $profile->last_dividend,
                'lastDividendYear' => $profile->last_dividend_year,
                'week52High' => $profile->week52_high,
                'week52Low' => $profile->week52_low,
                'sharesTotal' => $profile->shares_total,
                'faceValue' => $profile->face_value,
                'shareCapital' => $profile->share_capital,
                'tradingSince' => $profile->trading_since?->format('Y-m-d'),
                'segment' => $profile->segment,
                'category' => $profile->category,
                'bid' => $profile->bid,
                'ask' => $profile->ask,
                'couponRate' => $profile->coupon_rate,
                'yieldToMaturity' => $profile->yield_to_maturity,
                'unitsIssued' => $profile->units_issued,
                'issueValue' => $profile->issue_value,
                // A bond's coupon as BVB shows it, said only where it is not the rate its terms give.
                'couponDiffers' => is_numeric($profile->coupon_rate) && is_numeric($termsRate) && bccomp($profile->coupon_rate, $termsRate, 6) !== 0,
            ],
            'readAt' => $profile->read_at->toIso8601String(),
        ];
    }

    /**
     * Days on an issuer's calendar, latest first, each said to be still to come from its own day on.
     *
     * @param list<array<string, mixed>> $items each with its `date` as `Y-m-d`
     *
     * @return list<array<string, mixed>>
     */
    private static function dated(array $items): array
    {
        $today = Carbon::today('UTC')->format('Y-m-d');
        $date = static fn (array $item): string => is_string($item['date'] ?? null) ? $item['date'] : '';

        usort($items, static fn (array $a, array $b): int => strcmp($date($b), $date($a)));

        return array_map(static fn (array $item): array => [...$item, 'upcoming' => $date($item) >= $today], $items);
    }
}
