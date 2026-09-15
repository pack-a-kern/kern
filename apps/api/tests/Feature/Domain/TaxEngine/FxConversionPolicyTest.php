<?php

declare(strict_types=1);

use App\Domain\Fx\Models\FxRate;
use App\Domain\Fx\Services\FxRateQuery;
use App\Domain\TaxEngine\Enums\FxConversionMethod;
use App\Domain\TaxEngine\Enums\RoundingMode;
use App\Domain\TaxEngine\Exceptions\FxRateUnavailableWithinLookbackException;
use App\Domain\TaxEngine\Exceptions\IncompleteFiscalYearAveragesException;
use App\Domain\TaxEngine\Services\FxConversionPolicy;
use App\Domain\TaxEngine\ValueObjects\Money;
use App\Domain\TaxKnowledge\Models\OfficialSource;
use Database\Factories\SourceDocumentVersionFactory;

/**
 * `BLUEPRINT.md` §6.9/§7.5 — every conversion carries a mandatory, explicit method, and full
 * provenance. All rates here are SYNTHETIC (`docs/AGENTS.md` §2) — none resembles a real
 * historical BNR quote for any date.
 */
function seedSyntheticFxRate(string $currencyCode, string $date, string $rateDecimal, int $multiplier = 1): void
{
    $fxSource = OfficialSource::query()->where('code', 'BNR')->first() ?? OfficialSource::factory()->create(['code' => 'BNR']);
    $version = SourceDocumentVersionFactory::new()->createOne();

    FxRate::create([
        'official_source_id' => $fxSource->id,
        'rate_date' => $date,
        'currency_code' => $currencyCode,
        'multiplier' => $multiplier,
        'rate_decimal' => $rateDecimal,
        'source_document_version_id' => $version->id,
    ]);
}

function makeFxConversionPolicy(): FxConversionPolicy
{
    return new FxConversionPolicy(app(FxRateQuery::class));
}

it('converts using the SPOT method for an exact date', function (): void {
    seedSyntheticFxRate('XTS', '2026-06-15', '5.0000'); // synthetic test currency

    $amount = Money::ofMinorUnits(10000, 'XTS'); // 100.00 XTS
    $result = makeFxConversionPolicy()->convertToRon($amount, FxConversionMethod::SPOT, new DateTimeImmutable('2026-06-15'), RoundingMode::HALF_UP);

    expect($result->convertedAmount->minorUnits)->toBe(50000) // 500.00 RON
        ->and($result->convertedAmount->currencyCode)->toBe('RON')
        ->and($result->method)->toBe(FxConversionMethod::SPOT)
        ->and($result->provenance)->toHaveCount(1)
        ->and($result->provenance[0]->sourceDocumentVersionId)->not->toBeEmpty();
});

it('respects the multiplier trap — a rate quoted per 100 units', function (): void {
    seedSyntheticFxRate('XTS', '2026-06-15', '150.0000', multiplier: 100); // 100 XTS = 150 RON

    $amount = Money::ofMinorUnits(10000, 'XTS'); // 100.00 XTS = 1 unit of the 100-multiplier
    $result = makeFxConversionPolicy()->convertToRon($amount, FxConversionMethod::SPOT, new DateTimeImmutable('2026-06-15'), RoundingMode::HALF_UP);

    // per-unit rate = 150 / 100 = 1.50 RON per XTS; 100.00 XTS -> 150.00 RON.
    expect($result->convertedAmount->minorUnits)->toBe(15000);
});

it('falls back to the previous banking day when the requested date has no rate', function (): void {
    seedSyntheticFxRate('XTS', '2026-06-12', '4.0000'); // Friday
    // 2026-06-13/14 (weekend) have no rate.

    $amount = Money::ofMinorUnits(1000, 'XTS');
    $result = makeFxConversionPolicy()->convertToRon($amount, FxConversionMethod::PREVIOUS_BANKING_DAY, new DateTimeImmutable('2026-06-14'), RoundingMode::HALF_UP);

    // FxRateQuery::rateWithProvenance() divides the published rate by its multiplier at a fixed
    // 10-decimal-place working scale (App\Domain\Fx\Services\FxRateQuery::DIVISION_SCALE) — the
    // trailing zeros are that division's precision, not a formatting artefact.
    expect($result->rateApplied->toString())->toBe('4.0000000000')
        ->and($result->resolvedFor->format('Y-m-d'))->toBe('2026-06-12');
});

