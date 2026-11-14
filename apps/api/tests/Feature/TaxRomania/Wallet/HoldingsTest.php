<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Wallet\HoldingsTest;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
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
use Kern\Legislation\Database\Factories\SourceDocumentVersionFactory;
use Kern\Legislation\Models\SourceSection;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Tenancy\TenantScope;
use Kern\TaxRomania\FiscalParameters\Enums\FiscalParameterValueType;
use Kern\TaxRomania\FiscalParameters\Models\FiscalParameter;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\Wallet\Enums\AccountKind;
use Kern\TaxRomania\Wallet\Enums\RecordSource;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;
use Kern\TaxRomania\Wallet\Models\FinancialTransaction;
use Kern\TaxRomania\Wallet\Models\InvestmentLot;
use Kern\TaxRomania\Wallet\Models\InvestmentLotPayout;
use Kern\TaxRomania\Wallet\Models\TransactionSplit;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Holdings in an investments account (ADR 0025 §9): a lot added with its instrument's terms, found again
 * by its broker's reference, sold first in first out, a Tezaur refused, valued from the latest price —
 * and none of it reachable from another taxpayer. Every instrument here is made up.
 */

/** @return array{0: User, 1: TaxSubject} */
function owner(): array
{
    $user = User::factory()->create();
    $subject = TaxSubject::factory()->create();

    SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::OWNER,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    return [$user, $subject];
}

/** The money account the taxpayer's holdings opened — the only one of its kind in these tests. */
function heldAccount(TaxSubject $subject): string
{
    return FinancialAccount::query()->withoutGlobalScope(TenantScope::class)
        ->where('tax_subject_id', $subject->id)
        ->where('kind', AccountKind::INVESTMENTS->value)
        ->sole()
        ->id;
}

/** @param array<string, mixed> $overrides */
function instrument(array $overrides = []): FinancialInstrument
{
    return FinancialInstrument::query()->create([
        'kind' => InstrumentKind::SHARE,
        'symbol' => 'EXMPL',
        'isin' => 'ROEXMPL00001',
        'name' => 'Exemplu SA',
        'issuer_name' => 'Exemplu SA',
        'currency' => 'RON',
        'market' => Market::BVB,
        'status' => InstrumentStatus::ACTIVE,
        ...$overrides,
    ]);
}

/** @param array<string, mixed> $overrides */
function fidelis(array $overrides = []): FinancialInstrument
{
    return instrument([
        'kind' => InstrumentKind::GOVERNMENT_BOND,
        'programme' => Programme::FIDELIS,
        'symbol' => 'R2899T',
        'isin' => 'ROTEST000001',
        'name' => 'FIDELIS R2899T',
        'issuer_name' => 'Emitent de test',
        'face_value' => '100',
        'rate' => '7.00',
        'issue_date' => '2026-01-05',
        'maturity_date' => '2031-01-05',
        ...$overrides,
    ]);
}

function tezaur(): FinancialInstrument
{
    return instrument([
        'kind' => InstrumentKind::GOVERNMENT_BOND,
        'programme' => Programme::TEZAUR,
        'symbol' => null,
        'isin' => null,
        'market' => null,
        'issue_code' => '9999',
        'name' => 'TEZAUR 9999',
        'issuer_name' => 'Emitent de test',
        'face_value' => '1',
        'rate' => '7.00',
        'issue_date' => '2026-01-10',
        'maturity_date' => '2029-01-10',
    ]);
}

function price(FinancialInstrument $instrument, string $price, string $on = '2026-11-06'): void
{
    InstrumentPrice::query()->create([
        'instrument_id' => $instrument->id,
        'trading_date' => $on,
        'price' => $price,
        'priced_at' => "{$on} 15:45:00",
        'evidence_id' => SourceDocumentVersionFactory::new()->createOne()->id,
    ]);
}

/**
 * @param array<string, mixed> $overrides
 *
 * @return TestResponse<Response>
 */
function buy(TestCase $case, User $user, TaxSubject $subject, FinancialInstrument $instrument, array $overrides = []): TestResponse
{
    return $case->actingAs($user)->postJson("/api/v1/subjects/{$subject->id}/wallet/holdings", [
        'instrument_id' => $instrument->id,
        'units' => '10',
        'price_per_unit' => '100',
        'bought_on' => '2026-02-01',
        'fees_minor' => 0,
        'held_at' => 'BROKER',
        ...$overrides,
    ]);
}

