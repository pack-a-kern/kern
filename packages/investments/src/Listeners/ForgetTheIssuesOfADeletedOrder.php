<?php

declare(strict_types=1);

namespace Kern\Investments\Listeners;

use Kern\Investments\Contracts\MarketData;
use Kern\Investments\Events\InstrumentsWithdrawn;
use Kern\Investments\Models\FinancialInstrument;
use Kern\Investments\Models\InstrumentPayout;
use Kern\Investments\Models\InstrumentPrice;
use Kern\Legislation\Events\LawDeleting;

/**
 * An order deleted as a law takes the bonds read from it, with their payouts and prices, so nothing is
 * left pointing at a law that is gone. Reached only when nobody holds one: `kern/tax-romania` declares a
 * person's holding as what keeps the law. The order's own record goes by its foreign key's cascade.
 */
final readonly class ForgetTheIssuesOfADeletedOrder
{
    public function __construct(private MarketData $market)
    {
    }

    public function handle(LawDeleting $event): void
    {
        $ids = $this->market->readFrom($event->sourceDocumentId);

        if ($ids === []) {
            return;
        }

        InstrumentsWithdrawn::dispatch($ids);
        InstrumentPayout::query()->whereIn('instrument_id', $ids)->delete();
        InstrumentPrice::query()->whereIn('instrument_id', $ids)->delete();
        FinancialInstrument::query()->whereKey($ids)->delete();
    }
}
