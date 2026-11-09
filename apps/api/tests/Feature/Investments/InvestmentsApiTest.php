<?php

declare(strict_types=1);

namespace Tests\Feature\Investments\InvestmentsApiTest;

use Illuminate\Support\Facades\Http;
use Kern\Investments\Enums\InstrumentKind;
use Kern\Investments\Enums\InstrumentStatus;
use Kern\Investments\Enums\Market;
use Kern\Investments\Enums\PayoutKind;
use Kern\Investments\Enums\PayoutSource;
use Kern\Investments\Enums\PayoutStatus;
use Kern\Investments\Enums\Programme;
use Kern\Investments\Models\FinancialInstrument;
use Kern\Investments\Models\InstrumentPayout;
use Kern\Investments\Models\InstrumentPrice;
use Kern\Investments\Models\IssuerProfile;
use Kern\Legislation\Models\SourceDocument;
use Kern\Legislation\Models\SourceDocumentVersion;
use Kern\Legislation\Repositories\OfficialSourceRepository;

/**
 * Every row's value at a key, of a list answered — `array_column` over a response that is a list of rows.
 *
 * @return list<mixed>
 */
function column(mixed $rows, string $key): array
{
    return array_column(is_array($rows) ? $rows : [], $key);
}

/**
 * Made-up market data: two listed shares, a Tezaur issue, one matured bond.
 *
 * @return array<string, FinancialInstrument>
 */
function market(): array
{
    $make = static fn (array $values): FinancialInstrument => FinancialInstrument::query()->create([
        'issuer_name' => 'Example issuer', 'currency' => 'RON', 'status' => InstrumentStatus::ACTIVE, ...$values,
    ]);

    return [
        'alpha' => $make(['kind' => InstrumentKind::SHARE, 'isin' => 'ROEXAMPLE001', 'symbol' => 'ALFA', 'name' => 'Alfa Ștefănești S.A.', 'market' => Market::BVB]),
        'beta' => $make(['kind' => InstrumentKind::SHARE, 'isin' => 'ROEXAMPLE002', 'symbol' => 'BETA', 'name' => 'Beta S.A.', 'market' => Market::BVB]),
        'tezaur' => $make(['kind' => InstrumentKind::GOVERNMENT_BOND, 'programme' => Programme::TEZAUR, 'issue_code' => '0001', 'name' => 'TEZAUR 0001', 'face_value' => '1', 'rate' => '6.50']),
        'old' => $make(['kind' => InstrumentKind::CORPORATE_BOND, 'isin' => 'ROEXAMPLE003', 'symbol' => 'OLD27', 'name' => 'Old bond', 'status' => InstrumentStatus::MATURED]),
    ];
}

function evidence(): string
{
    $document = SourceDocument::query()->create([
        'official_source_id' => app(OfficialSourceRepository::class)->idForCode('BVB'),
        'document_type' => 'BVB_SHARES',
        'external_identifier' => 'BVB shares',
        'canonical_url' => 'https://www.bvb.ro/shares',
    ]);

    return SourceDocumentVersion::query()->create([
        'source_document_id' => $document->id, 'retrieved_at' => now(), 'content_sha256' => str_repeat('b', 64), 'raw_object_path' => 'x', 'size_bytes' => 1,
    ])->id;
}

beforeEach(function (): void {
    $this->travelTo('2026-11-09 12:00:00');
    $this->user = ordinaryUser();
});

it('answers only someone signed in', function (): void {
    $this->getJson('/api/v1/investments/instruments')->assertUnauthorized();
    $this->getJson('/api/v1/investments/issuers/ALFA')->assertUnauthorized();
    $this->postJson('/api/v1/investments/issuers/ALFA/refresh')->assertUnauthorized();
});

