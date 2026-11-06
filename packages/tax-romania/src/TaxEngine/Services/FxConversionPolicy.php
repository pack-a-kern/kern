<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Services;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Kern\Platform\Money\CurrencyCode;
use Kern\TaxRomania\Fx\DataTransferObjects\FxRateWithProvenance;
use Kern\TaxRomania\Fx\Services\FxRateQuery;
use Kern\TaxRomania\Fx\ValueObjects\DecimalRate;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\FxConversionResult;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\FxRateProvenanceEntry;
use Kern\TaxRomania\TaxEngine\Enums\FxConversionMethod;
use Kern\TaxRomania\TaxEngine\Enums\RoundingMode;
use Kern\TaxRomania\TaxEngine\Exceptions\FxRateUnavailableWithinLookbackException;
use Kern\TaxRomania\TaxEngine\Exceptions\IncompleteFiscalYearAveragesException;
use Kern\TaxRomania\TaxEngine\Exceptions\NoDailyRatesForPeriodException;
use Kern\TaxRomania\TaxEngine\ValueObjects\Money;
use RuntimeException;

/**
 * `BLUEPRINT.md` §6.9/§7.5: converts a foreign-currency `Money` amount to RON, retaining amount,
 * currency, rate, date, source, method and result for every conversion — and taking the method as
 * a mandatory, explicit parameter chosen by the calling rule, never a default this policy picks
 * itself (`BLUEPRINT.md` §6.9's closing note).
 *
 * Built entirely on `Kern\TaxRomania\Fx\Services\FxRateQuery` (the sanctioned application-service
 * crossing into `Kern\TaxRomania\Fx`, mirroring `Kern\TaxRomania\Fx`'s own crossing into
 * `Kern\Legislation` — never an `Fx` Eloquent model). `MONTHLY_AVERAGE`/`ANNUAL_AVERAGE`
 * are computed here from the period's daily rates, read in one range query with their provenance
 * (`FxRateQuery::ratesWithProvenanceBetween()`), rather than by calling
 * `Kern\TaxRomania\Fx\Services\FxPeriodAverageCalculator`, because that calculator's public methods
 * take `Kern\TaxRomania\Fx\Models\FxPeriodAverage` Eloquent models as parameters/return
 * values — there is no DTO-based read surface for a pre-calculated average, and extending `Fx` to
 * add one is outside this task's path scope. The arithmetic (`BLUEPRINT.md` §7.4: monthly = mean of
 * that month's daily rates; annual = mean of the year's 12 monthly averages, never of daily rates
 * directly) is the same published methodology, independently applied to the daily rates `Fx`
 * exposes — see `docs/handoff/020-tax-engine.md` "Open questions" for the reconciliation gap this leaves against `Fx`'s own `ext_fx_period_averages` cache.
 */
