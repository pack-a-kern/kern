<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Queries;

use Carbon\CarbonImmutable;
use Kern\Investments\Contracts\MarketData;
use Kern\Investments\DataTransferObjects\InstrumentTerms;
use Kern\Investments\DataTransferObjects\LatestPrice;
use Kern\Investments\Enums\InstrumentKind;
use Kern\Investments\Enums\Programme;
use Kern\TaxRomania\Wallet\DataTransferObjects\HoldingLine;
use Kern\TaxRomania\Wallet\Enums\ValuationBasis;
use Kern\TaxRomania\Wallet\Models\InvestmentLot;
use Kern\TaxRomania\Wallet\Services\Holdings\LotAmounts;

/**
 * The active taxpayer's lots, each with its instrument's terms and what follows from them.
 *
 * **The value is recomputed each time it is read**, from the instrument's latest price — so after each
 * BVB run it is the new one, with nothing stored to go stale (ADR 0025 §9). A share is worth units ×
 * price; a bond units × face value × price, BVB's price being a percent of the face value. A Tezaur bond
 * is never listed, and a Fidelis has no price until its first trading day: both are worth their face
 * value. A corporate or municipal bond not yet priced, and a bond past its maturity — repaid, its money
 * already in the account — have none.
 *
 * Tenant-scoped through `InvestmentLot`; the market is asked once for every instrument on the page.
 */
final readonly class Holdings
{
    public function __construct(private MarketData $market)
    {
    }

    /** @return list<HoldingLine> oldest purchase first */
    public function all(?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::now('UTC'))->startOfDay()->toDateTimeImmutable();
        /** @var list<InvestmentLot> $lots */
        $lots = InvestmentLot::query()->orderBy('bought_on')->orderBy('id')->get()->all();
        $ids = array_values(array_unique(array_map(static fn (InvestmentLot $lot): string => $lot->instrument_id, $lots)));
        $instruments = $this->market->instruments($ids);
        $prices = $this->market->latestPrices($ids);

        return array_map(function (InvestmentLot $lot) use ($instruments, $prices, $today): HoldingLine {
            $instrument = $instruments[$lot->instrument_id];
            $cost = LotAmounts::minor($lot->units, $lot->price_per_unit) + $lot->fees_minor;
            $proceeds = $lot->sold_price_per_unit === null ? null : LotAmounts::minor($lot->units, $lot->sold_price_per_unit) - (int) $lot->sale_fees_minor;
            $matured = $instrument->maturityDate !== null && $instrument->maturityDate < $today;
            [$value, $basis, $valuedOn] = $lot->sold_on !== null || $matured ? [null, null, null] : $this->value($lot, $instrument, $prices[$lot->instrument_id] ?? null);

            return new HoldingLine(
                id: $lot->id,
                accountId: $lot->financial_account_id,
                instrumentId: $instrument->id,
                instrumentName: $instrument->name,
                instrumentKind: $instrument->kind,
                programme: $instrument->programme,
                isin: $instrument->isin,
                symbol: $instrument->symbol,
                issueCode: $instrument->issueCode,
                faceValue: $instrument->faceValue,
                ratePercent: $instrument->ratePercent,
                maturityDate: $instrument->maturityDate?->format('Y-m-d'),
                currency: $lot->currency,
                heldAt: $lot->held_at,
                units: $lot->units,
                pricePerUnit: $lot->price_per_unit,
                boughtOn: $lot->bought_on->toDateString(),
                feesMinor: $lot->fees_minor,
                costMinor: $cost,
                soldOn: $lot->sold_on?->toDateString(),
                soldPricePerUnit: $lot->sold_price_per_unit,
                saleFeesMinor: $lot->sale_fees_minor,
                proceedsMinor: $proceeds,
                resultMinor: $proceeds === null ? null : $proceeds - $cost,
                valueMinor: $value,
                valuationBasis: $basis,
                valuedOn: $valuedOn,
            );
        }, $lots);
    }

    /** @return array{0: int|null, 1: ValuationBasis|null, 2: string|null} the value, what it rests on, and the trading day of its price */
    private function value(InvestmentLot $lot, InstrumentTerms $instrument, ?LatestPrice $price): array
    {
        $face = $instrument->faceValue;
        assert($face === null || is_numeric($face));
        $quoted = $price?->price;
        assert($quoted === null || is_numeric($quoted));
        $tradedOn = $price?->tradingDate->format('Y-m-d');

        if ($instrument->kind === InstrumentKind::SHARE) {
            return $quoted === null ? [null, null, null] : [LotAmounts::minor($lot->units, $quoted), ValuationBasis::MARKET_PRICE, $tradedOn];
        }

        if ($face === null) {
            return [null, null, null];
        }

        if ($quoted !== null && $instrument->programme !== Programme::TEZAUR) {
            return [LotAmounts::minor($lot->units, bcdiv(bcmul($face, $quoted, 15), '100', 15)), ValuationBasis::MARKET_PRICE, $tradedOn];
        }

        // Unpriced: the state's bonds are worth their face value; a company's or a city's is not known.
        return $instrument->programme === null ? [null, null, null] : [LotAmounts::minor($lot->units, $face), ValuationBasis::FACE_VALUE, null];
    }
}
