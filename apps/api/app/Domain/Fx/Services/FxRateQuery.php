<?php

declare(strict_types=1);

namespace App\Domain\Fx\Services;

use App\Domain\Fx\DataTransferObjects\FxRateWithProvenance;
use App\Domain\Fx\Exceptions\FxRateNotFoundException;
use App\Domain\Fx\Models\FxRate;
use App\Domain\Fx\Models\FxSource;
use App\Domain\Fx\ValueObjects\DecimalRate;
use App\Domain\TaxKnowledge\Repositories\SourceDocumentVersionRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The sanctioned way any other module (a future `TaxEngine`, `Wallet`, ...) asks "what was the
 * RON rate for this currency on this date" — `BLUEPRINT.md` §31's example, §26 DoD "query rate(EUR,
 * date) reproducibil". No interpolation, no nearest-day fallback: an exact miss is
 * {@see FxRateNotFoundException}, never a guess (§7.5 reproducibility).
 *
 * Defaults `$sourceCode` to `BNR` because `BLUEPRINT.md` §7.1 names it the sole canonical FX source
 * for this product; the parameter exists so a caller can be explicit rather than because a second
 * source is implemented.
 */
final readonly class FxRateQuery
{
    /** Division precision for the per-unit rate; not further rounded — the caller decides that. */
    private const int DIVISION_SCALE = 10;

    public function __construct(
        private SourceDocumentVersionRepository $sourceVersions,
    ) {
    }

    public function rate(string $currencyCode, DateTimeImmutable $date, string $sourceCode = 'BNR'): DecimalRate
    {
        return $this->find($currencyCode, $date, $sourceCode)->perUnitRate;
    }

    public function rateWithProvenance(string $currencyCode, DateTimeImmutable $date, string $sourceCode = 'BNR'): FxRateWithProvenance
    {
        return $this->find($currencyCode, $date, $sourceCode);
    }

    private function find(string $currencyCode, DateTimeImmutable $date, string $sourceCode): FxRateWithProvenance
    {
        $dateString = $date->format('Y-m-d');

        /** @var FxSource|null $source */
        $source = FxSource::query()->where('code', $sourceCode)->first();

        /** @var FxRate|null $rate */
        $rate = $source === null ? null : FxRate::query()
            ->where('fx_source_id', $source->id)
            ->where('rate_date', $dateString)
            ->where('currency_code', strtoupper($currencyCode))
            ->first();

        if ($source === null || $rate === null) {
            throw FxRateNotFoundException::forQuery($sourceCode, $currencyCode, $dateString);
        }

        $published = DecimalRate::fromString((string) $rate->rate_decimal);
        $perUnit = $published->divideByInt($rate->multiplier, self::DIVISION_SCALE);

        $versionDetails = $this->sourceVersions->detailsFor($rate->source_document_version_id);

        return new FxRateWithProvenance(
            perUnitRate: $perUnit,
            currencyCode: $rate->currency_code,
            rateDate: $rate->rate_date->setTimezone(new DateTimeZone('UTC'))->toDateTimeImmutable(),
            fxSourceCode: $sourceCode,
            multiplier: $rate->multiplier,
            publishedRateDecimal: $published,
            sourceDocumentVersionId: $versionDetails->id,
            contentSha256: $versionDetails->contentSha256,
            rawObjectPath: $versionDetails->rawObjectPath,
            retrievedAt: $versionDetails->retrievedAt,
        );
    }
}
