<?php

declare(strict_types=1);

namespace App\Domain\Fx\Services;

use App\Domain\Fx\Enums\PeriodType;
use App\Domain\Fx\Enums\VerificationStatus;
use App\Domain\Fx\Exceptions\IncompleteMonthlyAveragesException;
use App\Domain\Fx\Exceptions\NoDailyRatesForPeriodException;
use App\Domain\Fx\Models\FxPeriodAverage;
use App\Domain\Fx\Models\FxRate;
use App\Domain\Fx\Models\FxSource;
use App\Domain\Fx\ValueObjects\DecimalRate;
use Carbon\CarbonImmutable;

/**
 * `BLUEPRINT.md` §7.4: monthly average = arithmetic mean of the published daily rates in that month;
 * annual average = arithmetic mean of that year's 12 monthly averages. Both are computed purely
 * from already-imported `fx_rates`/`fx_period_averages` rows — never from a live fetch — so
 * recomputing is always safe and always reproducible (§7.5).
 *
 * A calendar month with no banking-day rates published on some days (weekends, public holidays)
 * needs no special handling here: `fx_rates` simply has no row for those dates (BNR never
 * publishes one), so the arithmetic mean is already computed over exactly the days that exist,
 * never over an assumed 28-31-day denominator and never treating a missing day as zero.
 */
final readonly class FxPeriodAverageCalculator
{
    /**
     * Working precision for intermediate sums/divisions, ahead of the final round to
     * `$roundingScale`. Deliberately larger than any plausible `$roundingScale` so intermediate
     * truncation (BCMath truncates, never rounds) cannot itself bias the final rounded result.
     */
    private const int INTERNAL_SCALE = 12;

    public function __construct(
        private int $roundingScale,
        private string $verificationTolerance,
    ) {
    }

    public function calculateMonthly(FxSource $source, string $currencyCode, int $year, int $month): FxPeriodAverage
    {
        $periodStart = new CarbonImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
        $periodEnd = $periodStart->endOfMonth();

        $dailyRates = FxRate::query()
            ->where('fx_source_id', $source->id)
            ->where('currency_code', $currencyCode)
            ->whereBetween('rate_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->get();

        if ($dailyRates->isEmpty()) {
            throw NoDailyRatesForPeriodException::forMonth($currencyCode, $year, $month);
        }

        $sum = DecimalRate::zero();

        foreach ($dailyRates as $dailyRate) {
            $perUnit = DecimalRate::fromString((string) $dailyRate->rate_decimal)
                ->divideByInt((int) $dailyRate->multiplier, self::INTERNAL_SCALE);

            $sum = $sum->add($perUnit, self::INTERNAL_SCALE);
        }

        $average = $sum->divideByInt($dailyRates->count(), self::INTERNAL_SCALE)->roundedTo($this->roundingScale);

        return $this->upsertCalculated($source, PeriodType::MONTH, $periodStart, $currencyCode, $average, calculatedFromDaily: true, method: 'ARITHMETIC_MEAN_OF_DAILY_RATES');
    }

    public function calculateAnnual(FxSource $source, string $currencyCode, int $year): FxPeriodAverage
    {
        $periodStart = new CarbonImmutable(sprintf('%04d-01-01 00:00:00', $year));
        $periodEnd = $periodStart->endOfYear();

        $monthlyAverages = FxPeriodAverage::query()
            ->where('fx_source_id', $source->id)
            ->where('currency_code', $currencyCode)
            ->where('period_type', PeriodType::MONTH->value)
            ->whereBetween('period_start', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->get();

        if ($monthlyAverages->count() !== 12) {
            throw IncompleteMonthlyAveragesException::forYear($currencyCode, $year, $monthlyAverages->count());
        }

        $sum = DecimalRate::zero();

        foreach ($monthlyAverages as $monthlyAverage) {
            $sum = $sum->add(DecimalRate::fromString((string) $monthlyAverage->calculated_value_decimal), self::INTERNAL_SCALE);
        }

        $average = $sum->divideByInt(12, self::INTERNAL_SCALE)->roundedTo($this->roundingScale);

        return $this->upsertCalculated($source, PeriodType::YEAR, $periodStart, $currencyCode, $average, calculatedFromDaily: false, method: 'ARITHMETIC_MEAN_OF_MONTHLY_AVERAGES');
    }

    /**
     * Records BNR's own published figure against an already-calculated average and sets
     * `verification_status` accordingly. Deliberately separate from `calculateMonthly()`/
     * `calculateAnnual()`: the official bulletin value is not known at calculation time and
     * arrives later, out of band — this table never guesses it, and never silently prefers the
     * calculated value over a known-different official one (`BLUEPRINT.md` §7.4).
     */
    public function recordOfficialPublishedValue(FxPeriodAverage $average, DecimalRate $officialValue): FxPeriodAverage
    {
        $calculated = DecimalRate::fromString($average->calculated_value_decimal);
        $tolerance = DecimalRate::fromString($this->verificationTolerance);

        $withinTolerance = $calculated
            ->absoluteDifference($officialValue, self::INTERNAL_SCALE)
            ->isLessThanOrEqualTo($tolerance, self::INTERNAL_SCALE);

        $average->update([
            'official_published_value_decimal' => $officialValue->toString(),
            'verification_tolerance_decimal' => $this->verificationTolerance,
            'verification_status' => ($withinTolerance ? VerificationStatus::VERIFIED : VerificationStatus::MISMATCH)->value,
        ]);

        return $average->refresh();
    }

    private function upsertCalculated(
        FxSource $source,
        PeriodType $periodType,
        CarbonImmutable $periodStart,
        string $currencyCode,
        DecimalRate $average,
        bool $calculatedFromDaily,
        string $method,
    ): FxPeriodAverage {
        /** @var FxPeriodAverage $model */
        $model = FxPeriodAverage::query()->updateOrCreate(
            [
                'fx_source_id' => $source->id,
                'period_type' => $periodType->value,
                'period_start' => $periodStart->toDateString(),
                'currency_code' => $currencyCode,
            ],
            [
                'method' => $method,
                'calculated_value_decimal' => $average->toString(),
                'calculated_from_daily' => $calculatedFromDaily,
            ],
        );

        // On the create path, `verification_status` is populated only by the migration's own
        // column DEFAULT ('PENDING') — Eloquent never syncs a DB-level default back into an
        // in-memory model after INSERT, so without this refresh a freshly-created row would read
        // back as `verification_status === null` in this same request despite the database
        // already holding 'PENDING'. Refreshing unconditionally (not just on the create path)
        // keeps this method's return value always exactly the persisted row either way.
        return $model->refresh();
    }
}
