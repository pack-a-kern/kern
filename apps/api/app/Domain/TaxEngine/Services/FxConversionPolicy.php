<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine\Services;

use App\Domain\Fx\DataTransferObjects\FxRateWithProvenance;
use App\Domain\Fx\Exceptions\FxRateNotFoundException;
use App\Domain\Fx\Services\FxRateQuery;
use App\Domain\Fx\ValueObjects\DecimalRate;
use App\Domain\TaxEngine\DataTransferObjects\FxConversionResult;
use App\Domain\TaxEngine\DataTransferObjects\FxRateProvenanceEntry;
use App\Domain\TaxEngine\Enums\FxConversionMethod;
use App\Domain\TaxEngine\Enums\RoundingMode;
use App\Domain\TaxEngine\Exceptions\FxRateUnavailableWithinLookbackException;
use App\Domain\TaxEngine\Exceptions\IncompleteFiscalYearAveragesException;
use App\Domain\TaxEngine\Exceptions\NoDailyRatesForPeriodException;
use App\Domain\TaxEngine\ValueObjects\Money;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use RuntimeException;

/**
 * `BLUEPRINT.md` §6.9/§7.5: converts a foreign-currency `Money` amount to RON, retaining amount,
 * currency, rate, date, source, method and result for every conversion — and taking the method as
 * a mandatory, explicit parameter chosen by the calling rule, never a default this policy picks
 * itself (`BLUEPRINT.md` §6.9's closing note).
 *
 * Built entirely on `App\Domain\Fx\Services\FxRateQuery` (the sanctioned application-service
 * crossing into `App\Domain\Fx`, mirroring `App\Domain\Fx`'s own crossing into
 * `App\Domain\TaxKnowledge` — never an `Fx` Eloquent model). `MONTHLY_AVERAGE`/`ANNUAL_AVERAGE`
 * are computed here from repeated spot lookups rather than by calling
 * `App\Domain\Fx\Services\FxPeriodAverageCalculator`, because that calculator's public methods
 * take `App\Domain\Fx\Models\FxPeriodAverage` Eloquent models as parameters/return
 * values — there is no DTO-based read surface for a pre-calculated average, and extending `Fx` to
 * add one is outside this task's path scope. The arithmetic (`BLUEPRINT.md` §7.4: monthly = mean of
 * that month's daily rates; annual = mean of the year's 12 monthly averages, never of daily rates
 * directly) is the same published methodology, independently applied to the one primitive `Fx`
 * does expose (`FxRateQuery::rateWithProvenance()`) — see `docs/handoff/020-tax-engine.md` "Open
 * questions" for the reconciliation gap this leaves against `Fx`'s own `ext_fx_period_averages` cache.
 */