/** @return array<string, array<array-key, mixed>> every lot the API answers, open and sold, by lot id */
function holdings(TestCase $case, User $user, TaxSubject $subject): array
{
    $byId = [];

    foreach (['open', 'sold'] as $state) {
        $positions = $case->actingAs($user)->getJson("/api/v1/subjects/{$subject->id}/wallet/holdings?state={$state}&per_page=100")->assertOk()->json('positions');

        foreach (is_array($positions) ? $positions : [] as $position) {
            foreach (is_array($position) && is_array($position['lots'] ?? null) ? $position['lots'] : [] as $line) {
                if (is_array($line) && is_string($line['id'] ?? null)) {
                    $byId[$line['id']] = $line;
                }
            }
        }
    }

    return $byId;
}

/** @param TestResponse<Response> $added */
function lotId(TestResponse $added): string
{
    $id = $added->json('id');

    return is_string($id) ? $id : '';
}

/**
 * @param TestResponse<Response> $sale
 *
 * @return list<string> the lots the sale closed, oldest purchase first
 */
function soldIds(TestResponse $sale): array
{
    $ids = $sale->json('ids');

    return array_values(array_filter(is_array($ids) ? $ids : [], is_string(...)));
}

// A sale asks again for the password or code (`verify-recent`); these tests are past that.
beforeEach(function (): void {
    verifySession();
});

it('adds a lot with its instrument’s terms, and refuses an instrument no longer on the market', function (): void {
    [$user, $subject] = owner();
    $euro = fidelis(['currency' => 'EUR']);
    $matured = instrument(['symbol' => 'GONE', 'isin' => 'ROGONE000001', 'status' => InstrumentStatus::MATURED]);

    $id = lotId(buy($this, $user, $subject, $euro, ['units' => '12.5', 'fees_minor' => 250])->assertCreated());

    buy($this, $user, $subject, $matured)
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'INSTRUMENT_NOT_OFFERED');

    $line = holdings($this, $user, $subject)[$id];
    expect($line['currency'])->toBe('EUR')
        ->and($line['instrumentName'])->toBe('FIDELIS R2899T')
        ->and($line['units'])->toBe('12.5')
        ->and($line['costMinor'])->toBe(125_250);
});

it('answers one position per share per place, its purchases under it, added up exactly, a page at a time', function (): void {
    [$user, $subject] = owner();
    $share = instrument();
    $other = instrument(['symbol' => 'OTHR', 'isin' => 'ROOTHR000001', 'name' => 'Alt SA', 'issuer_name' => 'Alt SA']);
    price($share, '120');
    buy($this, $user, $subject, $share, ['units' => '10', 'price_per_unit' => '100', 'fees_minor' => 500, 'bought_on' => '2025-03-01'])->assertCreated();
    buy($this, $user, $subject, $share, ['units' => '5.5', 'price_per_unit' => '110', 'bought_on' => '2026-01-10'])->assertCreated();
    buy($this, $user, $subject, $other, ['bought_on' => '2026-02-01'])->assertCreated();
    $url = "/api/v1/subjects/{$subject->id}/wallet/holdings";

    $first = $this->actingAs($user)->getJson("{$url}?state=open&per_page=10")->assertOk();
    /** @var array{instrumentId: string, lots: list<mixed>, units: string, costMinor: int, averagePrice: string, valueMinor: int, resultMinor: int, resultPercent: string, firstBoughtOn: string, receivedMinor: int} $position */
    $position = $first->json('positions.0');

    expect($first->json('total'))->toBe(2)
        ->and($first->json('positions'))->toHaveCount(2)
        ->and($position['instrumentId'])->toBe($share->id)
        ->and($position['lots'])->toHaveCount(2)
        ->and($position['units'])->toBe('15.500000')
        // 10 × 100 + 5 fees + 5.5 × 110 = 1610 RON, over 15.5 units.
        ->and($position['costMinor'])->toBe(161000)
        ->and($position['averagePrice'])->toBe('103.8709')
        // 15.5 × 120 = 1860 RON: 250 more than it cost.
        ->and([$position['valueMinor'], $position['resultMinor'], $position['resultPercent']])->toBe([186000, 25000, '15.52'])
        ->and($position['firstBoughtOn'])->toBe('2025-03-01')
        ->and($position['receivedMinor'])->toBe(0);
    expect($this->actingAs($user)->getJson("{$url}?state=open&per_page=10&page=2")->assertOk()->json('positions'))->toBe([])
        ->and($this->actingAs($user)->getJson("{$url}?state=sold")->assertOk()->json('total'))->toBe(0);
    $this->actingAs($user)->getJson($url)->assertUnprocessable();
    $this->actingAs($user)->getJson("{$url}?state=all")->assertUnprocessable();
});

