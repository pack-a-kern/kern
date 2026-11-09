<?php

declare(strict_types=1);

namespace Kern\Investments\DataTransferObjects;

use DateTimeImmutable;
use Kern\Investments\Enums\InstrumentKind;
use Kern\Investments\Enums\InstrumentStatus;
use Kern\Investments\Enums\Programme;

/** A security's terms, as its source states them. Decimals are plain decimal strings. */
final readonly class InstrumentTerms
{
    public function __construct(
        public string $id,
        public InstrumentKind $kind,
        public ?Programme $programme,
        public InstrumentStatus $status,
        public string $name,
        public ?string $isin,
        public ?string $symbol,
        public ?string $issueCode,
        public string $currency,
        public ?string $faceValue,
        public ?string $ratePercent,
        public ?DateTimeImmutable $issueDate,
        public ?DateTimeImmutable $maturityDate,
    ) {
    }
}
