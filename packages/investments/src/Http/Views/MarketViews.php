<?php

declare(strict_types=1);

namespace Kern\Investments\Http\Views;

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
        ];
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
    public static function profile(IssuerProfile $profile): array
    {
        return [
            'issuerKey' => $profile->issuer_key,
            'meetings' => $profile->meetings,
            'shareholders' => $profile->shareholders,
            'shareholdersAsOf' => $profile->shareholders_as_of?->format('Y-m-d'),
            'readAt' => $profile->read_at->toIso8601String(),
        ];
    }
}