it('refuses a malformed number of units', function (): void {
    [$user, $subject] = owner();
    $share = instrument();

    foreach (['0', '1e3', '1,5', '-2'] as $units) {
        buy($this, $user, $subject, $share, ['units' => $units])->assertUnprocessable()->assertJsonValidationErrors('units');
    }
});

it('keeps a broker’s reference encrypted and adopts the lot by it, never adding it twice', function (): void {
    [$user, $subject] = owner();
    $share = instrument();

    $first = lotId(buy($this, $user, $subject, $share, ['external_ref' => 'POS-778899'])->assertCreated());
    $again = lotId(buy($this, $user, $subject, $share, ['external_ref' => 'POS-778899', 'units' => '99'])->assertCreated());

    $row = DB::table('investment_lots')->where('id', $first)->first();

    expect($again)->toBe($first)
        ->and(DB::table('investment_lots')->where('tax_subject_id', $subject->id)->count())->toBe(1)
        ->and($row?->units)->toBe('10')
        ->and(json_encode($row, JSON_THROW_ON_ERROR))->not->toContain('POS-778899')
        ->and($row?->external_ref_blind_index)->toBeString();

    // The same reference at another place is another position.
    buy($this, $user, $subject, $share, ['external_ref' => 'POS-778899', 'held_at' => 'BANK'])->assertCreated();
    expect(DB::table('investment_lots')->where('tax_subject_id', $subject->id)->count())->toBe(2);
});

it('never shows, adds to or sells another taxpayer’s holding', function (): void {
    [$alice, $hers] = owner();
    [$bob, $his] = owner();
    $share = instrument();
    buy($this, $alice, $hers, $share)->assertCreated();
    $account = heldAccount($hers);

    expect(holdings($this, $bob, $his))->toBe([]);

    // His own purchase opens his own account, never hers.
    buy($this, $bob, $his, $share)->assertCreated();
    expect(heldAccount($his))->not->toBe($account);
    $this->actingAs($bob)->postJson("/api/v1/subjects/{$his->id}/wallet/holdings/sales", [
        'account_id' => $account,
        'instrument_id' => $share->id,
        'units' => '1',
        'price_per_unit' => '1',
        'sold_on' => '2026-03-01',
        'fees_minor' => 0,
    ])->assertNotFound();
    $this->actingAs($bob)->getJson("/api/v1/subjects/{$hers->id}/wallet/holdings?state=open")->assertForbidden();

    expect(InvestmentLot::query()->withoutGlobalScope(TenantScope::class)->whereNotNull('sold_on')->count())->toBe(0);
});

/** The securities gain's rates and holding period, approved — as the law states them, 2026. */
function approvedSecuritiesRates(): void
{
    foreach ([
        'RO.INVESTMENTS.SECURITIES_GAIN_RATE_LONG' => [FiscalParameterValueType::DECIMAL, ['value' => '0.03']],
        'RO.INVESTMENTS.SECURITIES_GAIN_RATE_SHORT' => [FiscalParameterValueType::DECIMAL, ['value' => '0.06']],
        'RO.INVESTMENTS.SECURITIES_HOLDING_DAYS' => [FiscalParameterValueType::JSON, ['days' => 365]],
    ] as $code => [$type, $value]) {
        insertApprovedFiscalParameterVersion(
            FiscalParameter::factory()->create(['code' => $code, 'value_type' => $type->value])->id,
            SourceSection::factory()->create()->id,
            ['valid_from' => '2026-01-01', 'value_json' => json_encode($value, JSON_THROW_ON_ERROR)],
        );
    }
}

