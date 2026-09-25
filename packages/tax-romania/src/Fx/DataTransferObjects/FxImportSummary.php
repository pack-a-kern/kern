<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Fx\DataTransferObjects;

use Kern\TaxRomania\Fx\Enums\FxRateImportOutcome;

/**
 * Outcome counts for one {@see \Kern\TaxRomania\Fx\Actions\ImportBnrDailyRates}/
 * {@see \Kern\TaxRomania\Fx\Actions\BackfillBnrYear} run, across every day and currency parsed.
 */
final readonly class FxImportSummary
{
    public function __construct(
        public int $daysProcessed,
        public int $created,
        public int $unchanged,
        public int $anomalies,
        public bool $wasNewDocumentVersion,
    ) {
    }

    public static function empty(bool $wasNewDocumentVersion): self
    {
        return new self(0, 0, 0, 0, $wasNewDocumentVersion);
    }

    public function withRate(FxRateImportOutcome $outcome): self
    {
        return new self(
            $this->daysProcessed,
            $this->created + ($outcome === FxRateImportOutcome::CREATED ? 1 : 0),
            $this->unchanged + ($outcome === FxRateImportOutcome::UNCHANGED ? 1 : 0),
            $this->anomalies + ($outcome === FxRateImportOutcome::ANOMALY ? 1 : 0),
            $this->wasNewDocumentVersion,
        );
    }

    public function withDayProcessed(): self
    {
        return new self($this->daysProcessed + 1, $this->created, $this->unchanged, $this->anomalies, $this->wasNewDocumentVersion);
    }
}
