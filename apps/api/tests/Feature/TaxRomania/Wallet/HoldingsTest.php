<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Wallet\HoldingsTest;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Kern\Investments\Enums\InstrumentKind;
use Kern\Investments\Enums\InstrumentStatus;
use Kern\Investments\Enums\Market;
use Kern\Investments\Enums\Programme;
use Kern\Investments\Models\FinancialInstrument;
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
use Kern\TaxRomania\Wallet\Models\FinancialTransaction;
use Kern\TaxRomania\Wallet\Models\InvestmentLot;
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

function account(TestCase $case, User $user, TaxSubject $subject, string $kind = 'INVESTMENTS'): string
{
    $id = $case->actingAs($user)->postJson("/api/v1/subjects/{$subject->id}/wallet/accounts", [
        'kind' => $kind,
        'display_name' => 'Cont de test',
    ])->assertCreated()->json('id');

    return is_string($id) ? $id : '';
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
function buy(TestCase $case, User $user, TaxSubject $subject, string $account, FinancialInstrument $instrument, array $overrides = []): TestResponse
{
    return $case->actingAs($user)->postJson("/api/v1/subjects/{$subject->id}/wallet/holdings", [
        'account_id' => $account,
        'instrument_id' => $instrument->id,
        'units' => '10',
        'price_per_unit' => '100',
        'bought_on' => '2026-02-01',
        'fees_minor' => 0,
        'held_at' => 'BROKER',
        ...$overrides,
    ]);
}

/** @return array<string, array<array-key, mixed>> the holdings the API answers, by lot id */
function holdings(TestCase $case, User $user, TaxSubject $subject): array
{
    $lines = $case->actingAs($user)->getJson("/api/v1/subjects/{$subject->id}/wallet/holdings")->assertOk()->json('holdings');
    $byId = [];

    foreach (is_array($lines) ? $lines : [] as $line) {
        if (is_array($line) && is_string($line['id'] ?? null)) {
            $byId[$line['id']] = $line;
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

it('adds a lot with its instrument’s terms, and refuses an instrument no longer on the market', function (): void {
    [$user, $subject] = owner();
    $account = account($this, $user, $subject);
    $euro = fidelis(['currency' => 'EUR']);
    $matured = instrument(['symbol' => 'GONE', 'isin' => 'ROGONE000001', 'status' => InstrumentStatus::MATURED]);

    $id = lotId(buy($this, $user, $subject, $account, $euro, ['units' => '12.5', 'fees_minor' => 250])->assertCreated());

    buy($this, $user, $subject, $account, $matured)
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'INSTRUMENT_NOT_OFFERED');

    $line = holdings($this, $user, $subject)[$id];
    expect($line['currency'])->toBe('EUR')
        ->and($line['instrumentName'])->toBe('FIDELIS R2899T')
        ->and($line['units'])->toBe('12.5')
        ->and($line['costMinor'])->toBe(125_250);
});

it('refuses a holding in an account that is not for investments, and a malformed number of units', function (): void {
    [$user, $subject] = owner();
    $other = account($this, $user, $subject, 'OTHER');
    $share = instrument();

    buy($this, $user, $subject, $other, $share)
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'NOT_AN_INVESTMENTS_ACCOUNT');

    $account = account($this, $user, $subject);
    foreach (['0', '1e3', '1,5', '-2'] as $units) {
        buy($this, $user, $subject, $account, $share, ['units' => $units])->assertUnprocessable()->assertJsonValidationErrors('units');
    }
});

it('keeps a broker’s reference encrypted and adopts the lot by it, never adding it twice', function (): void {
    [$user, $subject] = owner();
    $account = account($this, $user, $subject);
    $share = instrument();

    $first = lotId(buy($this, $user, $subject, $account, $share, ['external_ref' => 'POS-778899'])->assertCreated());
    $again = lotId(buy($this, $user, $subject, $account, $share, ['external_ref' => 'POS-778899', 'units' => '99'])->assertCreated());

    $row = DB::table('investment_lots')->where('id', $first)->first();

    expect($again)->toBe($first)
        ->and(DB::table('investment_lots')->where('tax_subject_id', $subject->id)->count())->toBe(1)
        ->and($row?->units)->toBe('10')
        ->and(json_encode($row, JSON_THROW_ON_ERROR))->not->toContain('POS-778899')
        ->and($row?->external_ref_blind_index)->toBeString();

    // The same reference at another place is another position.
    buy($this, $user, $subject, $account, $share, ['external_ref' => 'POS-778899', 'held_at' => 'BANK'])->assertCreated();
    expect(DB::table('investment_lots')->where('tax_subject_id', $subject->id)->count())->toBe(2);
});

it('never shows, adds to or sells another taxpayer’s holding', function (): void {
    [$alice, $hers] = owner();
    [$bob, $his] = owner();
    $account = account($this, $alice, $hers);
    $share = instrument();
    buy($this, $alice, $hers, $account, $share)->assertCreated();

    expect(holdings($this, $bob, $his))->toBe([]);

    buy($this, $bob, $his, $account, $share)->assertNotFound();
    $this->actingAs($bob)->postJson("/api/v1/subjects/{$his->id}/wallet/holdings/sales", [
        'account_id' => $account,
        'instrument_id' => $share->id,
        'units' => '1',
        'price_per_unit' => '1',
        'sold_on' => '2026-03-01',
        'fees_minor' => 0,
    ])->assertNotFound();
    $this->actingAs($bob)->getJson("/api/v1/subjects/{$hers->id}/wallet/holdings")->assertForbidden();

    expect(InvestmentLot::query()->withoutGlobalScope(TenantScope::class)->whereNotNull('sold_on')->count())->toBe(0);
});

/** The securities gain's rates and holding period, approved — as the law states them, 2026. */
function approvedSecuritiesRates(): void
{
    foreach ([
        'RO.INCOME_TAX.SECURITIES_GAIN_RATE_LONG' => [FiscalParameterValueType::DECIMAL, ['value' => '0.03']],
        'RO.INCOME_TAX.SECURITIES_GAIN_RATE_SHORT' => [FiscalParameterValueType::DECIMAL, ['value' => '0.06']],
        'RO.INCOME_TAX.SECURITIES_HOLDING_DAYS' => [FiscalParameterValueType::JSON, ['days' => 365]],
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
    $account = account($this, $user, $subject);
    $share = instrument();
    $older = lotId(buy($this, $user, $subject, $account, $share, ['units' => '10', 'price_per_unit' => '100', 'bought_on' => '2026-01-10', 'fees_minor' => 1_000]));
    $newer = lotId(buy($this, $user, $subject, $account, $share, ['units' => '10', 'price_per_unit' => '110', 'bought_on' => '2026-02-10', 'fees_minor' => 500]));
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
    $account = account($this, $user, $subject);
    $share = instrument();
    buy($this, $user, $subject, $account, $share, ['units' => '10', 'price_per_unit' => '100', 'bought_on' => '2025-01-10']);
    buy($this, $user, $subject, $account, $share, ['units' => '10', 'price_per_unit' => '100', 'bought_on' => '2026-03-10']);

    $sold = soldIds($this->actingAs($user)->postJson("/api/v1/subjects/{$subject->id}/wallet/holdings/sales", [
        'account_id' => $account, 'instrument_id' => $share->id, 'units' => '20', 'price_per_unit' => '110', 'sold_on' => '2026-06-01', 'fees_minor' => 0,
    ])->assertCreated());

    // 200 lei gained, 100 a lot: 3 on the one held over a year, 6 on the other.
    expect(InvestmentLot::query()->withoutGlobalScope(TenantScope::class)->whereIn('id', $sold)->orderBy('bought_on')->pluck('sale_tax_withheld_minor')->all())->toBe([300, 600]);
});

it('refuses a gain on a share while no rate is approved, and writes nothing', function (): void {
    [$user, $subject] = owner();
    $account = account($this, $user, $subject);
    $share = instrument();
    buy($this, $user, $subject, $account, $share, ['units' => '10', 'price_per_unit' => '100', 'bought_on' => '2026-01-10']);

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subject->id}/wallet/holdings/sales", [
        'account_id' => $account, 'instrument_id' => $share->id, 'units' => '10', 'price_per_unit' => '120', 'sold_on' => '2026-06-01', 'fees_minor' => 0,
    ])->assertUnprocessable()->assertJsonPath('reason', 'NO_APPROVED_SECURITIES_RATE');

    expect(FinancialTransaction::query()->withoutGlobalScope(TenantScope::class)->where('account_id', $account)->exists())->toBeFalse()
        ->and(InvestmentLot::query()->withoutGlobalScope(TenantScope::class)->whereNotNull('sold_on')->exists())->toBeFalse();
});

it('withholds nothing on a public-debt gain, filed as such, and nothing on a loss', function (): void {
    [$user, $subject] = owner();
    $account = account($this, $user, $subject);
    $bond = fidelis();
    $share = instrument();
    buy($this, $user, $subject, $account, $bond, ['units' => '10', 'price_per_unit' => '100', 'bought_on' => '2026-01-10']);
    buy($this, $user, $subject, $account, $share, ['units' => '10', 'price_per_unit' => '100', 'bought_on' => '2026-01-10']);
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
    $account = account($this, $user, $subject);
    $tezaur = tezaur();
    $lot = lotId(buy($this, $user, $subject, $account, $tezaur, ['units' => '5000', 'price_per_unit' => '1', 'held_at' => 'TREASURY']));

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
    $account = account($this, $user, $subject);
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

    $id = fn (FinancialInstrument $instrument, array $overrides = []): string => lotId(buy($this, $user, $subject, $account, $instrument, $overrides)->assertCreated());
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