/** @return array<string, int> the sale movement's splits, by category */
function saleSplits(string $account): array
{
    $sale = FinancialTransaction::query()->withoutGlobalScope(TenantScope::class)->where('account_id', $account)->sole();

    $splits = [];

    foreach (TransactionSplit::query()->withoutGlobalScope(TenantScope::class)->where('transaction_id', $sale->id)->get() as $split) {
        $splits[$split->category_code] = $split->amount_minor;
    }

    return $splits;
}

it('sells first in, first out, closing the oldest lot and splitting the next', function (): void {
    approvedSecuritiesRates();
    [$user, $subject] = owner();
    $share = instrument();
    $older = lotId(buy($this, $user, $subject, $share, ['units' => '10', 'price_per_unit' => '100', 'bought_on' => '2026-01-10', 'fees_minor' => 1_000]));
    $account = heldAccount($subject);
    $newer = lotId(buy($this, $user, $subject, $share, ['units' => '10', 'price_per_unit' => '110', 'bought_on' => '2026-02-10', 'fees_minor' => 500]));
    $sale = fn (string $units) => $this->actingAs($user)->postJson("/api/v1/subjects/{$subject->id}/wallet/holdings/sales", [
        'account_id' => $account,
        'instrument_id' => $share->id,
        'units' => $units,
        'price_per_unit' => '120',
        'sold_on' => '2026-06-01',
        'fees_minor' => 300,
    ]);

    $sale('21')->assertUnprocessable()->assertJsonPath('reason', 'NOT_ENOUGH_UNITS');
    $sold = soldIds($sale('15')->assertCreated());

    $lines = holdings($this, $user, $subject);
    $split = $lines[$sold[1]];

    expect($sold)->toHaveCount(2)
        ->and($sold[0])->toBe($older)
        // Whole: 10 units bought for 1.000 lei + 10 lei fees, sold for 1.200 lei less 2 lei of the 3.
        ->and($lines[$older]['soldOn'])->toBe('2026-06-01')
        ->and($lines[$older]['saleFeesMinor'])->toBe(200)
        ->and($lines[$older]['proceedsMinor'])->toBe(119_800)
        ->and($lines[$older]['resultMinor'])->toBe(18_800)
        // In part: the 5 units sold close in a row of their own, with half the purchase fees.
        ->and($split['units'])->toBe('5')
        ->and($split['feesMinor'])->toBe(250)
        ->and($split['saleFeesMinor'])->toBe(100)
        ->and($split['resultMinor'])->toBe(59_900 - 55_250)
        ->and($lines[$newer]['units'])->toBe('5')
        ->and($lines[$newer]['feesMinor'])->toBe(250)
        ->and($lines[$newer]['soldOn'])->toBeNull()
        // What arrived: 15 × 120 lei, less the fees, less what the intermediary withheld on the gain.
        // The units cost the weighted average of both lots, fees in (art. 97 (8^2)): 2.115 lei for 20, so
        // 1.586,25 for 15; the gain 210,75; both lots held under 365 days, 6%: 84,30 + 42,15 → 12,65 withheld.
        ->and(FinancialTransaction::query()->withoutGlobalScope(TenantScope::class)->where('account_id', $account)->sole()->amount_minor)->toBe(178_435)
        ->and(saleSplits($account))->toBe(['TRANSFER' => 158_625, 'SECURITIES_GAIN' => 19_810])
        ->and(InvestmentLot::query()->withoutGlobalScope(TenantScope::class)->whereIn('id', $sold)->pluck('sale_tax_withheld_minor')->all())->toEqualCanonicalizing([843, 422]);
});

it('withholds the long rate on a lot held the holding period, the short rate on one that was not', function (): void {
    approvedSecuritiesRates();
    [$user, $subject] = owner();
    $share = instrument();
    buy($this, $user, $subject, $share, ['units' => '10', 'price_per_unit' => '100', 'bought_on' => '2025-01-10']);
    $account = heldAccount($subject);
    buy($this, $user, $subject, $share, ['units' => '10', 'price_per_unit' => '100', 'bought_on' => '2026-03-10']);

    $sold = soldIds($this->actingAs($user)->postJson("/api/v1/subjects/{$subject->id}/wallet/holdings/sales", [
        'account_id' => $account, 'instrument_id' => $share->id, 'units' => '20', 'price_per_unit' => '110', 'sold_on' => '2026-06-01', 'fees_minor' => 0,
    ])->assertCreated());

    // 200 lei gained, 100 a lot: 3 on the one held over a year, 6 on the other.
    expect(InvestmentLot::query()->withoutGlobalScope(TenantScope::class)->whereIn('id', $sold)->orderBy('bought_on')->pluck('sale_tax_withheld_minor')->all())->toBe([300, 600]);
});

