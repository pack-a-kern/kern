<?php

declare(strict_types=1);

namespace Kern\Investments\Tests\Integration\BvbImportTest;

use Illuminate\Support\Facades\Http;
use Kern\Investments\Enums\InstrumentKind;
use Kern\Investments\Enums\InstrumentStatus;
use Kern\Investments\Enums\Market;
use Kern\Investments\Enums\PayoutKind;
use Kern\Investments\Enums\Programme;
use Kern\Investments\Models\FinancialInstrument;
use Kern\Investments\Models\InstrumentPayout;
use Kern\Investments\Models\InstrumentPrice;
use Kern\Investments\Parsing\ParseFailure;
use Kern\Investments\Services\BvbImport;

function page(string $name): string
{
    return (string) file_get_contents(__DIR__ . "/../Fixtures/bvb/{$name}.html");
}

/**
 * BVB, answering with its real pages of 2026-11-09. The shares page is read from the container at each
 * request, so a test changes it with `sharesPage()` — a second `Http::fake()` would not replace the first.
 */
function bvb(?string $shares = null): void
{
    sharesPage($shares ?? page('shares'));

    Http::fake(static fn ($request) => Http::response(match ($request->url()) {
        'https://www.bvb.ro/shares' => app('test.bvb.shares'),
        'https://www.bvb.ro/bonds' => page('bonds'),
        'https://www.bvb.ro/dividends' => page('dividends'),
        default => '',
    }));
}

function sharesPage(string $html): void
{
    app()->instance('test.bvb.shares', $html);
}

/** R2707B as its order wrote it: a Fidelis issue BVB lists. */
function fidelis(): FinancialInstrument
{
    return FinancialInstrument::query()->create([
        'kind' => InstrumentKind::GOVERNMENT_BOND, 'programme' => Programme::FIDELIS, 'isin' => 'ROV4289T5QU6', 'symbol' => 'R2707B',
        'name' => 'FIDELIS R2707B', 'issuer_name' => 'Ministerul Finanțelor', 'currency' => 'RON', 'face_value' => '100', 'rate' => '8.25',
        'issue_date' => '2025-07-16', 'maturity_date' => '2027-07-16', 'market' => Market::BVB, 'status' => InstrumentStatus::ACTIVE,
    ]);
}

beforeEach(function (): void {
    $this->travelTo('2026-11-09 18:30:00');
});

it('writes the day’s shares, corporate and municipal bonds, and every price, under the day BVB prints', function (): void {
    bvb();
    $issue = fidelis();

    $lines = app(BvbImport::class)->run();

    $tlv = FinancialInstrument::query()->where('isin', 'ROTLVAACNOR1')->firstOrFail();
    $price = InstrumentPrice::query()->where('instrument_id', $tlv->id)->firstOrFail();

    expect(FinancialInstrument::query()->where('kind', InstrumentKind::SHARE)->count())->toBe(89)
        ->and(FinancialInstrument::query()->where('kind', InstrumentKind::CORPORATE_BOND)->count())->toBe(38)
        ->and(FinancialInstrument::query()->where('kind', InstrumentKind::MUNICIPAL_BOND)->count())->toBe(23)
        ->and($tlv->symbol)->toBe('TLV')
        ->and($price->trading_date->format('Y-m-d'))->toBe('2026-11-09')
        ->and($price->price)->toBe('37.0000')
        ->and(InstrumentPrice::query()->where('instrument_id', $issue->id)->value('price'))->toBe('100.9000')
        ->and($lines[1])->toContain('161 listed government bonds with no order behind them');
});

it('writes a government bond only when an order made it, and keeps the order’s terms', function (): void {
    bvb();
    $issue = fidelis();

    app(BvbImport::class)->run();

    expect(FinancialInstrument::query()->where('kind', InstrumentKind::GOVERNMENT_BOND)->count())->toBe(1)
        ->and($issue->refresh()->rate)->toBe('8.25')
        ->and($issue->listing_read_at)->not->toBeNull();
});

it('writes a dividend for a share it knows, as BVB announced it', function (): void {
    bvb();

    app(BvbImport::class)->run();

    $h2o = FinancialInstrument::query()->where('isin', 'RO4Q0Z5RO1B6')->firstOrFail();
    $dividend = InstrumentPayout::query()->where('instrument_id', $h2o->id)->where('payment_date', '2026-06-25')->firstOrFail();

    expect($dividend->kind)->toBe(PayoutKind::DIVIDEND)
        ->and($dividend->amount_per_unit)->toBe('7.348365')
        ->and($dividend->ex_date?->format('Y-m-d'))->toBe('2026-06-03')
        ->and($dividend->financial_year)->toBe(2025);
});

it('changes nothing read twice the same day', function (): void {
    bvb();
    fidelis();

    app(BvbImport::class)->run();
    $counts = [FinancialInstrument::query()->count(), InstrumentPrice::query()->count(), InstrumentPayout::query()->count()];
    app(BvbImport::class)->run();

    expect([FinancialInstrument::query()->count(), InstrumentPrice::query()->count(), InstrumentPayout::query()->count()])->toBe($counts);
});

it('marks a share gone from the list delisted, and keeps it and its prices', function (): void {
    bvb();
    app(BvbImport::class)->run();

    // The next day, Banca Transilvania's row is gone from the list.
    $this->travelTo('2026-11-10 18:30:00');
    sharesPage((string) preg_replace('#<tr>\s*<td>\s*<a href="[^"]*s=TLV">.*?</tr>#s', '', page('shares'), 1));
    $lines = app(BvbImport::class)->run();
    $tlv = FinancialInstrument::query()->where('isin', 'ROTLVAACNOR1')->firstOrFail();

    expect($tlv->status)->toBe(InstrumentStatus::DELISTED)
        ->and(InstrumentPrice::query()->where('instrument_id', $tlv->id)->count())->toBe(1)
        ->and($lines[2])->toContain('1 delisted');
});

it('writes nothing when a page does not read', function (): void {
    fidelis();
    bvb(str_replace('Pret (RON)', 'Price', page('shares')));

    expect(fn () => app(BvbImport::class)->run())->toThrow(ParseFailure::class, 'BVB shares: no table')
        ->and(FinancialInstrument::query()->count())->toBe(1)
        ->and(InstrumentPrice::query()->count())->toBe(0);
});