final readonly class FxConversionPolicy
{
    /**
     * A technical search bound for `PREVIOUS_BANKING_DAY` (covers even an unusually long run of
     * consecutive non-banking days), never a fiscal fact.
     */
    private const int MAX_PREVIOUS_BANKING_DAY_LOOKBACK_DAYS = 10;

    /** Working precision for average sums, ahead of the final round — mirrors
     *  `Kern\TaxRomania\Fx\Services\FxPeriodAverageCalculator::INTERNAL_SCALE`. */
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

        $converted = $amount->convertedTo(CurrencyCode::BASE, 2, $rate, $roundingMode);

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
     * The rate of `$asOf`, or of the closest day before it that has one, within the lookback.
     *
     * The lookback's rates are read in one query and the newest taken — the answer trying each day
     * in turn, newest first, gave. `resolvedFor` is that day, as the walk back from `$asOf` reaches it.
     *
     * @return array{0: DecimalRate, 1: DateTimeImmutable, 2: list<FxRateProvenanceEntry>}
     */
    private function previousBankingDay(string $currencyCode, DateTimeImmutable $asOf, string $sourceCode): array
    {
        $carbonAsOf = CarbonImmutable::instance($asOf);
        $rates = $this->fxRateQuery->ratesWithProvenanceBetween(
            $currencyCode,
            $carbonAsOf->subDays(self::MAX_PREVIOUS_BANKING_DAY_LOOKBACK_DAYS)->toDateTimeImmutable(),
            $asOf,
            $sourceCode,
        );
        $newest = $rates === [] ? null : $rates[array_key_last($rates)];

        for ($daysBack = 0; $newest !== null && $daysBack <= self::MAX_PREVIOUS_BANKING_DAY_LOOKBACK_DAYS; $daysBack++) {
            $candidateDate = $carbonAsOf->subDays($daysBack)->toDateTimeImmutable();

            if ($candidateDate->format('Y-m-d') === $newest->rateDate->format('Y-m-d')) {
                return [$newest->perUnitRate, $candidateDate, [$this->toProvenanceEntry($newest)]];
            }
        }

        throw FxRateUnavailableWithinLookbackException::forSearch($currencyCode, $asOf, self::MAX_PREVIOUS_BANKING_DAY_LOOKBACK_DAYS);
    }

    /**
     * The mean of the month's published daily rates — read in one query, not one per day.
     *
     * @return array{0: DecimalRate, 1: DateTimeImmutable, 2: list<FxRateProvenanceEntry>}
     */
    private function monthlyAverage(string $currencyCode, int $year, int $month, string $sourceCode): array
    {
        $periodStart = self::carbonDate($year, $month, 1);
        $rates = $this->fxRateQuery->ratesWithProvenanceBetween(
            $currencyCode,
            $periodStart->toDateTimeImmutable(),
            $periodStart->endOfMonth()->toDateTimeImmutable(),
            $sourceCode,
        );

        [$average, $provenance] = $this->averageOfMonth($rates, $currencyCode, $year, $month);

        return [$average, $periodStart->toDateTimeImmutable(), $provenance];
    }

    /**
     * The mean of the year's twelve monthly averages — the year's rates read in one query and
     * grouped by month, each month averaged exactly as {@see self::monthlyAverage()} does.
     *
     * @return array{0: DecimalRate, 1: DateTimeImmutable, 2: list<FxRateProvenanceEntry>}
     */
    private function annualAverage(string $currencyCode, int $year, string $sourceCode): array
    {
        $byMonth = [];

        foreach ($this->fxRateQuery->ratesWithProvenanceBetween(
            $currencyCode,
            self::carbonDate($year, 1, 1)->toDateTimeImmutable(),
            self::carbonDate($year, 12, 31)->toDateTimeImmutable(),
            $sourceCode,
        ) as $rate) {
            $byMonth[(int) $rate->rateDate->format('n')][] = $rate;
        }

        $sum = DecimalRate::zero();
        $monthsAvailable = 0;
        $provenance = [];

        for ($month = 1; $month <= 12; $month++) {
            try {
                [$monthlyAverage, $monthlyProvenance] = $this->averageOfMonth($byMonth[$month] ?? [], $currencyCode, $year, $month);
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
     * One month's daily rates, oldest first, as their mean and the evidence for it.
     *
     * @param  list<FxRateWithProvenance>  $rates
     * @return array{0: DecimalRate, 1: list<FxRateProvenanceEntry>}
     */
    private function averageOfMonth(array $rates, string $currencyCode, int $year, int $month): array
    {
        if ($rates === []) {
            throw NoDailyRatesForPeriodException::forMonth($currencyCode, $year, $month);
        }

        $sum = DecimalRate::zero();

        foreach ($rates as $rate) {
            $sum = $sum->add($rate->perUnitRate, self::INTERNAL_SCALE);
        }

        return [
            $sum->divideByInt(count($rates), self::INTERNAL_SCALE)->roundedTo(self::AVERAGE_RATE_SCALE),
            array_map($this->toProvenanceEntry(...), $rates),
        ];
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
