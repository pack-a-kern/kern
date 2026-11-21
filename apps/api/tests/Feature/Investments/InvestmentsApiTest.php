<?php

declare(strict_types=1);

namespace Tests\Feature\Investments\InvestmentsApiTest;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

it('lists the active market by name, each with its latest price, a numbered page at a time, counting each kind', function (): void {
    ['alpha' => $alpha] = market();
    $evidence = evidence();
    InstrumentPrice::query()->create(['instrument_id' => $alpha->id, 'trading_date' => '2026-11-06', 'price' => '10.0000', 'priced_at' => now(), 'evidence_id' => $evidence]);
    InstrumentPrice::query()->create(['instrument_id' => $alpha->id, 'trading_date' => '2026-11-09', 'price' => '11.5000', 'priced_at' => now(), 'evidence_id' => $evidence]);
    config(['kern.paging.sizes' => '2,25', 'kern.paging.default' => '25']);
    app()->forgetInstance(\Kern\Platform\Routing\PageSizes::class);

    $first = $this->actingAs($this->user)->getJson('/api/v1/investments/instruments?per_page=2')->assertOk();
    $second = $this->actingAs($this->user)->getJson('/api/v1/investments/instruments?per_page=2&page=2')->assertOk();

    expect(column($first->json('data'), 'name'))->toBe(['Alfa Ștefănești S.A.', 'Beta S.A.'])
        ->and($first->json('data.0.latestPrice'))->toBe(['price' => '11.5000', 'tradingDate' => '2026-11-09'])
        ->and([$first->json('total'), $first->json('page'), $first->json('perPage')])->toBe([3, 1, 2])
        ->and($first->json('counts'))->toEqual(['SHARE' => 2, 'GOVERNMENT_BOND' => 1])
        ->and($first->json('countAll'))->toBe(3)
        ->and(column($second->json('data'), 'name'))->toBe(['TEZAUR 0001']);
    $this->actingAs($this->user)->getJson('/api/v1/investments/instruments?per_page=7')->assertUnprocessable();
});

