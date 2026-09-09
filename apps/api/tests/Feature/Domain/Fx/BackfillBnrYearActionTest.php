<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Fx\BackfillBnrYearActionTest;

use App\Domain\Fx\Actions\BackfillBnrYear;
use App\Domain\Fx\Exceptions\BnrEndpointNotConfiguredException;
use App\Domain\Fx\Models\FxRate;
use App\Domain\Fx\Models\FxRateAnomaly;
use App\Domain\TaxKnowledge\Models\SourceDocumentVersion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * `bnr-fixture.test` — see `ImportBnrDailyRatesActionTest`'s docblock: synthetic, test-only,
 * intercepted entirely by `Http::fake()`, never a real BNR host or path.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    Storage::fake('s3');

    config([
        'sources.entries.BNR.allowed_hosts' => ['bnr-fixture.test'],
        'sources.entries.BNR.endpoints.yearly_xml_template' => 'https://bnr-fixture.test/years/{year}.xml',
    ]);
});

function yearlyFixture(string $name): string
{
    $contents = file_get_contents(__DIR__ . '/../../../Fixtures/Fx/Bnr/' . $name);

    if ($contents === false) {
        throw new RuntimeException("Missing test fixture: {$name}");
    }

    return $contents;
}

it('substitutes {year} into the configured template and imports every banking day in the archive', function (): void {
    Http::fake(['https://bnr-fixture.test/years/2026.xml' => Http::response(yearlyFixture('synthetic-yearly.xml'), 200, ['Content-Type' => 'application/xml'])]);

    $summary = app(BackfillBnrYear::class)(2026);

    // 5 days x 3 currencies each = 15 rates created.
    expect($summary->daysProcessed)->toBe(5)
        ->and($summary->created)->toBe(15)
        ->and($summary->anomalies)->toBe(0)
        ->and(FxRate::query()->count())->toBe(15)
        ->and(SourceDocumentVersion::query()->count())->toBe(1);

    Http::assertSent(function ($request): bool {
        return (string) $request->url() === 'https://bnr-fixture.test/years/2026.xml';
    });
});

it('is idempotent across a full re-backfill of unchanged content', function (): void {
    Http::fake(['https://bnr-fixture.test/years/2026.xml' => Http::response(yearlyFixture('synthetic-yearly.xml'), 200, ['Content-Type' => 'application/xml'])]);

    app(BackfillBnrYear::class)(2026);
    $second = app(BackfillBnrYear::class)(2026);

    expect($second->wasNewDocumentVersion)->toBeFalse()
        ->and($second->created)->toBe(0)
        ->and($second->unchanged)->toBe(15)
        ->and($second->anomalies)->toBe(0)
        ->and(SourceDocumentVersion::query()->count())->toBe(1);
});

it('records exactly one anomaly for the one day whose value changed on re-backfill, leaving every other day untouched', function (): void {
    // A single Http::fake() call with a sequence — see SourceIngestionServiceTest's "creates a
    // second version" test for why two separate Http::fake() calls for the same URL do not work
    // (Laravel merges stub registrations; the first-registered one always wins).
    Http::fake([
        'https://bnr-fixture.test/years/2026.xml' => Http::sequence()
            ->push(yearlyFixture('synthetic-yearly.xml'), 200, ['Content-Type' => 'application/xml'])
            ->push(yearlyFixture('synthetic-yearly-changed-value.xml'), 200, ['Content-Type' => 'application/xml']),
    ]);

    app(BackfillBnrYear::class)(2026);
    $second = app(BackfillBnrYear::class)(2026);

    expect($second->anomalies)->toBe(1)
        ->and($second->unchanged)->toBe(14)
        ->and(FxRateAnomaly::query()->count())->toBe(1)
        ->and(FxRate::query()->count())->toBe(15);

    $anomaly = FxRateAnomaly::query()->firstOrFail();
    expect($anomaly->currency_code)->toBe('EUR')
        ->and($anomaly->rate_date->format('Y-m-d'))->toBe('2026-01-15')
        ->and((string) $anomaly->existing_rate_decimal)->toBe('4.976400')
        ->and((string) $anomaly->incoming_rate_decimal)->toBe('5.200000');

    // The originally-imported row for the disputed day is untouched.
    $originalRow = FxRate::query()->where('currency_code', 'EUR')->where('rate_date', '2026-01-15')->firstOrFail();
    expect((string) $originalRow->rate_decimal)->toBe('4.976400');
});

it('throws BnrEndpointNotConfiguredException rather than guessing a URL when the yearly template is unset', function (): void {
    config(['sources.entries.BNR.endpoints.yearly_xml_template' => null]);

    app(BackfillBnrYear::class)(2026);
})->throws(BnrEndpointNotConfiguredException::class);
