<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Wallet\HoldingPayoutsTest;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Kern\Investments\Enums\InstrumentKind;
use Kern\Investments\Enums\InstrumentStatus;
use Kern\Investments\Enums\Market;
use Kern\Investments\Enums\PayoutKind;
use Kern\Investments\Enums\PayoutSource;
use Kern\Investments\Enums\PayoutStatus;
use Kern\Investments\Enums\Programme;
use Kern\Investments\Models\FinancialInstrument;
use Kern\Investments\Models\InstrumentPayout;
use Kern\Legislation\Models\SourceSection;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Tenancy\TenantScope;
use Kern\TaxRomania\FiscalParameters\Enums\FiscalParameterValueType;
use Kern\TaxRomania\FiscalParameters\Models\FiscalParameter;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\Wallet\Actions\PostHoldingPayouts;
use Kern\TaxRomania\Wallet\Enums\AccountKind;
use Kern\TaxRomania\Wallet\Enums\HeldAt;
use Kern\TaxRomania\Wallet\Enums\RecordSource;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;
use Kern\TaxRomania\Wallet\Models\FinancialTransaction;
use Kern\TaxRomania\Wallet\Models\InvestmentLot;
use Kern\TaxRomania\Wallet\Models\InvestmentLotPayout;
use Kern\TaxRomania\Wallet\Models\TransactionSplit;

/**
 * The nightly credit of a holding's payouts (ADR 0025 §9), with no tenant bound, as the scheduler runs
 * it: who is owed a dividend, net of the rate a person approved — and nothing while none is — a Tezaur
 * coupon computed as its prospectus says, no coupon for a corporate bond, and nothing paid twice when a
 * lot is split by a sale. Every instrument and rate here is the test's own.
 */