it('throws when PREVIOUS_BANKING_DAY exhausts its lookback window', function (): void {
    $amount = Money::ofMinorUnits(1000, 'XTS');

    expect(fn () => makeFxConversionPolicy()->convertToRon($amount, FxConversionMethod::PREVIOUS_BANKING_DAY, new DateTimeImmutable('2026-06-14'), RoundingMode::HALF_UP))
        ->toThrow(FxRateUnavailableWithinLookbackException::class);
});

it('computes a MONTHLY_AVERAGE as the arithmetic mean of the month\'s daily rates', function (): void {
    seedSyntheticFxRate('XTS', '2026-06-01', '4.0000');
    seedSyntheticFxRate('XTS', '2026-06-15', '5.0000');
    seedSyntheticFxRate('XTS', '2026-06-30', '6.0000');

    $amount = Money::ofMinorUnits(10000, 'XTS'); // 100.00 XTS
    $result = makeFxConversionPolicy()->convertToRon($amount, FxConversionMethod::MONTHLY_AVERAGE, new DateTimeImmutable('2026-06-15'), RoundingMode::HALF_UP);

    // mean of 4, 5, 6 = 5.000000
    expect($result->rateApplied->toString())->toBe('5.000000')
        ->and($result->convertedAmount->minorUnits)->toBe(50000)
        ->and($result->provenance)->toHaveCount(3);
});

it('computes an ANNUAL_AVERAGE as the mean of the year\'s 12 monthly averages, never of daily rates directly', function (): void {
    // One rate per month, each a different value, so a naive daily-mean would equal the same
    // number here — use unequal month lengths of data to make the two methods diverge: seed two
    // very different values within the SAME month for month 1, proving the annual figure uses
    // that month's *average* (4.5), not each individual daily rate.
    seedSyntheticFxRate('XTS', '2026-01-01', '4.0000');
    seedSyntheticFxRate('XTS', '2026-01-31', '5.0000'); // Jan average = 4.5
    for ($month = 2; $month <= 12; $month++) {
        seedSyntheticFxRate('XTS', sprintf('2026-%02d-15', $month), '10.0000');
    }

    $amount = Money::ofMinorUnits(10000, 'XTS');
    $result = makeFxConversionPolicy()->convertToRon($amount, FxConversionMethod::ANNUAL_AVERAGE, new DateTimeImmutable('2026-06-01'), RoundingMode::HALF_UP);

    // January's monthly average is (4.0 + 5.0) / 2 = 4.5, not 4.0 or 5.0 individually — proving
    // the annual figure is built from monthly averages. Annual = (4.5 + 10*11) / 12 = 9.541667
    // (rounded half-up to 6 places), never a straight mean of all 13 daily rates.
    expect($result->rateApplied->toString())->toBe('9.541667');
});

it('throws when fewer than 12 months have any published rate for an annual average', function (): void {
    seedSyntheticFxRate('XTS', '2026-01-15', '4.0000');

    $amount = Money::ofMinorUnits(10000, 'XTS');

    expect(fn () => makeFxConversionPolicy()->convertToRon($amount, FxConversionMethod::ANNUAL_AVERAGE, new DateTimeImmutable('2026-06-01'), RoundingMode::HALF_UP))
        ->toThrow(IncompleteFiscalYearAveragesException::class);
});

it('records full provenance for every conversion — amount, currency, rate, date, source, method, result', function (): void {
    seedSyntheticFxRate('XTS', '2026-06-15', '5.0000');

    $amount = Money::ofMinorUnits(10000, 'XTS');
    $result = makeFxConversionPolicy()->convertToRon($amount, FxConversionMethod::SPOT, new DateTimeImmutable('2026-06-15'), RoundingMode::HALF_UP, 'BNR');

    $array = $result->toArray();

    expect($array)->toHaveKeys(['original_amount', 'converted_amount', 'method', 'rate_applied', 'resolved_for', 'source_code', 'provenance'])
        ->and($array['source_code'])->toBe('BNR')
        ->and($array['provenance'][0])->toHaveKeys(['rate_date', 'per_unit_rate', 'source_document_version_id', 'content_sha256', 'raw_object_path', 'retrieved_at']);
});