final readonly class FxConversionPolicy
{
    /**
     * A technical search bound for `PREVIOUS_BANKING_DAY` (covers even an unusually long run of
     * consecutive non-banking days), never a fiscal fact.
     */
    private const int MAX_PREVIOUS_BANKING_DAY_LOOKBACK_DAYS = 10;

    /** Working precision for average sums, ahead of the final round — mirrors
     *  `App\Domain\Fx\Services\FxPeriodAverageCalculator::INTERNAL_SCALE`. */
    private const int INTERNAL_SCALE = 12;

    /** Decimal places the final averaged per-unit rate is rounded to before being applied. */
    private const int AVERAGE_RATE_SCALE = 6;

    public function __construct(
        private FxRateQuery $fxRateQuery,
    ) {
    }

    public function convertToRon(
        Money $amount,
        FxConversionMethod $method,
        DateTimeImmutable $asOf,
        RoundingMode $roundingMode,
        string $sourceCode = 'BNR',
    ): FxConversionResult {
        [$rate, $resolvedFor, $provenance] = match ($method) {
            FxConversionMethod::SPOT => $this->spot($amount->currencyCode, $asOf, $sourceCode),
            FxConversionMethod::PREVIOUS_BANKING_DAY => $this->previousBankingDay($amount->currencyCode, $asOf, $sourceCode),
            FxConversionMethod::MONTHLY_AVERAGE => $this->monthlyAverage($amount->currencyCode, (int) $asOf->format('Y'), (int) $asOf->format('n'), $sourceCode),
            FxConversionMethod::ANNUAL_AVERAGE => $this->annualAverage($amount->currencyCode, (int) $asOf->format('Y'), $sourceCode),
        };

        $converted = $amount->convertedTo('RON', 2, $rate, $roundingMode);

        return new FxConversionResult(
            originalAmount: $amount,
            convertedAmount: $converted,
            method: $method,
            rateApplied: $rate,
            resolvedFor: $resolvedFor,
            sourceCode: $sourceCode,
            provenance: $provenance,
        );
    }

    /**
     * @return array{0: DecimalRate, 1: DateTimeImmutable, 2: list<FxRateProvenanceEntry>}
     */
    private function spot(string $currencyCode, DateTimeImmutable $date, string $sourceCode): array
    {
        $rate = $this->fxRateQuery->rateWithProvenance($currencyCode, $date, $sourceCode);

        return [$rate->perUnitRate, $date, [$this->toProvenanceEntry($rate)]];
    }

    /**
     * @return array{0: DecimalRate, 1: DateTimeImmutable, 2: list<FxRateProvenanceEntry>}
     */
    private function previousBankingDay(string $currencyCode, DateTimeImmutable $asOf, string $sourceCode): array
    {
        $carbonAsOf = CarbonImmutable::instance($asOf);

        for ($daysBack = 0; $daysBack <= self::MAX_PREVIOUS_BANKING_DAY_LOOKBACK_DAYS; $daysBack++) {
            $candidateDate = $carbonAsOf->subDays($daysBack)->toDateTimeImmutable();

            try {
                return $this->spot($currencyCode, $candidateDate, $sourceCode);
            } catch (FxRateNotFoundException) {
                continue;
            }
        }

        throw FxRateUnavailableWithinLookbackException::forSearch($currencyCode, $asOf, self::MAX_PREVIOUS_BANKING_DAY_LOOKBACK_DAYS);
    }

    /**
     * @return array{0: DecimalRate, 1: DateTimeImmutable, 2: list<FxRateProvenanceEntry>}
     */
    private function monthlyAverage(string $currencyCode, int $year, int $month, string $sourceCode): array
    {
        $periodStart = self::carbonDate($year, $month, 1);
        $periodEnd = $periodStart->endOfMonth();

        $sum = DecimalRate::zero();
        $count = 0;
        $provenance = [];

        for ($day = $periodStart; $day->lte($periodEnd); $day = $day->addDay()) {
            try {
                $rate = $this->fxRateQuery->rateWithProvenance($currencyCode, $day->toDateTimeImmutable(), $sourceCode);
            } catch (FxRateNotFoundException) {
                continue;
            }

            $sum = $sum->add($rate->perUnitRate, self::INTERNAL_SCALE);
            $provenance[] = $this->toProvenanceEntry($rate);
            $count++;
        }

        if ($count === 0) {
            throw NoDailyRatesForPeriodException::forMonth($currencyCode, $year, $month);
        }

        $average = $sum->divideByInt($count, self::INTERNAL_SCALE)->roundedTo(self::AVERAGE_RATE_SCALE);

        return [$average, $periodStart->toDateTimeImmutable(), $provenance];
    }

    /**
     * @return array{0: DecimalRate, 1: DateTimeImmutable, 2: list<FxRateProvenanceEntry>}
     */
    private function annualAverage(string $currencyCode, int $year, string $sourceCode): array
    {
        $sum = DecimalRate::zero();
        $monthsAvailable = 0;
        $provenance = [];

        for ($month = 1; $month <= 12; $month++) {
            try {
                [$monthlyAverage, , $monthlyProvenance] = $this->monthlyAverage($currencyCode, $year, $month, $sourceCode);
            } catch (NoDailyRatesForPeriodException) {
                continue;
            }

            $sum = $sum->add($monthlyAverage, self::INTERNAL_SCALE);
            $provenance = [...$provenance, ...$monthlyProvenance];
            $monthsAvailable++;
        }

        if ($monthsAvailable !== 12) {
            throw IncompleteFiscalYearAveragesException::forYear($currencyCode, $year, $monthsAvailable);
        }

        $average = $sum->divideByInt(12, self::INTERNAL_SCALE)->roundedTo(self::AVERAGE_RATE_SCALE);

        return [$average, self::carbonDate($year, 1, 1)->toDateTimeImmutable(), $provenance];
    }

    /**
     * `CarbonImmutable::create()` is typed nullable in its stub (it can fail for an invalid
     * calendar date); every caller here always passes a valid year/month/day it derived itself
     * from a real date or a bounded loop counter, so a `null` result would be a genuine defect to
     * surface immediately, never a case to silently paper over.
     */
    private static function carbonDate(int $year, int $month, int $day): CarbonImmutable
    {
        $date = CarbonImmutable::create($year, $month, $day);

        if ($date === null) {
            throw new RuntimeException(sprintf('Invalid calendar date %04d-%02d-%02d.', $year, $month, $day));
        }

        return $date;
    }

    private function toProvenanceEntry(FxRateWithProvenance $rate): FxRateProvenanceEntry
    {
        return new FxRateProvenanceEntry(
            rateDate: $rate->rateDate,
            perUnitRate: $rate->perUnitRate,
            sourceDocumentVersionId: $rate->sourceDocumentVersionId,
            contentSha256: $rate->contentSha256,
            rawObjectPath: $rate->rawObjectPath,
            retrievedAt: $rate->retrievedAt,
        );
    }
}
