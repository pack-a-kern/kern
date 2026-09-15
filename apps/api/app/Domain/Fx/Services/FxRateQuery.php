<?php

declare(strict_types=1);

namespace App\Domain\Fx\Services;

use App\Domain\Fx\DataTransferObjects\FxRateWithProvenance;
use App\Domain\Fx\Exceptions\FxRateNotFoundException;
use App\Domain\Fx\Models\FxRate;
use App\Domain\Fx\ValueObjects\DecimalRate;
use App\Domain\TaxKnowledge\Repositories\OfficialSourceRepository;
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
        private OfficialSourceRepository $officialSources,
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

    /**
     * The most recent published rate **on or before** `$date`, or null if there is none.
     *
     * ## This is for display, and the naming says so
     *
     * {@see self::rate()} and {@see self::rateWithProvenance()} refuse to approximate: an exact miss
     * throws, because a fiscal figure has to be reproducible against the rate that actually applied
     * on its own date (`BLUEPRINT.md` §26). That strictness is not weakened here — this is a
     * *different question*, asked by a different kind of caller.
     *
     * A dashboard converting today's balances is not computing a tax figure. It needs the latest
     * rate the BNR has published, which on a Sunday is Friday's, and during a gap in the imported
     * history is whatever came before it. Answering that with an exception would leave the screen
     * blank; answering it silently from `self::rate()` would make every fiscal lookup approximate.
     * Hence a separate method, with "on or before" in its name so a caller cannot reach for it by
     * accident.
     *
     * Returns null rather than throwing: "no rate has ever been published for this currency" is an
     * ordinary answer for a screen, which shows the amount unconverted instead.
     */
    public function latestRateOnOrBefore(string $currencyCode, DateTimeImmutable $date, string $sourceCode = 'BNR'): ?FxRateWithProvenance
    {
        $sourceId = $this->officialSources->findIdForCode($sourceCode);

        if ($sourceId === null) {
            return null;
        }

        /** @var FxRate|null $rate */
        $rate = FxRate::query()
            ->where('official_source_id', $sourceId)
            ->where('currency_code', strtoupper($currencyCode))
            ->whereDate('rate_date', '<=', $date->format('Y-m-d'))
            ->orderByDesc('rate_date')
            ->first();

        return $rate === null ? null : $this->describe($rate, $sourceCode);
    }

    private function find(string $currencyCode, DateTimeImmutable $date, string $sourceCode): FxRateWithProvenance
    {
        $dateString = $date->format('Y-m-d');

        $sourceId = $this->officialSources->findIdForCode($sourceCode);

        /** @var FxRate|null $rate */
        $rate = $sourceId === null ? null : FxRate::query()
            ->where('official_source_id', $sourceId)
            ->where('rate_date', $dateString)
            ->where('currency_code', strtoupper($currencyCode))
            ->first();

        if ($sourceId === null || $rate === null) {
            throw FxRateNotFoundException::forQuery($sourceCode, $currencyCode, $dateString);
        }

        return $this->describe($rate, $sourceCode);
    }

    /**
     * One `ext_fx_rates` row as the value object callers receive, provenance and all.
     *
     * Shared by the strict lookup and the display-only one so the two can never disagree about how
     * a published rate becomes a per-unit rate — the division by `multiplier` in particular, which
     * is what makes a rate quoted per 100 HUF comparable with one quoted per 1 EUR.
     */
    private function describe(FxRate $rate, string $sourceCode): FxRateWithProvenance
    {
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
