<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Queries;

use Kern\Investments\Contracts\MarketData;
use Kern\Platform\Tenancy\TenantScope;
use Kern\TaxRomania\Wallet\Models\InvestmentLot;

/**
 * How many holdings, every taxpayer's, are of a bond read from this law — what keeps an order from being
 * deleted with its bonds. Across taxpayers on purpose: it is asked by whoever deletes the law, for
 * everyone, and answers a count, never whose.
 */
final readonly class HoldingsOfALaw
{
    public function __construct(private MarketData $market)
    {
    }

    public function __invoke(string $documentId): int
    {
        return InvestmentLot::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereIn('instrument_id', $this->market->readFrom($documentId))
            ->count();
    }
}
