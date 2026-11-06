<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Fx\Services;

use DateTimeImmutable;
use DateTimeZone;
use Kern\Legislation\DataTransferObjects\SourceDocumentVersionDetails;
use Kern\Legislation\Exceptions\UnknownSourceDocumentVersionException;
use Kern\Legislation\Repositories\OfficialSourceRepository;
use Kern\Legislation\Repositories\SourceDocumentVersionRepository;
use Kern\TaxRomania\Fx\DataTransferObjects\FxRateWithProvenance;
use Kern\TaxRomania\Fx\DataTransferObjects\PublishedFxRate;
use Kern\TaxRomania\Fx\Exceptions\FxRateNotFoundException;
use Kern\TaxRomania\Fx\Models\FxRate;
use Kern\TaxRomania\Fx\ValueObjects\DecimalRate;

/**
 * The sanctioned way any other module (a future `TaxEngine`, `Wallet`, ...) asks "what was the
 * RON rate for this currency on this date" — `BLUEPRINT.md` §31's example, §26 DoD "query rate(EUR,
 * date) reproducibil". No interpolation, no nearest-day fallback: an exact miss is
 * {@see FxRateNotFoundException}, never a guess (§7.5 reproducibility).
 *
 * Defaults `$sourceCode` to `BNR` because `BLUEPRINT.md` §7.1 names it the sole canonical FX source
 * for this product; the parameter exists so a caller can be explicit rather than because a second
 * source is implemented.
 *
 * The source's id is looked up once per instance — it is a row that never changes, and a screen
 * converting a dozen totals used to ask for it a dozen times. Not bound as a singleton, so an
 * instance lives as long as the request or job that resolved it.
 */
final class FxRateQuery
{
    /** Division precision for the per-unit rate; not further rounded — the caller decides that. */
    private const int DIVISION_SCALE = 10;

    /** @var array<string, string|null> by source code */
    private array $sourceIds = [];

    public function __construct(
        private readonly SourceDocumentVersionRepository $sourceVersions,
        private readonly OfficialSourceRepository $officialSources,
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
     *
     * Without provenance ({@see PublishedFxRate}): a screen shows the rate, its date and its source,
     * and the document details cost three queries a currency.
     */
    public function latestRateOnOrBefore(string $currencyCode, DateTimeImmutable $date, string $sourceCode = 'BNR'): ?PublishedFxRate
    {
        $sourceId = $this->sourceId($sourceCode);

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

        return $rate === null ? null : $this->published($rate, $sourceCode);
    }

    /**
     * Every published rate from `$from` to `$to`, both included, oldest first, provenance and all.
     *
     * What {@see self::rateWithProvenance()} answers for each day of the range, without asking it
     * once per day: the days with no rate (weekends, holidays) are simply absent, exactly as each of
     * those lookups would have thrown. One query for the rates and three for their documents, for a
     * month or for a year — an annual average used to be 365 lookups of five queries each.
     *
     * @return list<FxRateWithProvenance>
     */
    public function ratesWithProvenanceBetween(string $currencyCode, DateTimeImmutable $from, DateTimeImmutable $to, string $sourceCode = 'BNR'): array
    {
        $sourceId = $this->sourceId($sourceCode);

        if ($sourceId === null) {
            return [];
        }

        /** @var list<FxRate> $rates */
        $rates = FxRate::query()
            ->where('official_source_id', $sourceId)
            ->where('currency_code', strtoupper($currencyCode))
            ->whereDate('rate_date', '>=', $from->format('Y-m-d'))
            ->whereDate('rate_date', '<=', $to->format('Y-m-d'))
            ->orderBy('rate_date')
            ->get()
            ->all();

        $versions = $this->sourceVersions->detailsForMany(array_map(static fn (FxRate $rate): string => $rate->source_document_version_id, $rates));

        return array_map(fn (FxRate $rate): FxRateWithProvenance => $this->withProvenance(
            $rate,
            $sourceCode,
            $versions[$rate->source_document_version_id] ?? throw UnknownSourceDocumentVersionException::forId($rate->source_document_version_id),
        ), $rates);
    }

    private function find(string $currencyCode, DateTimeImmutable $date, string $sourceCode): FxRateWithProvenance
    {
        $dateString = $date->format('Y-m-d');

        $sourceId = $this->sourceId($sourceCode);

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

    private function sourceId(string $sourceCode): ?string
    {
        if (! array_key_exists($sourceCode, $this->sourceIds)) {
            $this->sourceIds[$sourceCode] = $this->officialSources->findIdForCode($sourceCode);
        }

        return $this->sourceIds[$sourceCode];
    }

    /**
     * One `ext_fx_rates` row as the rate a screen shows.
     *
     * Shared by both lookups so the two can never disagree about how a published rate becomes a
     * per-unit rate — the division by `multiplier` in particular, which is what makes a rate quoted
     * per 100 HUF comparable with one quoted per 1 EUR.
     */
    private function published(FxRate $rate, string $sourceCode): PublishedFxRate
    {
        return new PublishedFxRate(
            perUnitRate: DecimalRate::fromString((string) $rate->rate_decimal)->divideByInt($rate->multiplier, self::DIVISION_SCALE),
            currencyCode: $rate->currency_code,
            rateDate: $rate->rate_date->setTimezone(new DateTimeZone('UTC'))->toDateTimeImmutable(),
            fxSourceCode: $sourceCode,
        );
    }

    /** One `ext_fx_rates` row with the document it was read from — what a fiscal figure records. */
    private function describe(FxRate $rate, string $sourceCode): FxRateWithProvenance
    {
        return $this->withProvenance($rate, $sourceCode, $this->sourceVersions->detailsFor($rate->source_document_version_id));
    }

    private function withProvenance(FxRate $rate, string $sourceCode, SourceDocumentVersionDetails $versionDetails): FxRateWithProvenance
    {
        $rateOnly = $this->published($rate, $sourceCode);

        return new FxRateWithProvenance(
            perUnitRate: $rateOnly->perUnitRate,
            currencyCode: $rateOnly->currencyCode,
            rateDate: $rateOnly->rateDate,
            fxSourceCode: $sourceCode,
            multiplier: $rate->multiplier,
            publishedRateDecimal: DecimalRate::fromString((string) $rate->rate_decimal),
            sourceDocumentVersionId: $versionDetails->id,
            contentSha256: $versionDetails->contentSha256,
            rawObjectPath: $versionDetails->rawObjectPath,
            retrievedAt: $versionDetails->retrievedAt,
        );
    }
}