it('lists the active market by name, each with its latest price, a page at a time', function (): void {
    ['alpha' => $alpha] = market();
    $evidence = evidence();
    InstrumentPrice::query()->create(['instrument_id' => $alpha->id, 'trading_date' => '2026-11-06', 'price' => '10.0000', 'priced_at' => now(), 'evidence_id' => $evidence]);
    InstrumentPrice::query()->create(['instrument_id' => $alpha->id, 'trading_date' => '2026-11-09', 'price' => '11.5000', 'priced_at' => now(), 'evidence_id' => $evidence]);
    config(['investments.api.page_size' => '2']);
    app()->forgetInstance(\Kern\Investments\Config\InvestmentsConfig::class);

    $first = $this->actingAs($this->user)->getJson('/api/v1/investments/instruments')->assertOk();
    $cursor = $first->json('nextCursor');
    expect($cursor)->toBeString();
    $second = $this->actingAs($this->user)->getJson('/api/v1/investments/instruments?cursor=' . (is_string($cursor) ? $cursor : ''))->assertOk();

    expect(column($first->json('data'), 'name'))->toBe(['Alfa Ștefănești S.A.', 'Beta S.A.'])
        ->and($first->json('data.0.latestPrice'))->toBe(['price' => '11.5000', 'tradingDate' => '2026-11-09'])
        ->and(column($second->json('data'), 'name'))->toBe(['TEZAUR 0001'])
        ->and($second->json('nextCursor'))->toBeNull();
});

it('filters by kind, programme and status, and searches a code exactly or words of a name', function (): void {
    market();
    $names = fn (string $query): array => column($this->actingAs($this->user)->getJson("/api/v1/investments/instruments?{$query}")->assertOk()->json('data'), 'name');

    expect($names('kind=SHARE'))->toBe(['Alfa Ștefănești S.A.', 'Beta S.A.'])
        ->and($names('programme=TEZAUR'))->toBe(['TEZAUR 0001'])
        ->and($names('status=MATURED'))->toBe(['Old bond'])
        ->and($names('q=beta'))->toBe(['Beta S.A.'])
        ->and($names('q=ROEXAMPLE001'))->toBe(['Alfa Ștefănești S.A.'])
        ->and($names('q=0001'))->toBe(['TEZAUR 0001'])
        // A wildcard typed is a character searched for, not every name.
        ->and($names('q=%25%25'))->toBe([]);
});

it('refuses a filter it does not know, and a search too short or too long', function (string $query): void {
    $this->actingAs($this->user)->getJson("/api/v1/investments/instruments?{$query}")->assertUnprocessable();
})->with(['kind=FUND', 'status=GONE', 'q=a', 'q=' . str_repeat('a', 65)]);

it('shows an instrument with every payout in date order', function (): void {
    ['tezaur' => $tezaur] = market();
    foreach (['2028-07-01' => PayoutKind::PRINCIPAL, '2027-07-01' => PayoutKind::COUPON] as $paid => $kind) {
        InstrumentPayout::query()->create(['instrument_id' => $tezaur->id, 'kind' => $kind, 'payment_date' => $paid, 'rate' => '6.50', 'status' => PayoutStatus::ANNOUNCED, 'source' => PayoutSource::ORDER]);
    }

    $shown = $this->actingAs($this->user)->getJson("/api/v1/investments/instruments/{$tezaur->id}")->assertOk();

    expect($shown->json('issueCode'))->toBe('0001')
        ->and(column($shown->json('payouts'), 'paymentDate'))->toBe(['2027-07-01', '2028-07-01'])
        ->and($shown->json('latestPrice'))->toBeNull();
    $this->actingAs($this->user)->getJson('/api/v1/investments/instruments/01900000-0000-7000-8000-000000000000')->assertNotFound();
});

