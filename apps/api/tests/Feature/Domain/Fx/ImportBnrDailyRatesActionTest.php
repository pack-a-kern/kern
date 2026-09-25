<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Fx\ImportBnrDailyRatesActionTest;

use App\Domain\Fx\Actions\ImportBnrDailyRates;
use App\Domain\Fx\Exceptions\BnrEndpointNotConfiguredException;
use App\Domain\Fx\Models\FxRate;
use App\Domain\Fx\Models\FxRateAnomaly;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Kern\Legislation\Fetching\Exceptions\UnexpectedContentTypeException;
use Kern\Legislation\Models\SourceDocumentVersion;
use RuntimeException;

/**
 * `bnr-fixture.test` is a synthetic host that exists only inside this test's own `config()`
 * override — it is never written to `config/sources.php`, and every request to it is intercepted
 * by `Http::fake()` and never reaches a real network. This is deliberate
 * (`docs/reference/bnr-fx-endpoints.md`): the real `BNR_DAILY_XML_URL` stays unset everywhere
 * else, and this suite proves the pipeline end to end against a fixture instead of a guessed
 * production URL.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    Storage::fake('s3');

    config([
        'sources.entries.BNR.allowed_hosts' => ['bnr-fixture.test'],
        'sources.entries.BNR.endpoints.daily_xml' => 'https://bnr-fixture.test/daily.xml',
    ]);
});

function dailyFixture(string $name): string
{
    $contents = file_get_contents(__DIR__ . '/../../../Fixtures/Fx/Bnr/' . $name);

    if ($contents === false) {
        throw new RuntimeException("Missing test fixture: {$name}");
    }

    return $contents;
}

it('fetches, parses, and imports every currency for the one day in a daily feed', function (): void {
    Http::fake(['https://bnr-fixture.test/daily.xml' => Http::response(dailyFixture('synthetic-daily.xml'), 200, ['Content-Type' => 'application/xml'])]);

    $summary = app(ImportBnrDailyRates::class)();

    expect($summary->daysProcessed)->toBe(1)
        ->and($summary->created)->toBe(3)
        ->and($summary->unchanged)->toBe(0)
        ->and($summary->anomalies)->toBe(0)
        ->and($summary->wasNewDocumentVersion)->toBeTrue()
        ->and(FxRate::query()->count())->toBe(3)
        ->and(SourceDocumentVersion::query()->count())->toBe(1);
});

it('is idempotent end to end: running it twice with unchanged content imports nothing new', function (): void {
    Http::fake(['https://bnr-fixture.test/daily.xml' => Http::response(dailyFixture('synthetic-daily.xml'), 200, ['Content-Type' => 'application/xml'])]);

    app(ImportBnrDailyRates::class)();
    $second = app(ImportBnrDailyRates::class)();

    expect($second->wasNewDocumentVersion)->toBeFalse()
        ->and($second->created)->toBe(0)
        ->and($second->unchanged)->toBe(3)
        ->and($second->anomalies)->toBe(0)
        ->and(FxRate::query()->count())->toBe(3)
        ->and(SourceDocumentVersion::query()->count())->toBe(1);
});

it('records an anomaly, not a silent overwrite, when the daily feed changes for an already-imported date', function (): void {
    // A single Http::fake() call with a sequence — see SourceIngestionServiceTest's "creates a
    // second version" test for why two separate Http::fake() calls for the same URL do not work
    // (Laravel merges stub registrations; the first-registered one always wins).
    Http::fake([
        'https://bnr-fixture.test/daily.xml' => Http::sequence()
            ->push(dailyFixture('synthetic-daily.xml'), 200, ['Content-Type' => 'application/xml'])
            ->push(dailyFixture('synthetic-daily-changed-eur.xml'), 200, ['Content-Type' => 'application/xml']),
    ]);

    app(ImportBnrDailyRates::class)();
    $second = app(ImportBnrDailyRates::class)();

    expect($second->anomalies)->toBe(1)
        ->and($second->unchanged)->toBe(2)
        ->and(FxRateAnomaly::query()->count())->toBe(1);

    $eurRow = FxRate::query()->where('currency_code', 'EUR')->firstOrFail();
    expect((string) $eurRow->rate_decimal)->toBe('4.976400');
});

it('rejects a homepage-shaped HTML response instead of ingesting it as rate data', function (): void {
    Http::fake(['https://bnr-fixture.test/daily.xml' => Http::response('<!doctype html><html><body>BNR homepage</body></html>', 200, ['Content-Type' => 'text/html'])]);

    app(ImportBnrDailyRates::class)();
})->throws(UnexpectedContentTypeException::class);

it('throws BnrEndpointNotConfiguredException rather than guessing a URL when unset', function (): void {
    config(['sources.entries.BNR.endpoints.daily_xml' => null]);

    app(ImportBnrDailyRates::class)();
})->throws(BnrEndpointNotConfiguredException::class);