it('refuses a gain on a share while no rate is approved, and writes nothing', function (): void {
    [$user, $subject] = owner();
    $share = instrument();
    buy($this, $user, $subject, $share, ['units' => '10', 'price_per_unit' => '100', 'bought_on' => '2026-01-10']);
    $account = heldAccount($subject);

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subject->id}/wallet/holdings/sales", [
        'account_id' => $account, 'instrument_id' => $share->id, 'units' => '10', 'price_per_unit' => '120', 'sold_on' => '2026-06-01', 'fees_minor' => 0,
    ])->assertUnprocessable()->assertJsonPath('reason', 'NO_APPROVED_SECURITIES_RATE');

    expect(FinancialTransaction::query()->withoutGlobalScope(TenantScope::class)->where('account_id', $account)->exists())->toBeFalse()
        ->and(InvestmentLot::query()->withoutGlobalScope(TenantScope::class)->whereNotNull('sold_on')->exists())->toBeFalse();
});

it('withholds nothing on a public-debt gain, filed as such, and nothing on a loss', function (): void {
    [$user, $subject] = owner();
    $bond = fidelis();
    $share = instrument();
    buy($this, $user, $subject, $bond, ['units' => '10', 'price_per_unit' => '100', 'bought_on' => '2026-01-10']);
    $account = heldAccount($subject);
    buy($this, $user, $subject, $share, ['units' => '10', 'price_per_unit' => '100', 'bought_on' => '2026-01-10']);
    $sell = fn (FinancialInstrument $instrument, string $price) => $this->actingAs($user)->postJson("/api/v1/subjects/{$subject->id}/wallet/holdings/sales", [
        'account_id' => $account, 'instrument_id' => $instrument->id, 'units' => '10', 'price_per_unit' => $price, 'sold_on' => '2026-06-01', 'fees_minor' => 0,
    ])->assertCreated();

    $sell($bond, '102');
    $sell($share, '90');

    $splits = TransactionSplit::query()->withoutGlobalScope(TenantScope::class)->pluck('amount_minor', 'category_code')->all();

    expect($splits['PUBLIC_DEBT_INCOME'] ?? null)->toBe(2_000)
        ->and($splits)->not->toHaveKey('SECURITIES_GAIN')
        ->and(InvestmentLot::query()->withoutGlobalScope(TenantScope::class)->whereNotNull('sale_tax_withheld_minor')->exists())->toBeFalse();
});

it('refuses to sell a Tezaur bond early: it ends at maturity', function (): void {
    [$user, $subject] = owner();
    $tezaur = tezaur();
    $lot = lotId(buy($this, $user, $subject, $tezaur, ['units' => '5000', 'price_per_unit' => '1', 'held_at' => 'TREASURY']));
    $account = heldAccount($subject);

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subject->id}/wallet/holdings/sales", [
        'account_id' => $account,
        'instrument_id' => $tezaur->id,
        'units' => '1000',
        'price_per_unit' => '1',
        'sold_on' => '2026-06-01',
        'fees_minor' => 0,
    ])->assertUnprocessable()->assertJsonPath('reason', 'TEZAUR_ENDS_AT_MATURITY');

    expect(holdings($this, $user, $subject)[$lot]['soldOn'])->toBeNull();
});

