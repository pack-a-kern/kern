<?php

declare(strict_types=1);

namespace App\Domain\Fx\DataTransferObjects;

use App\Domain\Fx\ValueObjects\DecimalRate;
use DateTimeImmutable;

/**
 * The result of {@see \App\Domain\Fx\Services\FxRateQuery::rateWithProvenance()} — `BLUEPRINT.md`
 * §26 DoD F / §39: a rate is never just a number, it comes with the raw document hash, path, and
 * fetch time that produced it.
 */
final readonly class FxRateWithProvenance
{
    public function __construct(
        public DecimalRate $perUnitRate,
        public string $currencyCode,
        public DateTimeImmutable $rateDate,
        public string $fxSourceCode,
        public int $multiplier,
        public DecimalRate $publishedRateDecimal,
        public string $sourceDocumentVersionId,
        public string $contentSha256,
        public string $rawObjectPath,
        public DateTimeImmutable $retrievedAt,
    ) {
    }
}