function investmentsAccount(TaxSubject $subject): FinancialAccount
{
    return FinancialAccount::create([
        'tax_subject_id' => $subject->id,
        'kind' => AccountKind::INVESTMENTS,
        'display_name_ciphertext' => 'Cont de test',
        'source' => RecordSource::MANUAL,
    ]);
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
function payout(FinancialInstrument $instrument, PayoutKind $kind, string $paidOn, array $overrides = []): InstrumentPayout
{
    return InstrumentPayout::query()->create([
        'instrument_id' => $instrument->id,
        'kind' => $kind,
        'payment_date' => $paidOn,
        'status' => PayoutStatus::PAID,
        'source' => $kind === PayoutKind::DIVIDEND ? PayoutSource::BVB : PayoutSource::ORDER,
        ...$overrides,
    ]);
}

/** A lot written as the API would, with no tenant bound — the state the nightly task runs in. */
function lot(FinancialAccount $account, FinancialInstrument $instrument, string $units, string $boughtOn, ?string $soldOn = null): InvestmentLot
{
    return InvestmentLot::query()->withoutGlobalScope(TenantScope::class)->create([
        'tax_subject_id' => $account->tax_subject_id,
        'financial_account_id' => $account->id,
        'instrument_id' => $instrument->id,
        'units' => $units,
        'price_per_unit' => '1',
        'bought_on' => $boughtOn,
        'fees_minor' => 0,
        'currency' => $instrument->currency,
        'held_at' => HeldAt::BROKER,
        'sold_on' => $soldOn,
        'sold_price_per_unit' => $soldOn === null ? null : '1',
        'sale_fees_minor' => $soldOn === null ? null : 0,
    ]);
}

/** @return array<string, array{gross: int, withheld: int|null, net: int}> what each lot was credited, by lot id */
function credited(): array
{
    return InvestmentLotPayout::query()->withoutGlobalScope(TenantScope::class)->get()
        ->mapWithKeys(static fn (InvestmentLotPayout $row): array => [$row->lot_id => ['gross' => $row->gross_minor, 'withheld' => $row->withheld_minor, 'net' => $row->net_minor]])
        ->all();
}

function dividendRate(string $status): void
{
    $parameter = FiscalParameter::query()->where('code', PostHoldingPayouts::DIVIDEND_RATE)->first()
        ?? FiscalParameter::factory()->create(['code' => PostHoldingPayouts::DIVIDEND_RATE, 'value_type' => FiscalParameterValueType::DECIMAL->value]);

    \insertApprovedFiscalParameterVersion($parameter->id, SourceSection::factory()->create()->id, [
        'valid_from' => '2026-01-01',
        'value_json' => json_encode(['value' => '0.16'], JSON_THROW_ON_ERROR),
        'status' => $status,
        ...($status === 'APPROVED' ? [] : ['approved_by' => null, 'approved_at' => null]),
    ]);
}

function post(string $asOf): \Kern\TaxRomania\Wallet\DataTransferObjects\PostedHoldingPayouts
{
    return app(PostHoldingPayouts::class)(CarbonImmutable::parse($asOf));
}

it('credits a dividend to the units held the day before its ex-date and not sold before it, net of the approved rate', function (): void {
    dividendRate('APPROVED');
    $account = investmentsAccount(TaxSubject::factory()->create());
    $share = instrument();
    payout($share, PayoutKind::DIVIDEND, '2026-06-25', ['ex_date' => '2026-06-10', 'amount_per_unit' => '1']);

    $held = lot($account, $share, '100', '2026-03-01');
    $boughtOnTheExDate = lot($account, $share, '50', '2026-06-10');
    $soldBefore = lot($account, $share, '30', '2026-03-01', '2026-06-09');
    $soldOnTheExDate = lot($account, $share, '20', '2026-03-01', '2026-06-10');

    expect(post('2026-06-24')->posted)->toBe(0)
        ->and(post('2026-07-01')->posted)->toBe(2)
        ->and(post('2026-07-02')->posted)->toBe(0);

    $rows = credited();
    $transactions = FinancialTransaction::query()->withoutGlobalScope(TenantScope::class)->where('account_id', $account->id)->orderBy('amount_minor')->get();

    // 100 shares at 1 leu: 100 lei, 16 withheld, 84 credited.
    expect($rows[$held->id])->toBe(['gross' => 10_000, 'withheld' => 1_600, 'net' => 8_400])
        ->and($rows[$soldOnTheExDate->id])->toBe(['gross' => 2_000, 'withheld' => 320, 'net' => 1_680])
        ->and($rows)->not->toHaveKey($boughtOnTheExDate->id)
        ->and($rows)->not->toHaveKey($soldBefore->id)
        ->and($transactions->pluck('amount_minor')->all())->toBe([1_680, 8_400])
        ->and($transactions->first()?->booked_at->toDateString())->toBe('2026-06-25')
        ->and(TransactionSplit::query()->withoutGlobalScope(TenantScope::class)->whereIn('transaction_id', $transactions->modelKeys())->pluck('category_code')->unique()->all())->toBe(['DIVIDENDS']);
});

it('credits no dividend while no rate is approved, says so, and credits it once one is', function (): void {
    $account = investmentsAccount(TaxSubject::factory()->create());
    $share = instrument();
    payout($share, PayoutKind::DIVIDEND, '2026-06-25', ['ex_date' => '2026-06-10', 'amount_per_unit' => '1']);
    $lot = lot($account, $share, '100', '2026-03-01');

    expect(post('2026-07-01')->skipped)->toBe(['NO_APPROVED_DIVIDEND_RATE' => 1]);

    // A value only proposed is not counted.
    dividendRate('CANDIDATE');
    $this->travelTo(CarbonImmutable::parse('2026-07-01'));
    Artisan::call('kern:wallet:post-holding-payouts');
    expect(Artisan::output())->toContain('NO_APPROVED_DIVIDEND_RATE')
        ->and(credited())->toBe([])
        ->and(FinancialTransaction::query()->withoutGlobalScope(TenantScope::class)->where('account_id', $account->id)->exists())->toBeFalse();

    dividendRate('APPROVED');
    expect(post('2026-07-01')->posted)->toBe(1)
        ->and(credited()[$lot->id]['net'])->toBe(8_400);
});

it('computes a Tezaur coupon as its prospectus does: face value × rate × days accrued over the days of the year', function (): void {
    $account = investmentsAccount(TaxSubject::factory()->create());
    $tezaur = instrument([
        'kind' => InstrumentKind::GOVERNMENT_BOND, 'programme' => Programme::TEZAUR, 'symbol' => null, 'isin' => null,
        'market' => null, 'issue_code' => '9999', 'name' => 'TEZAUR 9999', 'face_value' => '1', 'rate' => '7.00',
        'issue_date' => '2026-11-10', 'maturity_date' => '2029-11-10',
    ]);
    // The second period holds 29 February 2028: 366 days over 366, a whole year's interest all the same.
    payout($tezaur, PayoutKind::COUPON, '2027-11-10', ['period_start' => '2026-11-10', 'period_end' => '2027-11-09', 'rate' => '7.00']);
    payout($tezaur, PayoutKind::COUPON, '2028-11-10', ['period_start' => '2027-11-10', 'period_end' => '2028-11-09', 'rate' => '7.00']);
    payout($tezaur, PayoutKind::PRINCIPAL, '2029-11-12', ['amount_per_unit' => '1']);
    lot($account, $tezaur, '12345', '2026-11-05');

    expect(post('2028-11-10')->posted)->toBe(2);

    // 12.345 × 1 leu × 7 % = 864,15 lei a year, untaxed at source.
    expect(InvestmentLotPayout::query()->withoutGlobalScope(TenantScope::class)->orderBy('paid_on')->get(['gross_minor', 'withheld_minor'])->toArray())
        ->toBe([['gross_minor' => 86_415, 'withheld_minor' => null], ['gross_minor' => 86_415, 'withheld_minor' => null]]);

    expect(post('2029-11-12')->posted)->toBe(1)
        ->and(FinancialTransaction::query()->withoutGlobalScope(TenantScope::class)->where('account_id', $account->id)->sum('amount_minor'))->toEqual(86_415 * 2 + 1_234_500);
});

it('credits no coupon to a corporate or a municipal bond, whose payment dates BVB does not publish', function (): void {
    $account = investmentsAccount(TaxSubject::factory()->create());

    foreach ([InstrumentKind::CORPORATE_BOND, InstrumentKind::MUNICIPAL_BOND] as $i => $kind) {
        $bond = instrument(['kind' => $kind, 'symbol' => "BOND{$i}", 'isin' => "ROBOND00000{$i}", 'face_value' => '100']);
        payout($bond, PayoutKind::COUPON, '2026-06-30', ['amount_per_unit' => '5', 'record_date' => '2026-06-20']);
        lot($account, $bond, '10', '2026-01-10');
    }

    expect(post('2026-07-01')->posted)->toBe(0)
        ->and(credited())->toBe([]);
});

it('keeps what a lot was paid with its units when part of it is sold, and pays neither part twice', function (): void {
    $user = User::factory()->create();
    $subject = TaxSubject::factory()->create();
    SubjectMembership::create(['subject_id' => $subject->id, 'user_id' => $user->id, 'role' => MembershipRole::OWNER, 'permissions_json' => [], 'valid_from' => now('UTC')]);
    $account = investmentsAccount($subject);
    $fidelis = instrument([
        'kind' => InstrumentKind::GOVERNMENT_BOND, 'programme' => Programme::FIDELIS, 'symbol' => 'R2899T', 'isin' => 'ROTEST000001',
        'name' => 'FIDELIS R2899T', 'face_value' => '100', 'rate' => '7.00', 'issue_date' => '2026-01-05', 'maturity_date' => '2031-01-05',
    ]);
    payout($fidelis, PayoutKind::COUPON, '2026-05-10', ['record_date' => '2026-05-01', 'amount_per_unit' => '7', 'rate' => '7.00']);
    $lot = lot($account, $fidelis, '10', '2026-01-05');

    expect(post('2026-06-01')->posted)->toBe(1);

    $sold = $this->actingAs($user)->postJson("/api/v1/subjects/{$subject->id}/wallet/holdings/sales", [
        'account_id' => $account->id,
        'instrument_id' => $fidelis->id,
        'units' => '4',
        'price_per_unit' => '101',
        'sold_on' => '2026-07-01',
        'fees_minor' => 0,
    ])->assertCreated()->json('ids.0');
    expect($sold)->toBeString();
    $sold = is_string($sold) ? $sold : '';

    expect(post('2026-07-02')->posted)->toBe(0)
        ->and(credited())->toEqual([
            $lot->id => ['gross' => 4_200, 'withheld' => null, 'net' => 4_200],
            $sold => ['gross' => 2_800, 'withheld' => null, 'net' => 2_800],
        ])
        ->and(DB::table('investment_lot_payouts')->distinct()->count('transaction_id'))->toBe(1);
});