it('values each kind as its source prices it, and a bond not priced at its face value only when it is the state’s', function (): void {
    [$user, $subject] = owner();
    $share = instrument();
    $listed = fidelis();
    $notYet = fidelis(['symbol' => 'R3099T', 'isin' => 'ROTEST000002', 'name' => 'FIDELIS R3099T', 'status' => InstrumentStatus::ON_OFFER]);
    $tezaur = tezaur();
    $corporate = instrument(['kind' => InstrumentKind::CORPORATE_BOND, 'symbol' => 'EXB30', 'isin' => 'ROEXB3000001', 'face_value' => '100', 'maturity_date' => '2030-06-30']);
    $municipal = instrument(['kind' => InstrumentKind::MUNICIPAL_BOND, 'symbol' => 'MUN30', 'isin' => 'ROMUN3000001', 'face_value' => '100', 'maturity_date' => '2030-06-30']);
    price($share, '25.5');
    price($share, '24', '2026-11-05');
    price($listed, '101.5');
    price($corporate, '98');

    $id = fn (FinancialInstrument $instrument, array $overrides = []): string => lotId(buy($this, $user, $subject, $instrument, $overrides)->assertCreated());
    $lines = [
        'share' => $id($share),
        'listed' => $id($listed),
        'notYet' => $id($notYet),
        'tezaur' => $id($tezaur, ['units' => '1000', 'price_per_unit' => '1', 'held_at' => 'TREASURY']),
        'corporate' => $id($corporate, ['units' => '5']),
        'municipal' => $id($municipal),
    ];
    $answered = holdings($this, $user, $subject);
    $value = static fn (string $key): array => [$answered[$lines[$key]]['valueMinor'], $answered[$lines[$key]]['valuationBasis']];

    expect($value('share'))->toBe([25_500, 'MARKET_PRICE'])                 // 10 × 25,50 lei, the latest day
        ->and($answered[$lines['share']]['valuedOn'])->toBe('2026-11-06')
        ->and($value('listed'))->toBe([101_500, 'MARKET_PRICE'])            // 10 × 100 lei × 101,5 %
        ->and($value('notYet'))->toBe([100_000, 'FACE_VALUE'])              // not trading yet: 10 × 100 lei
        ->and($value('tezaur'))->toBe([100_000, 'FACE_VALUE'])              // never listed: 1.000 × 1 leu
        ->and($value('corporate'))->toBe([49_000, 'MARKET_PRICE'])          // 5 × 100 lei × 98 %
        ->and($value('municipal'))->toBe([null, null]);                     // not priced, and not the state's
});

it('refuses a purchase or a sale past the largest a holding can be, and fees larger than a sale', function (): void {
    approvedSecuritiesRates();
    [$user, $subject] = owner();
    $share = instrument();

    buy($this, $user, $subject, $share, ['units' => '999999999999', 'price_per_unit' => '999999999999'])
        ->assertUnprocessable()->assertJsonPath('reason', 'AMOUNT_TOO_LARGE');
    buy($this, $user, $subject, $share, ['units' => '10', 'price_per_unit' => '100', 'bought_on' => '2026-01-10'])->assertCreated();
    $account = heldAccount($subject);

    $sell = fn (array $sale) => $this->actingAs($user)->postJson("/api/v1/subjects/{$subject->id}/wallet/holdings/sales", [
        'account_id' => $account, 'instrument_id' => $share->id, 'units' => '10', 'price_per_unit' => '100', 'sold_on' => '2026-06-01', 'fees_minor' => 0, ...$sale,
    ]);

    $sell(['fees_minor' => 100_001])->assertUnprocessable()->assertJsonPath('reason', 'FEES_EXCEED_PROCEEDS');
    $sell(['fees_minor' => PHP_INT_MAX])->assertUnprocessable()->assertJsonPath('reason', 'AMOUNT_TOO_LARGE');
    expect(FinancialTransaction::query()->withoutGlobalScope(TenantScope::class)->where('account_id', $account)->exists())->toBeFalse();
});

it('refuses a broker reference that is another holding’s', function (): void {
    [$user, $subject] = owner();
    $share = instrument();
    $reference = ['external_ref' => 'EXAMPLE-REF-1'];

    $first = lotId(buy($this, $user, $subject, $share, $reference)->assertCreated());

    expect(lotId(buy($this, $user, $subject, $share, $reference)->assertCreated()))->toBe($first);
    buy($this, $user, $subject, instrument(['symbol' => 'OTHR', 'isin' => 'ROOTHR000001']), $reference)->assertUnprocessable()->assertJsonPath('reason', 'REFERENCE_IN_USE');
});

