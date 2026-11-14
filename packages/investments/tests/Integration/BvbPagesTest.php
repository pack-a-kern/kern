<?php

declare(strict_types=1);

namespace Kern\Investments\Tests\Integration\BvbPagesTest;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kern\Investments\Enums\InstrumentKind;
use Kern\Investments\Enums\InstrumentStatus;
use Kern\Investments\Enums\Market;
use Kern\Investments\Enums\Programme;
use Kern\Investments\Jobs\ReadBvbInstrument;
use Kern\Investments\Models\FinancialInstrument;
use Kern\Investments\Models\InstrumentPrice;
use Kern\Investments\Models\IssuerProfile;
use Kern\Investments\Services\BvbPages;
use Kern\Legislation\Models\SourceDocument;
use Kern\Legislation\Models\SourceDocumentVersion;
use Kern\Legislation\Repositories\OfficialSourceRepository;

/** A listed bond, made up but for the symbol BVB's fixture pages are of. */
function bond(string $symbol, array $values = []): FinancialInstrument
{
    return FinancialInstrument::query()->create([
        'kind' => InstrumentKind::GOVERNMENT_BOND, 'programme' => Programme::FIDELIS, 'isin' => 'RO' . str_pad($symbol, 10, '0'), 'symbol' => $symbol,
        'name' => "FIDELIS {$symbol}", 'issuer_name' => 'Example ministry', 'currency' => 'RON', 'market' => Market::BVB, 'status' => InstrumentStatus::ACTIVE,
        ...$values,
    ]);
}

function evidence(): string
{
    $document = SourceDocument::query()->create([
        'official_source_id' => app(OfficialSourceRepository::class)->idForCode('BVB'),
        'document_type' => 'BVB_INSTRUMENT',
        'external_identifier' => 'BVB instrument ' . Str::uuid7(),
        'canonical_url' => 'https://www.bvb.ro/instrument',
    ]);

    return SourceDocumentVersion::query()->create([
        'source_document_id' => $document->id, 'retrieved_at' => now(), 'content_sha256' => str_repeat('c', 64), 'raw_object_path' => 'x', 'size_bytes' => 1,
    ])->id;
}

function profile(string $symbol, string $readAt): void
{
    IssuerProfile::query()->create(['issuer_key' => $symbol, 'meetings' => [], 'shareholders' => [], 'read_at' => $readAt, 'evidence_id' => evidence()]);
}

beforeEach(function (): void {
    $this->travelTo('2026-11-13 19:00:00');
});

it('queues the listed instruments due a reading, one every so many seconds, and none other', function (): void {
    Queue::fake();
    $never = bond('R2801A');
    $stale = bond('R2802A', ['history_read_at' => now()->subMonth()]);
    $noHistory = bond('R2803A');
    bond('R2804A', ['history_read_at' => now()->subMonth()]);
    bond('R2805A', ['status' => InstrumentStatus::MATURED]);
    FinancialInstrument::query()->create(['kind' => InstrumentKind::GOVERNMENT_BOND, 'programme' => Programme::TEZAUR, 'issue_code' => '0001', 'name' => 'TEZAUR 0001', 'issuer_name' => 'Example ministry', 'currency' => 'RON', 'status' => InstrumentStatus::ACTIVE]);
    profile('R2802A', now()->subDays(8)->toDateTimeString());
    profile('R2803A', now()->subDay()->toDateTimeString());
    // Read by a person yesterday, its past prices too: not due for a week.
    profile('R2804A', now()->subDay()->toDateTimeString());

    expect(app(BvbPages::class)->queue())->toContain('3 instruments queued');

    $queued = [];
    Queue::assertPushed(ReadBvbInstrument::class, function (ReadBvbInstrument $job) use (&$queued): bool {
        $queued[$job->instrumentId] = $job->delay;

        return true;
    });
    expect(array_keys($queued))->toBe([$never->id, $stale->id, $noHistory->id])
        ->and(array_map(static fn ($delay): int => (int) round(now()->diffInSeconds($delay)), array_values($queued)))->toBe([0, 10, 20]);
});

it('reads an instrument’s past prices once and its page each time, keeping a day the import already priced', function (): void {
    $instrument = bond('R3608A');
    InstrumentPrice::query()->create(['instrument_id' => $instrument->id, 'trading_date' => '2026-11-13', 'price' => '98.5000', 'priced_at' => now(), 'evidence_id' => evidence()]);
    Http::fake([
        // The browser worker, answering with what the page's chart fetched — the address it came from beside it.
        'https://browser-worker.test/*' => Http::response((string) file_get_contents(__DIR__ . '/../Fixtures/bvb/history-bond.json'), 200, [
            'Content-Type' => 'application/json',
            'X-Source-Url' => 'https://wapi.bvb.ro/api/history?symbol=R3608A&rs=1D&currencyCode=RON',
        ]),
        'https://www.bvb.ro/*' => Http::response((string) file_get_contents(__DIR__ . '/../Fixtures/bvb/instrument-bond.html')),
    ]);

    app()->call([new ReadBvbInstrument($instrument->id), 'handle']);
    $this->travel(8)->days();
    app()->call([new ReadBvbInstrument($instrument->id), 'handle']);

    $prices = InstrumentPrice::query()->where('instrument_id', $instrument->id)->orderBy('trading_date')->get();

    expect($prices)->toHaveCount(53)
        ->and($prices->first()?->price)->toBe('100.5980')
        ->and($prices->first()?->change_percent)->toBeNull()
        ->and($prices[1]->change_percent)->toBe('0.25')
        ->and($prices->last()?->price)->toBe('98.5000')
        ->and($instrument->refresh()->history_read_at)->not->toBeNull()
        ->and(IssuerProfile::query()->sole()->yield_to_maturity)->toBe('6.75');
    Http::assertSentCount(3);
    // The page, its chart's six months, by day — and the Browser card's engine and name, not HeadlessChrome.
    Http::assertSent(static fn ($request): bool => $request->url() === 'https://browser-worker.test/v1/source'
        && $request['sequence'] === 'history'
        && $request['secrets'] === ['url' => 'https://www.bvb.ro/instrument?s=R3608A']
        && $request['playbook']['context'] === ['engine' => 'chromium', 'userAgent' => 'Mozilla/5.0 (Test) Chrome/128.0.0.0']);
});
