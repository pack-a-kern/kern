<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Queries;

use Kern\TaxRomania\Wallet\Models\InvestmentLot;

/**
 * The live value of the active taxpayer's open holdings, summed per currency — handed to
 * {@see WalletQuery::totals()} so bonds and shares count towards net worth.
 *
 * A holding is money that moved into an asset and is still the taxpayer's, like a deposit's principal.
 * Its value is {@see Holdings::value()} recomputed on read, never stored — so it follows the market
 * without anything going stale. A lot that is sold, or a bond past maturity (its money already back in
 * the account), is worth nothing here and drops out. Open lots only, and the market is asked once for
 * all of them, exactly as the holdings screen does. Tenant-scoped through {@see InvestmentLot}.
 */
final readonly class HoldingsValue
{
    public function __construct(private Holdings $holdings)
    {
    }

    /** @return array<string, int> value in minor units, per currency */
    public function byCurrency(): array
    {
        /** @var list<InvestmentLot> $lots */
        $lots = InvestmentLot::query()->whereNull('sold_on')->get()->all();

        if ($lots === []) {
            return [];
        }

        $value = [];

        foreach ($this->holdings->lines($lots) as $line) {
            if ($line->valueMinor !== null) {
                $value[$line->currency] = ($value[$line->currency] ?? 0) + $line->valueMinor;
            }
        }

        return $value;
    }
}