it('refuses a sale dated before a payment the units were already credited', function (): void {
    [$user, $subject] = owner();
    $bond = fidelis();
    $lot = lotId(buy($this, $user, $subject, $bond, ['units' => '10', 'price_per_unit' => '100', 'bought_on' => '2026-01-10']));
    $account = heldAccount($subject);
    $coupon = InstrumentPayout::query()->create([
        'instrument_id' => $bond->id, 'kind' => PayoutKind::COUPON, 'payment_date' => '2026-05-10', 'amount_per_unit' => '7', 'rate' => '7.00',
        'status' => PayoutStatus::PAID, 'source' => PayoutSource::ORDER,
    ]);
    InvestmentLotPayout::query()->withoutGlobalScope(TenantScope::class)->create([
        'tax_subject_id' => $subject->id, 'lot_id' => $lot, 'payout_id' => $coupon->id, 'kind' => PayoutKind::COUPON,
        'paid_on' => '2026-05-10', 'currency' => 'RON', 'gross_minor' => 7_000, 'net_minor' => 7_000,
    ]);

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subject->id}/wallet/holdings/sales", [
        'account_id' => $account, 'instrument_id' => $bond->id, 'units' => '10', 'price_per_unit' => '101', 'sold_on' => '2026-05-01', 'fees_minor' => 0,
    ])->assertUnprocessable()->assertJsonPath('reason', 'SOLD_BEFORE_A_CREDITED_PAYMENT');
});

it('asks again for the password or code before a sale', function (): void {
    [$user, $subject] = owner();
    $share = instrument();
    buy($this, $user, $subject, $share, ['units' => '10', 'price_per_unit' => '100', 'bought_on' => '2026-01-10'])->assertCreated();
    $account = heldAccount($subject);
    withAuthenticator($user);
    forgetVerification();

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subject->id}/wallet/holdings/sales", [
        'account_id' => $account, 'instrument_id' => $share->id, 'units' => '10', 'price_per_unit' => '100', 'sold_on' => '2026-06-01', 'fees_minor' => 0,
    ])->assertForbidden();
    expect(InvestmentLot::query()->withoutGlobalScope(TenantScope::class)->whereNotNull('sold_on')->exists())->toBeFalse();
});

it('opens the money account itself, one per place and currency, and finds it again', function (): void {
    [$user, $subject] = owner();
    $share = instrument();
    $euro = instrument(['symbol' => 'EURX', 'isin' => 'ROEURX000001', 'currency' => 'EUR']);

    buy($this, $user, $subject, $share)->assertCreated();
    buy($this, $user, $subject, $share, ['units' => '3'])->assertCreated();
    expect(heldAccounts($subject))->toHaveCount(1);

    buy($this, $user, $subject, $share, ['held_at' => 'BANK'])->assertCreated();
    buy($this, $user, $subject, $euro)->assertCreated();

    $accounts = heldAccounts($subject);
    expect($accounts)->toHaveCount(3)
        ->and($accounts->map(fn (FinancialAccount $account): array => [$account->held_at?->value, $account->currency_code, $account->display_name_ciphertext])->sort()->values()->all())
        ->toBe([['BANK', 'RON', 'Bank · RON'], ['BROKER', 'EUR', 'Broker · EUR'], ['BROKER', 'RON', 'Broker · RON']])
        ->and(DB::table('audit_events')->where('event_type', 'WalletAccountOpened')->whereIn('resource_id', $accounts->pluck('id'))->count())->toBe(3);

    [$other, $theirs] = owner();
    buy($this, $other, $theirs, $share)->assertCreated();
    expect(heldAccounts($theirs))->toHaveCount(1)->and(heldAccounts($subject))->toHaveCount(3);
});

