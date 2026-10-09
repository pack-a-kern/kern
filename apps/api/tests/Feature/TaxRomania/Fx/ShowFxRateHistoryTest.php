<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kern\Legislation\Database\Factories\OfficialSourceFactory;
use Kern\Legislation\Database\Factories\SourceDocumentVersionFactory;
use Kern\Legislation\Models\OfficialSource;
use Kern\Legislation\Models\SourceDocumentVersion;

/**
 * The published rate series the dashboard draws.
 */

/**
 * One published rate.
 *
 * Inserted rather than built through a factory: `FxRate` has none, and the row's two foreign keys are the
 * source document it was read out of — provenance these tests assert nothing about.
 *
 * Made fresh on every call, not cached. Holding them in `$GLOBALS` survives the rollback between tests while
 * the rows they name do not, so the second test inserts a foreign key pointing at nothing: a helper that
 * passes alone and fails in its own file, which is the worst shape a test failure takes.
 */
function publishedRate(string $currency, string $date, string $rate = '5.0000', int $multiplier = 1): void
{
    /** @var OfficialSource $source */
    $source = OfficialSourceFactory::new()->createOne();
    /** @var SourceDocumentVersion $version */
    $version = SourceDocumentVersionFactory::new()->createOne();

    DB::table('ext_fx_rates')->insert([
        'id' => (string) Str::uuid7(),
        'rate_date' => $date,
        'currency_code' => $currency,
        'multiplier' => $multiplier,
        'rate_decimal' => $rate,
        'official_source_id' => $source->id,
        'source_document_version_id' => $version->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
it('returns a currency\'s recent rates, oldest first', function (): void {
    $user = ordinaryUser();

    foreach (['2026-10-07' => '4.9700', '2026-10-09' => '5.0600', '2026-10-08' => '5.0100'] as $date => $rate) {
        publishedRate('EUR', $date, $rate);
    }

    /** @var array{currency: string, points: list<array{date: string, rate: string}>} $body */
    $body = $this->actingAs($user)->getJson('/api/v1/fx/rates/history?currency=EUR&months=3')->assertOk()->json();

    expect($body['currency'])->toBe('EUR')
        ->and(array_column($body['points'], 'date'))->toBe(['2026-10-07', '2026-10-08', '2026-10-09']);
});

it('divides by the multiplier, so a per-hundred currency is not shown at a hundred times its worth', function (): void {
    /*
     * BNR quotes HUF, JPY and KRW per 100 units, and `multiplier` is how that is recorded. Plotted raw, the
     * forint looks like a currency that moved rather than a column nobody read.
     */
    $user = ordinaryUser();
    publishedRate('HUF', '2026-10-09', '1.2500', 100);

    /** @var array{currency: string, points: list<array{date: string, rate: string}>} $body */
    $body = $this->actingAs($user)->getJson('/api/v1/fx/rates/history?currency=HUF&months=3')->assertOk()->json();

    expect($body['points'][0]['rate'])->toBe('0.0125');
});

it('leaves out what falls before the window', function (): void {
    $user = ordinaryUser();
    publishedRate('USD', now()->subMonths(8)->toDateString());
    publishedRate('USD', now()->subMonth()->toDateString());

    /** @var array{currency: string, points: list<array{date: string, rate: string}>} $body */
    $body = $this->actingAs($user)->getJson('/api/v1/fx/rates/history?currency=USD&months=3')->assertOk()->json();

    expect($body['points'])->toHaveCount(1);
});

it('refuses a window it does not offer', function (): void {
    // Beyond five years the line says less with every point added, and there is an export for a dataset.
    $this->actingAs(ordinaryUser())
        ->getJson('/api/v1/fx/rates/history?currency=EUR&months=240')
        ->assertStatus(422);
});

it('is not readable by a stranger', function (): void {
    // Published figures, but not published by this installation to anyone who asks.
    $this->getJson('/api/v1/fx/rates/history?currency=EUR&months=3')->assertStatus(401);
});
