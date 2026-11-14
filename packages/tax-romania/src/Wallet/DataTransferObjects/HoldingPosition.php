<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\DataTransferObjects;

use Kern\Investments\Enums\InstrumentKind;
use Kern\Investments\Enums\Programme;
use Kern\TaxRomania\Wallet\Enums\HeldAt;
use Kern\TaxRomania\Wallet\Enums\ValuationBasis;

/**
 * One instrument held in one account, as the holdings screen shows it: what its purchases add up to, and
 * the purchases themselves (`lots`), each a row of its own because the law counts each (ADR 0025 —
 * the weighted average price per symbol for the gain, first in, first out for the holding period).
 *
 * Open: the units still held, their cost, the weighted average price paid (fees in — the law's tax value),
 * and what they are worth now and the unrealised result, when every purchase can be valued. Sold: what the
 * sales brought and the result they realised. Amounts in minor units of the currency; a percent as text
 * with two decimals; dates `Y-m-d`.
 */
final readonly class HoldingPosition
{
    /** @param list<HoldingLine> $lots oldest purchase first */
    public function __construct(
        public string $accountId,
        public string $instrumentId,
        public string $instrumentName,
        public InstrumentKind $instrumentKind,
        public ?Programme $programme,
        public ?string $isin,
        public ?string $symbol,
        public ?string $issueCode,
        public ?string $maturityDate,
        public string $currency,
        public HeldAt $heldAt,
        public string $units,
        public int $costMinor,
        public string $averagePrice,
        public string $firstBoughtOn,
        public ?string $lastSoldOn,
        public ?int $valueMinor,
        public ?ValuationBasis $valuationBasis,
        public ?string $valuedOn,
        public ?int $proceedsMinor,
        public ?int $resultMinor,
        public ?string $resultPercent,
        /** What its coupons and dividends paid into the account, net of what was withheld. */
        public int $receivedMinor,
        public array $lots,
    ) {
    }
}
