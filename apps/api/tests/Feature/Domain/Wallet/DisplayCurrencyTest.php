<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Wallet\DisplayCurrencyTest;

use DateTimeImmutable;
use Illuminate\Support\Facades\Artisan;
use Kern\Legislation\Database\Factories\OfficialSourceFactory;
use Kern\Legislation\Database\Factories\SourceDocumentVersionFactory;
use Kern\Legislation\Models\OfficialSource;
use Kern\TaxRomania\Fx\Exceptions\FxRateNotFoundException;
use Kern\TaxRomania\Fx\Models\FxRate;
use Kern\TaxRomania\Fx\Services\FxRateQuery;
use Kern\TaxRomania\Wallet\Services\Fx\DisplayCurrencyConverter;

/**
 * Adding per-currency totals into one, for a dashboard.
 *
 * The behaviour worth pinning is not the arithmetic — it is what happens when a rate is missing,
 * because that is where a total quietly becomes wrong. An omitted currency must be reported, never
 * approximated from a neighbour and never silently dropped.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

/**
 * One published BNR rate, with the source document version that carries its provenance.
 *
 * Built through the factories the FX module already ships rather than by hand: a rate hangs
 * off an `ext_official_sources` row, and reconstructing that chain per test is how a fixture drifts out
 * of step with the schema.
 */
function publishRate(string $currency, string $date, string $rate): void
{
    $source = OfficialSource::query()->where('code', 'BNR')->first()
        ?? OfficialSourceFactory::new()->createOne(['code' => 'BNR']);

    $version = SourceDocumentVersionFactory::new()->createOne([
        'content_sha256' => hash('sha256', $date . $currency),
        'raw_object_path' => "sources/bnr/{$currency}-{$date}",
    ]);

    FxRate::query()->updateOrCreate(
        ['official_source_id' => $source->id, 'rate_date' => $date, 'currency_code' => $currency],
        ['multiplier' => 1, 'rate_decimal' => $rate, 'source_document_version_id' => $version->id],
    );
}

it('adds foreign amounts into the display currency at the latest published rate', function (): void {
    publishRate('EUR', '2026-09-09', '5.0000');

    $total = app(DisplayCurrencyConverter::class)->convert(
        ['RON' => 100_00, 'EUR' => 100_00],
        'RON',
        new DateTimeImmutable('2026-09-10'),
    );

    // 100 RON + (100 EUR x 5) = 600 RON.
    expect($total->amountMinor)->toBe(600_00)
        ->and($total->currencyCode)->toBe('RON')
        ->and($total->complete())->toBeTrue()
        // The evidence travels with the number: a converted total without its rate is a claim
        // nobody can check.
        ->and($total->rates['EUR']['rate_date'])->toBe('2026-09-09');
});

it('uses the most recent rate on or before the date, not only an exact match', function (): void {
    publishRate('EUR', '2026-09-04', '4.9000');

    // A Sunday has no published rate, and the imported history has real gaps. The strict lookup used
    // for fiscal figures refuses to approximate; this one deliberately reaches back.
    $total = app(DisplayCurrencyConverter::class)->convert(
        ['EUR' => 100_00],
        'RON',
        new DateTimeImmutable('2026-09-06'),
    );

    expect($total->amountMinor)->toBe(490_00)
        ->and($total->rates['EUR']['rate_date'])->toBe('2026-09-04');
});

it('leaves out a currency it has no rate for, and says which', function (): void {
    publishRate('EUR', '2026-09-09', '5.0000');

    $total = app(DisplayCurrencyConverter::class)->convert(
        ['RON' => 100_00, 'EUR' => 100_00, 'JPY' => 1_000_00],
        'RON',
        new DateTimeImmutable('2026-09-10'),
    );

    // Substituting a stale or neighbouring rate would make the figure look complete while being
    // wrong. Omitting it and saying so is the honest failure.
    expect($total->amountMinor)->toBe(600_00)
        ->and($total->complete())->toBeFalse()
        ->and($total->missing)->toBe(['JPY']);
});

