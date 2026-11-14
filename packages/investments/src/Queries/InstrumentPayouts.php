<?php

declare(strict_types=1);

namespace Kern\Investments\Queries;

use Illuminate\Database\Eloquent\Builder;
use Kern\Investments\Enums\InstrumentKind;
use Kern\Investments\Enums\PayoutKind;
use Kern\Investments\Models\FinancialInstrument;
use Kern\Investments\Models\InstrumentPayout;

/**
 * The payments an instrument's screen shows, in date order: a share's every one; a bond's coupons — its
 * repayment is its face value on its maturity, both already among its terms.
 */
final class InstrumentPayouts
{
    /** @return Builder<InstrumentPayout> */
    public static function shown(FinancialInstrument $instrument): Builder
    {
        return InstrumentPayout::query()
            ->where('instrument_id', $instrument->id)
            ->when($instrument->kind !== InstrumentKind::SHARE, static fn (Builder $query): Builder => $query->where('kind', PayoutKind::COUPON))
            ->orderBy('payment_date')
            ->orderBy('id');
    }
}