it('answers a span of prices, and refuses one longer than configured or backwards', function (): void {
    ['alpha' => $alpha] = market();
    InstrumentPrice::query()->create(['instrument_id' => $alpha->id, 'trading_date' => '2026-11-09', 'price' => '11.5000', 'priced_at' => now(), 'evidence_id' => evidence()]);
    $url = "/api/v1/investments/instruments/{$alpha->id}/prices";

    expect($this->actingAs($this->user)->getJson("{$url}?from=2026-11-01&to=2026-11-30")->assertOk()->json('data.0.price'))->toBe('11.5000');
    $this->actingAs($this->user)->getJson("{$url}?from=2016-01-01&to=2026-11-30")->assertUnprocessable();
    $this->actingAs($this->user)->getJson("{$url}?from=2026-11-30&to=2026-11-01")->assertUnprocessable();
    $this->actingAs($this->user)->getJson($url)->assertUnprocessable();
});

it('lists upcoming payouts soonest first and recent ones latest first', function (): void {
    ['alpha' => $alpha] = market();
    foreach (['2026-06-25', '2026-12-01', '2027-01-15'] as $paid) {
        InstrumentPayout::query()->create(['instrument_id' => $alpha->id, 'kind' => PayoutKind::DIVIDEND, 'payment_date' => $paid, 'amount_per_unit' => '1.5', 'status' => PayoutStatus::ANNOUNCED, 'source' => PayoutSource::BVB]);
    }

    expect(column($this->actingAs($this->user)->getJson('/api/v1/investments/payouts/upcoming')->assertOk()->json('data'), 'paymentDate'))->toBe(['2026-12-01', '2027-01-15'])
        ->and(column($this->actingAs($this->user)->getJson('/api/v1/investments/payouts/recent?kind=DIVIDEND')->assertOk()->json('data'), 'paymentDate'))->toBe(['2026-06-25']);
    $this->actingAs($this->user)->getJson('/api/v1/investments/payouts/later')->assertNotFound();
});

it('answers a stored profile without reaching out, and 404 for one never read', function (): void {
    Http::fake();
    IssuerProfile::query()->create(['issuer_key' => 'ALFA', 'meetings' => [['date' => '2026-04-28', 'kind' => 'Adunarea Generala Ordinara']], 'shareholders' => [], 'read_at' => now(), 'evidence_id' => evidence()]);

    expect($this->actingAs($this->user)->getJson('/api/v1/investments/issuers/ALFA')->assertOk()->json('meetings.0.kind'))->toBe('Adunarea Generala Ordinara');
    $this->actingAs($this->user)->getJson('/api/v1/investments/issuers/BETA')->assertNotFound();
    $this->actingAs($this->user)->getJson('/api/v1/investments/issuers/bad;key')->assertNotFound();
    Http::assertNothingSent();
});

it('reads a profile from BVB when asked, and answers the stored one while it is young', function (): void {
    $page = (string) file_get_contents(base_path('../../packages/investments/tests/Fixtures/bvb/instrument-share.html'));
    Http::fake(['https://www.bvb.ro/*' => Http::response($page)]);

    $read = $this->actingAs($this->user)->postJson('/api/v1/investments/issuers/H2O/refresh')->assertOk();
    $this->travel(2)->hours();
    $this->actingAs($this->user)->postJson('/api/v1/investments/issuers/H2O/refresh')->assertOk();

    expect($read->json('meetings'))->toHaveCount(5)
        ->and($read->json('shareholders.0.shares'))->toBe(360094390)
        ->and($read->json('shareholdersAsOf'))->toBe('2025-12-31');
    Http::assertSentCount(1);
});

it('refuses a refresh past the person’s budget', function (): void {
    Http::fake(['https://www.bvb.ro/*' => Http::response((string) file_get_contents(base_path('../../packages/investments/tests/Fixtures/bvb/instrument-share.html')))]);

    foreach (range(1, app(\Kern\Investments\Config\InvestmentsConfig::class)->api->refreshes[0]) as $ignored) {
        $this->actingAs($this->user)->postJson('/api/v1/investments/issuers/H2O/refresh')->assertOk();
    }

    $this->actingAs($this->user)->postJson('/api/v1/investments/issuers/H2O/refresh')->assertTooManyRequests();
});
