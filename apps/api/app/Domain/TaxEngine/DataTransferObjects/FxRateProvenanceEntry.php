<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine\DataTransferObjects;

use App\Domain\Fx\ValueObjects\DecimalRate;
use DateTimeImmutable;

/**
 * One published daily rate that contributed to a {@see FxConversionResult} — for `SPOT`/
 * `PREVIOUS_BANKING_DAY` there is exactly one; for `MONTHLY_AVERAGE`/`ANNUAL_AVERAGE` there are
 * several, each carried through in full so the average itself is reproducible from the same raw
 * documents years later (`BLUEPRINT.md` §7.5).
 */
final readonly class FxRateProvenanceEntry
{
    public function __construct(
        public DateTimeImmutable $rateDate,
        public DecimalRate $perUnitRate,
        public string $sourceDocumentVersionId,
        public string $contentSha256,
        public string $rawObjectPath,
        public DateTimeImmutable $retrievedAt,
    ) {
    }

    /**
     * @return array{rate_date: string, per_unit_rate: string, source_document_version_id: string, content_sha256: string, raw_object_path: string, retrieved_at: string}
     */
    public function toArray(): array
    {
        return [
            'rate_date' => $this->rateDate->format('Y-m-d'),
            'per_unit_rate' => $this->perUnitRate->toString(),
            'source_document_version_id' => $this->sourceDocumentVersionId,
            'content_sha256' => $this->contentSha256,
            'raw_object_path' => $this->rawObjectPath,
            'retrieved_at' => $this->retrievedAt->format(DateTimeImmutable::ATOM),
        ];
    }
}
