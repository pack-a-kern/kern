<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\DataTransferObjects;

use DateTimeImmutable;
use Kern\TaxRomania\Fx\ValueObjects\DecimalRate;
use Kern\TaxRomania\TaxEngine\Enums\FxConversionMethod;
use Kern\TaxRomania\TaxEngine\ValueObjects\Money;

/**
 * The full record `BLUEPRINT.md` §7.5 requires for every fiscal FX conversion: original amount,
 * currency, the rate actually applied, the date it was resolved for, the source, the method the
 * calling rule chose, and the resulting RON amount — plus every underlying published rate that
 * produced `$rateApplied` (one for `SPOT`/`PREVIOUS_BANKING_DAY`, several for the average
 * methods). Persisted verbatim as `tax_facts.fx_conversion_json`.
 */
final readonly class FxConversionResult
{
    /**
     * @param list<FxRateProvenanceEntry> $provenance
     */
    public function __construct(
        public Money $originalAmount,
        public Money $convertedAmount,
        public FxConversionMethod $method,
        public DecimalRate $rateApplied,
        public DateTimeImmutable $resolvedFor,
        public string $sourceCode,
        public array $provenance,
    ) {
    }

    /**
     * @return array{
     *     original_amount: string,
     *     converted_amount: string,
     *     method: string,
     *     rate_applied: string,
     *     resolved_for: string,
     *     source_code: string,
     *     provenance: list<array{rate_date: string, per_unit_rate: string, source_document_version_id: string, content_sha256: string, raw_object_path: string, retrieved_at: string}>,
     * }
     */
    public function toArray(): array
    {
        return [
            'original_amount' => $this->originalAmount->toString(),
            'converted_amount' => $this->convertedAmount->toString(),
            'method' => $this->method->value,
            'rate_applied' => $this->rateApplied->toString(),
            'resolved_for' => $this->resolvedFor->format('Y-m-d'),
            'source_code' => $this->sourceCode,
            'provenance' => array_map(
                static fn (FxRateProvenanceEntry $entry): array => $entry->toArray(),
                $this->provenance,
            ),
        ];
    }
}