it('refuses a second automatic account for the same place and currency in the database', function (): void {
    [$user, $subject] = owner();
    buy($this, $user, $subject, instrument())->assertCreated();
    $account = heldAccounts($subject)->sole();

    expect(fn () => FinancialAccount::query()->create([
        'tax_subject_id' => $subject->id,
        'kind' => AccountKind::INVESTMENTS,
        'display_name_ciphertext' => 'Twin',
        'currency_code' => 'RON',
        'held_at' => $account->held_at,
        'source' => RecordSource::MANUAL,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('lists the automatic account with its place, for the screens to narrow, and refuses it as a target of a transaction', function (): void {
    [$user, $subject] = owner();
    buy($this, $user, $subject, instrument())->assertCreated();
    $account = heldAccount($subject);

    // Listed like every account: each screen keeps what it offers, and the Accounts screen leaves it out.
    $listed = $this->actingAs($user)->getJson("/api/v1/subjects/{$subject->id}/wallet/accounts")->assertOk()->json('accounts');
    expect(collect(is_array($listed) ? $listed : [])->firstWhere('id', $account))->toMatchArray(['kind' => 'INVESTMENTS', 'held_at' => 'BROKER', 'currency_code' => 'RON']);

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subject->id}/wallet/transactions", [
        'account_id' => $account, 'amount_minor' => 100, 'currency_code' => 'RON', 'booked_at' => '2026-03-01', 'description' => 'Typed',
    ])->assertUnprocessable()->assertJsonValidationErrors('account_id');
});

it('sells from the row of a holding, in the account its lot is in', function (): void {
    approvedSecuritiesRates();
    [$user, $subject] = owner();
    $share = instrument();
    $lot = lotId(buy($this, $user, $subject, $share, ['bought_on' => '2026-01-10']));
    $row = holdings($this, $user, $subject)[$lot];

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subject->id}/wallet/holdings/sales", [
        'account_id' => $row['accountId'], 'instrument_id' => $row['instrumentId'], 'units' => '4', 'price_per_unit' => '110', 'sold_on' => '2026-06-01', 'fees_minor' => 0,
    ])->assertCreated();

    // A payment into the account (a dividend, say) is listed with the place it keeps its money, which the screen words.
    FinancialTransaction::query()->create([
        'tax_subject_id' => $subject->id, 'account_id' => $row['accountId'], 'booked_at' => '2026-06-02', 'amount_minor' => 500,
        'currency_code' => 'RON', 'description_ciphertext' => 'Dividend', 'counterparty_ciphertext' => '',
        'classification_status' => 'UNCLASSIFIED', 'source' => RecordSource::MANUAL,
    ]);
    $this->actingAs($user)->getJson("/api/v1/subjects/{$subject->id}/wallet/transactions")->assertOk()
        ->assertJsonPath('transactions.0.account_held_at', 'BROKER')
        ->assertJsonPath('transactions.0.account_kind', 'INVESTMENTS');

    expect($row['accountId'])->toBe(heldAccount($subject))
        ->and(FinancialTransaction::query()->withoutGlobalScope(TenantScope::class)->where('account_id', $row['accountId'])->count())->toBe(2);
});

/** @return Collection<int, FinancialAccount> */
function heldAccounts(TaxSubject $subject): Collection
{
    return FinancialAccount::query()->withoutGlobalScope(TenantScope::class)
        ->where('tax_subject_id', $subject->id)
        ->where('kind', AccountKind::INVESTMENTS->value)
        ->get();
}

it('does not let a person open an investments account', function (): void {
    [$user, $subject] = owner();

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subject->id}/wallet/accounts", [
        'kind' => 'INVESTMENTS',
        'display_name' => 'Mine',
    ])->assertUnprocessable()->assertJsonValidationErrors('kind');
});

it('refuses to edit or delete the automatic account, and keeps every holding in it', function (): void {
    [$user, $subject] = owner();
    $lot = lotId(buy($this, $user, $subject, instrument())->assertCreated());
    $account = heldAccount($subject);

    $this->actingAs($user)->patchJson("/api/v1/subjects/{$subject->id}/wallet/accounts/{$account}", ['display_name' => 'Renamed'])
        ->assertUnprocessable()->assertJsonPath('reason', 'ACCOUNT_OPENED_BY_HOLDINGS');
    $this->actingAs($user)->deleteJson("/api/v1/subjects/{$subject->id}/wallet/accounts/{$account}")
        ->assertUnprocessable()->assertJsonPath('reason', 'ACCOUNT_OPENED_BY_HOLDINGS');

    expect(FinancialAccount::query()->withoutGlobalScope(TenantScope::class)->whereKey($account)->exists())->toBeTrue()
        ->and(InvestmentLot::query()->withoutGlobalScope(TenantScope::class)->whereKey($lot)->exists())->toBeTrue();
});