it('cross-converts through RON when the display currency is not RON', function (): void {
    publishRate('EUR', '2026-09-09', '5.0000');
    publishRate('USD', '2026-09-09', '4.0000');

    $total = app(DisplayCurrencyConverter::class)->convert(
        ['USD' => 100_00],
        'EUR',
        new DateTimeImmutable('2026-09-10'),
    );

    // 100 USD -> 400 RON -> 80 EUR. The BNR never publishes a USD/EUR rate, so it goes through the
    // currency both are quoted against rather than inventing one.
    expect($total->amountMinor)->toBe(80_00)
        ->and($total->currencyCode)->toBe('EUR');
});

it('needs no rate at all when everything is already in the display currency', function (): void {
    $total = app(DisplayCurrencyConverter::class)->convert(
        ['RON' => 250_00],
        'RON',
        new DateTimeImmutable('2026-09-10'),
    );

    // Nothing to attribute — and claiming a rate here would be inventing provenance.
    expect($total->amountMinor)->toBe(250_00)
        ->and($total->rates)->toBe([])
        ->and($total->complete())->toBeTrue();
});

it('reports everything as missing when the display currency itself has no rate', function (): void {
    publishRate('EUR', '2026-09-09', '5.0000');

    $total = app(DisplayCurrencyConverter::class)->convert(
        ['EUR' => 100_00],
        'JPY',
        new DateTimeImmutable('2026-09-10'),
    );

    // A zero total shown as if real would be the worst outcome here.
    expect($total->amountMinor)->toBe(0)
        ->and($total->complete())->toBeFalse()
        ->and($total->missing)->toBe(['EUR']);
});

it('keeps the strict fiscal lookup strict', function (): void {
    publishRate('EUR', '2026-09-04', '4.9000');

    $rates = app(FxRateQuery::class);

    // The display lookup reaches back; the fiscal one must not, or every stored figure becomes
    // approximate.
    expect($rates->latestRateOnOrBefore('EUR', new DateTimeImmutable('2026-09-06')))->not->toBeNull();
    expect(fn () => $rates->rate('EUR', new DateTimeImmutable('2026-09-06')))
        ->toThrow(FxRateNotFoundException::class);
});

it('converts a balance far larger than a scaled integer could hold', function (): void {
    publishRate('EUR', '2026-09-09', '5.2542');

    // 32,423,423.00 EUR. The first version of this converter scaled each rate to a fixed-point
    // integer and multiplied: `3.24e9 x 5.25e10` is 1.7e20, past `PHP_INT_MAX`, so PHP silently
    // produced a float and `intdiv()` threw — the whole wallet overview answered 500. A float
    // result would have been worse than the crash, because money must never round through binary
    // floating point.
    $total = app(DisplayCurrencyConverter::class)->convert(
        ['EUR' => 3_242_342_300],
        'RON',
        new DateTimeImmutable('2026-09-10'),
    );

    // 3,242,342,300 x 5.2542 = 17,035,914,912.66 bani, rounded half-up.
    expect($total->amountMinor)->toBe(17_035_914_913)
        ->and($total->complete())->toBeTrue();
});

it('rounds half-up once, at the end, rather than per currency', function (): void {
    publishRate('EUR', '2026-09-09', '5.0001');
    publishRate('USD', '2026-09-09', '4.0001');

    $total = app(DisplayCurrencyConverter::class)->convert(
        ['EUR' => 1, 'USD' => 1],
        'RON',
        new DateTimeImmutable('2026-09-10'),
    );

    // 5.0001 -> 5 and 4.0001 -> 4. Rounding inside the loop is deliberate here (each currency is a
    // separate conversion), but each rounding is half-up on the exact decimal rather than on a
    // float, so a rate ending in 5 never drifts.
    expect($total->amountMinor)->toBe(9);
});
