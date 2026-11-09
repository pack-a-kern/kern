<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\DataTransferObjects;

use Kern\Investments\Enums\InstrumentKind;
use Kern\Investments\Enums\Programme;
use Kern\TaxRomania\Wallet\Enums\HeldAt;
use Kern\TaxRomania\Wallet\Enums\ValuationBasis;

/**
 * One lot as the holdings screen shows it: the instrument's terms beside what the person entered, and
 * what follows from both — its cost, and either its value (open) or what the sale brought and its result
 * (sold). Every amount is in minor units of the lot's currency; dates are `Y-m-d`.
 *
 * The value is informative: an unrealised figure, never income. Only a sale realises a result.
 */
final readonly class HoldingLine
{
    public function __construct(
        public string $id,
        public string $accountId,
        public string $instrumentId,
        public string $instrumentName,
        public InstrumentKind $instrumentKind,
        public ?Programme $programme,
        public ?string $isin,
        public ?string $symbol,
        public ?string $issueCode,
        public ?string $faceValue,
        public ?string $ratePercent,
        public ?string $maturityDate,
        public string $currency,
        public HeldAt $heldAt,
        public string $units,
        public string $pricePerUnit,
        public string $boughtOn,
        public int $feesMinor,
        public int $costMinor,
        public ?string $soldOn,
        public ?string $soldPricePerUnit,
        public ?int $saleFeesMinor,
        /** What the intermediary withheld on this lot's part of the sale's gain; none on a loss or public debt. */
        public ?int $saleTaxWithheldMinor,
        public ?int $proceedsMinor,
        public ?int $resultMinor,
        public ?int $valueMinor,
        public ?ValuationBasis $valuationBasis,
        public ?string $valuedOn,
    ) {
    }
}
