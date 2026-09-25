<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Fx\FxPeriodAverageCalculatorTest;

use App\Domain\Fx\Enums\PeriodType;
use App\Domain\Fx\Enums\VerificationStatus;
use App\Domain\Fx\Exceptions\IncompleteMonthlyAveragesException;
use App\Domain\Fx\Exceptions\NoDailyRatesForPeriodException;
use App\Domain\Fx\Models\FxPeriodAverage;
use App\Domain\Fx\Models\FxRate;
use App\Domain\Fx\Services\FxPeriodAverageCalculator;
use App\Domain\Fx\ValueObjects\DecimalRate;
use Illuminate\Support\Facades\Artisan;
use Kern\Legislation\Database\Factories\OfficialSourceFactory;
use Kern\Legislation\Database\Factories\SourceDocumentVersionFactory;

beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

function calculator(): FxPeriodAverageCalculator
{
    return new FxPeriodAverageCalculator(roundingScale: 6, verificationTolerance: '0.0001');
}

function seedDailyRate(\Kern\Legislation\Models\OfficialSource $source, string $versionId, string $date, string $currency, int $multiplier, string $value): void
{
    FxRate::query()->create([
        'official_source_id' => $source->id,
        'rate_date' => $date,
        'currency_code' => $currency,
        'multiplier' => $multiplier,
        'rate_decimal' => $value,
        'source_document_version_id' => $versionId,
    ]);
}

it('computes the monthly average as the arithmetic mean of only the published daily rates, ignoring missing (non-banking) days', function (): void {
    $source = OfficialSourceFactory::new()->createOne();
    $version = SourceDocumentVersionFactory::new()->createOne();

    // January 2026 has 31 calendar days; only 3 banking-day rates are seeded, simulating
    // weekends/holidays having no published row at all (not a zero, not forward-filled).
    seedDailyRate($source, $version->id, '2026-01-05', 'EUR', 1, '4.970000');
    seedDailyRate($source, $version->id, '2026-01-06', 'EUR', 1, '4.980000');
    seedDailyRate($source, $version->id, '2026-01-07', 'EUR', 1, '4.990000');

    $average = calculator()->calculateMonthly($source->id, 'EUR', 2026, 1);

    expect($average->period_type)->toBe(PeriodType::MONTH)
        ->and($average->period_start->format('Y-m-d'))->toBe('2026-01-01')
        ->and($average->calculated_value_decimal)->toBe('4.980000')
        ->and($average->calculated_from_daily)->toBeTrue()
        ->and($average->verification_status)->toBe(VerificationStatus::PENDING)
        ->and($average->official_published_value_decimal)->toBeNull();
});

it('divides the multiplier out before averaging a currency quoted per more than one unit', function (): void {
    $source = OfficialSourceFactory::new()->createOne();
    $version = SourceDocumentVersionFactory::new()->createOne();

    seedDailyRate($source, $version->id, '2026-01-05', 'HUF', 100, '1278.000000');
    seedDailyRate($source, $version->id, '2026-01-06', 'HUF', 100, '1282.000000');

    $average = calculator()->calculateMonthly($source->id, 'HUF', 2026, 1);

    // Per-unit rates are 12.78 and 12.82 → mean 12.80, not the un-divided mean of 1280.00.
    expect($average->calculated_value_decimal)->toBe('12.800000');
});

it('throws when no daily rates exist at all for the requested month', function (): void {
    $source = OfficialSourceFactory::new()->createOne();

    calculator()->calculateMonthly($source->id, 'EUR', 2026, 2);
})->throws(NoDailyRatesForPeriodException::class);

it('recomputing a monthly average is safe and overwrites the calculated value in place', function (): void {
    $source = OfficialSourceFactory::new()->createOne();
    $version = SourceDocumentVersionFactory::new()->createOne();

    seedDailyRate($source, $version->id, '2026-01-05', 'EUR', 1, '4.970000');
    $first = calculator()->calculateMonthly($source->id, 'EUR', 2026, 1);

    seedDailyRate($source, $version->id, '2026-01-06', 'EUR', 1, '4.990000');
    $second = calculator()->calculateMonthly($source->id, 'EUR', 2026, 1);

    expect(FxPeriodAverage::query()->count())->toBe(1)
        ->and($first->calculated_value_decimal)->toBe('4.970000')
        ->and($second->calculated_value_decimal)->toBe('4.980000');
});

it('computes the annual average as the arithmetic mean of the 12 monthly averages', function (): void {
    $source = OfficialSourceFactory::new()->createOne();

    for ($month = 1; $month <= 12; $month++) {
        FxPeriodAverage::query()->create([
            'official_source_id' => $source->id,
            'period_type' => PeriodType::MONTH->value,
            'period_start' => sprintf('2026-%02d-01', $month),
            'currency_code' => 'EUR',
            'method' => 'ARITHMETIC_MEAN_OF_DAILY_RATES',
            'calculated_value_decimal' => '5.000000',
            'calculated_from_daily' => true,
        ]);
    }

    $annual = calculator()->calculateAnnual($source->id, 'EUR', 2026);

    expect($annual->period_type)->toBe(PeriodType::YEAR)
        ->and($annual->period_start->format('Y-m-d'))->toBe('2026-01-01')
        ->and($annual->calculated_value_decimal)->toBe('5.000000')
        ->and($annual->calculated_from_daily)->toBeFalse();
});

it('refuses to compute an annual average from an incomplete set of monthly averages', function (): void {
    $source = OfficialSourceFactory::new()->createOne();

    for ($month = 1; $month <= 11; $month++) {
        FxPeriodAverage::query()->create([
            'official_source_id' => $source->id,
            'period_type' => PeriodType::MONTH->value,
            'period_start' => sprintf('2026-%02d-01', $month),
            'currency_code' => 'EUR',
            'method' => 'ARITHMETIC_MEAN_OF_DAILY_RATES',
            'calculated_value_decimal' => '5.000000',
            'calculated_from_daily' => true,
        ]);
    }

    calculator()->calculateAnnual($source->id, 'EUR', 2026);
})->throws(IncompleteMonthlyAveragesException::class);

it('marks VERIFIED when the official published value is within tolerance', function (): void {
    $source = OfficialSourceFactory::new()->createOne();
    $version = SourceDocumentVersionFactory::new()->createOne();
    seedDailyRate($source, $version->id, '2026-01-05', 'EUR', 1, '4.980000');

    $average = calculator()->calculateMonthly($source->id, 'EUR', 2026, 1);
    $updated = calculator()->recordOfficialPublishedValue($average, DecimalRate::fromString('4.980050'));

    expect($updated->verification_status)->toBe(VerificationStatus::VERIFIED)
        ->and($updated->official_published_value_decimal)->toBe('4.980050')
        ->and($updated->verification_tolerance_decimal)->toBe('0.0001');
});

it('marks MISMATCH when the official published value is outside tolerance, never silently preferring one value', function (): void {
    $source = OfficialSourceFactory::new()->createOne();
    $version = SourceDocumentVersionFactory::new()->createOne();
    seedDailyRate($source, $version->id, '2026-01-05', 'EUR', 1, '4.980000');

    $average = calculator()->calculateMonthly($source->id, 'EUR', 2026, 1);
    $updated = calculator()->recordOfficialPublishedValue($average, DecimalRate::fromString('5.100000'));

    expect($updated->verification_status)->toBe(VerificationStatus::MISMATCH)
        // both values are kept side by side — neither is discarded.
        ->and($updated->calculated_value_decimal)->toBe('4.980000')
        ->and($updated->official_published_value_decimal)->toBe('5.100000');
});