it('filters by kind, programme and status, and searches a code exactly or words of a name', function (): void {
    market();
    $names = fn (string $query): array => column($this->actingAs($this->user)->getJson("/api/v1/investments/instruments?{$query}")->assertOk()->json('data'), 'name');

    expect($this->actingAs($this->user)->getJson('/api/v1/investments/instruments?kind=SHARE')->json('counts'))
        ->toEqual(['SHARE' => 2, 'GOVERNMENT_BOND' => 1])
        ->and($names('kind=SHARE'))->toBe(['Alfa Ștefănești S.A.', 'Beta S.A.'])
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

it('shows an instrument with how its price moved, how far off it matures, and its next and last payment', function (): void {
    ['tezaur' => $tezaur, 'alpha' => $alpha] = market();
    $tezaur->forceFill(['maturity_date' => '2029-07-01'])->save();
    foreach (['2026-07-01' => PayoutKind::COUPON, '2027-07-01' => PayoutKind::COUPON, '2029-07-01' => PayoutKind::PRINCIPAL] as $paid => $kind) {
        InstrumentPayout::query()->create(['instrument_id' => $tezaur->id, 'kind' => $kind, 'payment_date' => $paid, 'rate' => '6.50', 'status' => PayoutStatus::ANNOUNCED, 'source' => PayoutSource::ORDER]);
    }
    InstrumentPrice::query()->create(['instrument_id' => $alpha->id, 'trading_date' => '2026-11-09', 'price' => '11.5000', 'change_percent' => '-1.25', 'priced_at' => now(), 'evidence_id' => evidence()]);

    $bond = $this->actingAs($this->user)->getJson("/api/v1/investments/instruments/{$tezaur->id}")->assertOk();
    $share = $this->actingAs($this->user)->getJson("/api/v1/investments/instruments/{$alpha->id}")->assertOk();

    // A bond's list is its coupons: its repayment is among its terms.
    expect($bond->json('issueCode'))->toBe('0001')
        ->and($bond->json('payoutsCount'))->toBe(2)
        ->and($bond->json('nextPayout.paymentDate'))->toBe('2027-07-01')
        ->and($bond->json('lastPayout.paymentDate'))->toBe('2026-07-01')
        ->and($bond->json('maturesIn'))->toBe(['value' => 3, 'unit' => 'year'])
        ->and($bond->json('latestChange'))->toBeNull()
        ->and($bond->json('termsDocumentId'))->toBe($tezaur->terms_document_id)
        // A Tezaur bond is not listed on BVB, so it has no page there; a BVB share does.
        ->and($bond->json('bvbUrl'))->toBeNull()
        ->and($bond->json())->not->toHaveKey('payouts')
        ->and($share->json('latestChange'))->toBe(['percent' => '-1.25', 'trend' => 'DOWN'])
        ->and($share->json('maturesIn'))->toBeNull()
        ->and($share->json('bvbUrl'))->toContain('s=ALFA');
    $this->actingAs($this->user)->getJson('/api/v1/investments/instruments/01900000-0000-7000-8000-000000000000')->assertNotFound();
});

it('lists a bond’s coupons numbered and a share’s every payment, in date order, when asked', function (): void {
    ['tezaur' => $tezaur, 'alpha' => $alpha] = market();
    foreach ([[PayoutKind::PRINCIPAL, '2028-07-01'], [PayoutKind::COUPON, '2027-07-01'], [PayoutKind::COUPON, '2028-07-01']] as [$kind, $paid]) {
        InstrumentPayout::query()->create(['instrument_id' => $tezaur->id, 'kind' => $kind, 'payment_date' => $paid, 'period_start' => '2026-07-01', 'period_end' => '2027-06-30', 'rate' => '6.50', 'status' => PayoutStatus::ANNOUNCED, 'source' => PayoutSource::ORDER]);
    }
    InstrumentPayout::query()->create(['instrument_id' => $alpha->id, 'kind' => PayoutKind::DIVIDEND, 'payment_date' => '2026-06-25', 'amount_per_unit' => '1.5', 'status' => PayoutStatus::PAID, 'source' => PayoutSource::BVB]);

    $coupons = $this->actingAs($this->user)->getJson("/api/v1/investments/instruments/{$tezaur->id}/payouts")->assertOk()->json('data');
    $dividends = $this->actingAs($this->user)->getJson("/api/v1/investments/instruments/{$alpha->id}/payouts")->assertOk()->json('data');

    expect(column($coupons, 'number'))->toBe([1, 2])
        ->and(column($coupons, 'paymentDate'))->toBe(['2027-07-01', '2028-07-01'])
        ->and(column($coupons, 'kind'))->toBe(['COUPON', 'COUPON'])
        ->and(column($coupons, 'periodStart'))->toBe(['2026-07-01', '2026-07-01'])
        ->and(column($dividends, 'number'))->toBe([null])
        ->and(column($dividends, 'amountPerUnit'))->toBe(['1.5']);
    $this->actingAs($this->user)->getJson('/api/v1/investments/instruments/01900000-0000-7000-8000-000000000000/payouts')->assertNotFound();
});

it('answers the prices of the last so many months, and refuses a span longer than configured', function (): void {
    ['alpha' => $alpha] = market();
    $evidence = evidence();
    InstrumentPrice::query()->create(['instrument_id' => $alpha->id, 'trading_date' => '2026-11-09', 'price' => '11.5000', 'priced_at' => now(), 'evidence_id' => $evidence]);
    InstrumentPrice::query()->create(['instrument_id' => $alpha->id, 'trading_date' => '2026-07-01', 'price' => '9.0000', 'priced_at' => now(), 'evidence_id' => $evidence]);
    $url = "/api/v1/investments/instruments/{$alpha->id}/prices";

    expect(column($this->actingAs($this->user)->getJson("{$url}?months=3")->assertOk()->json('data'), 'price'))->toBe(['11.5000'])
        ->and(column($this->actingAs($this->user)->getJson("{$url}?months=12")->assertOk()->json('data'), 'price'))->toBe(['9.0000', '11.5000']);
    $this->actingAs($this->user)->getJson("{$url}?months=120")->assertUnprocessable();
    $this->actingAs($this->user)->getJson("{$url}?months=0")->assertUnprocessable();
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

it('answers a stored profile without reaching out, its calendar latest first and marked, null for one never read', function (): void {
    market();
    Http::fake();
    IssuerProfile::query()->create([
        'issuer_key' => 'ALFA',
        'meetings' => [['date' => '2026-04-28', 'kind' => 'Adunarea Generala Ordinara'], ['date' => '2026-12-15', 'kind' => 'Adunarea Generala Extraordinara']],
        'shareholders' => [],
        'read_at' => now(),
        'evidence_id' => evidence(),
    ]);

    $stored = $this->actingAs($this->user)->getJson('/api/v1/investments/issuers/ALFA')->assertOk();
    // A profile read before its figures were kept has none of them, and no financial calendar.
    expect(column($stored->json('meetings'), 'date'))->toBe(['2026-12-15', '2026-04-28'])
        ->and(column($stored->json('meetings'), 'upcoming'))->toBe([true, false])
        ->and($stored->json('financialCalendar'))->toBe([])
        ->and($stored->json('figures.marketCap'))->toBeNull()
        ->and($stored->json('figures.couponDiffers'))->toBeFalse();
    // Never read: JSON's own null, not an empty object.
    expect($this->actingAs($this->user)->getJson('/api/v1/investments/issuers/BETA')->assertOk()->getContent())->toBe('null');
    $this->actingAs($this->user)->getJson('/api/v1/investments/issuers/MADEUP')->assertNotFound();
    $this->actingAs($this->user)->getJson('/api/v1/investments/issuers/bad;key')->assertNotFound();
    Http::assertNothingSent();
});

/** A share the market knows, under the symbol the fixture page is of. Made-up names. */
function knownShare(): void
{
    FinancialInstrument::query()->create(['kind' => InstrumentKind::SHARE, 'isin' => 'RO4Q0Z5RO1B6', 'symbol' => 'H2O', 'name' => 'Example energy S.A.', 'issuer_name' => 'Example energy S.A.', 'currency' => 'RON', 'market' => Market::BVB, 'status' => InstrumentStatus::ACTIVE]);
}

it('refreshes only a company the market knows, and fetches nothing for any other', function (): void {
    Http::fake();

    $this->actingAs($this->user)->postJson('/api/v1/investments/issuers/MADEUP/refresh')->assertNotFound();
    Http::assertNothingSent();
});

it('reads a profile from BVB when asked, and answers the stored one while it is young', function (): void {
    knownShare();
    $page = (string) file_get_contents(base_path('../../packages/investments/tests/Fixtures/bvb/instrument-share.html'));
    Http::fake(['https://www.bvb.ro/*' => Http::response($page)]);

    $read = $this->actingAs($this->user)->postJson('/api/v1/investments/issuers/H2O/refresh')->assertOk();
    $this->travel(2)->hours();
    $this->actingAs($this->user)->postJson('/api/v1/investments/issuers/H2O/refresh')->assertOk();

    expect($read->json('meetings'))->toHaveCount(5)
        ->and($read->json('shareholders.0.shares'))->toBe(360094390)
        ->and($read->json('shareholdersAsOf'))->toBe('2025-12-31')
        // Latest first, each still to come on 2026-11-09.
        ->and($read->json('financialCalendar'))->toBe([
            ['date' => '2026-11-17', 'what' => 'Teleconferinta – prezentare rezultate financiare trimestrul III', 'upcoming' => true],
            ['date' => '2026-11-16', 'what' => 'Rezultate financiare trimestrul III 2026', 'upcoming' => true],
        ])
        ->and($read->json('figures'))->toMatchArray([
            'marketCap' => '84382961569.20', 'priceEarnings' => '19.38', 'dividendYield' => '5.10',
            'lastDividend' => '9.571562', 'lastDividendYear' => 2025, 'week52High' => '221.0000',
            'sharesTotal' => 449802567, 'tradingSince' => '2023-07-12', 'category' => 'Premium',
        ]);
    // Two outbound requests on the first refresh: the page, then the logo it points at (served here as
    // the same HTML, so not kept). The second refresh is young, so it reaches out for neither.
    Http::assertSentCount(2);
});

it('reads a listed bond’s yield and issue from BVB, and never asks for a bond that is not listed', function (): void {
    FinancialInstrument::query()->create(['kind' => InstrumentKind::GOVERNMENT_BOND, 'programme' => Programme::FIDELIS, 'isin' => 'RO0000EXMPL1', 'symbol' => 'R2899A', 'name' => 'FIDELIS R2899A', 'issuer_name' => 'Example ministry', 'currency' => 'RON', 'rate' => '8.00', 'market' => Market::BVB, 'status' => InstrumentStatus::ACTIVE]);
    FinancialInstrument::query()->create(['kind' => InstrumentKind::GOVERNMENT_BOND, 'programme' => Programme::TEZAUR, 'issue_code' => '9999', 'symbol' => 'T9999', 'name' => 'TEZAUR 9999', 'issuer_name' => 'Example ministry', 'currency' => 'RON', 'status' => InstrumentStatus::ACTIVE]);
    Http::fake(['https://www.bvb.ro/*' => Http::response((string) file_get_contents(base_path('../../packages/investments/tests/Fixtures/bvb/instrument-bond.html')))]);

    $read = $this->actingAs($this->user)->postJson('/api/v1/investments/issuers/R2899A/refresh')->assertOk();
    $this->actingAs($this->user)->postJson('/api/v1/investments/issuers/T9999/refresh')->assertNotFound();

    expect($read->json('figures'))->toMatchArray([
        'bid' => '100.4500', 'ask' => '100.9999', 'couponRate' => '8.2500', 'yieldToMaturity' => '6.75',
        'unitsIssued' => 990835, 'issueValue' => '99083500', 'marketCap' => null,
        // Its terms say 8%, BVB 8.25%: said.
        'couponDiffers' => true,
    ])->and($read->json('shareholders'))->toBe([]);
    // The page, then the logo it points at (served here as the same HTML, so not kept).
    Http::assertSentCount(2);
});

it('caches the issuer logo the page points at, puts it on the market list, and serves it with a conditional 304', function (): void {
    Storage::fake('issuer_logos');
    knownShare();
    $page = (string) file_get_contents(base_path('../../packages/investments/tests/Fixtures/bvb/instrument-share.html'));
    // A 1×1 PNG — the smallest valid image, enough to prove the bytes are kept and served.
    $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC', true);
    Http::fake([
        '*Logo.ashx*' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        'https://www.bvb.ro/*' => Http::response($page),
    ]);

    $this->actingAs($this->user)->postJson('/api/v1/investments/issuers/H2O/refresh')->assertOk();

    $profile = IssuerProfile::query()->where('issuer_key', 'H2O')->sole();
    expect($profile->logo_path)->toBe('H2O.png')
        ->and($profile->logo_mime)->toBe('image/png')
        ->and($profile->logo_etag)->toBe(hash('sha256', $png));
    Storage::disk('issuer_logos')->assertExists('H2O.png');

    // The market list carries the logo's address on that instrument.
    $logos = column($this->actingAs($this->user)->getJson('/api/v1/investments/instruments?q=H2O')->assertOk()->json('data'), 'logoUrl');
    expect($logos)->toHaveCount(1)
        ->and($logos[0])->toContain('/investments/issuers/H2O/logo');

    // The controller serves the cached image, and answers a matching If-None-Match with 304.
    $etag = '"' . $profile->logo_etag . '"';
    $served = $this->actingAs($this->user)->get('/api/v1/investments/issuers/H2O/logo')->assertOk();
    expect($served->headers->get('Content-Type'))->toContain('image/png')
        ->and($served->headers->get('ETag'))->toBe($etag);
    $this->actingAs($this->user)->get('/api/v1/investments/issuers/H2O/logo', ['If-None-Match' => $etag])->assertStatus(304);
});

it('keeps the profile and shows initials when the issuer has no logo on file', function (): void {
    knownShare();

    // Never refreshed: no profile, so no logo to serve.
    $this->actingAs($this->user)->get('/api/v1/investments/issuers/H2O/logo')->assertNotFound();
    $logos = column($this->actingAs($this->user)->getJson('/api/v1/investments/instruments?q=H2O')->assertOk()->json('data'), 'logoUrl');
    expect($logos)->toBe([null]);
});

it('refuses a refresh past the person’s budget', function (): void {
    knownShare();
    Http::fake(['https://www.bvb.ro/*' => Http::response((string) file_get_contents(base_path('../../packages/investments/tests/Fixtures/bvb/instrument-share.html')))]);

    foreach (range(1, app(\Kern\Investments\Config\InvestmentsConfig::class)->api->refreshes[0]) as $ignored) {
        $this->actingAs($this->user)->postJson('/api/v1/investments/issuers/H2O/refresh')->assertOk();
    }

    $this->actingAs($this->user)->postJson('/api/v1/investments/issuers/H2O/refresh')->assertTooManyRequests();
});
