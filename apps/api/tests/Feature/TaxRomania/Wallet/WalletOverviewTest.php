<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Wallet\WalletOverviewTest;

use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\Testing\InMemoryDekRepository;
use Kern\Platform\Testing\InMemoryKeyManagementService;
use Kern\TaxRomania\Database\Factories\BankFactory;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\Wallet\Enums\AccountKind;
use Kern\TaxRomania\Wallet\Enums\RecordSource;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;
use Tests\TestCase;

/**
 * What the wallet overview says a taxpayer has — starting from nothing, the way a person actually
 * starts using it.
 */
beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();

    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemoryDekRepository()),
    );
});

/** @return array{0: User, 1: string, 2: string} the user, the subject, and its General account */
function startingFromNothing(): array
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

    $general = FinancialAccount::create([
        'tax_subject_id' => $subject->id,
        'kind' => AccountKind::GENERAL,
        'display_name_ciphertext' => null,
        'currency_code' => 'RON',
        'source' => RecordSource::MANUAL,
    ]);

    return [$user, $subject->id, $general->id];
}

function openDeposit(TestCase $case, User $user, string $subjectId, string $generalId, int $principal, ?string $bankId = null): string
{
    $id = $case->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'bank_id' => $bankId ?? BankFactory::new()->createOne()->id,
        'kind' => 'TERM_DEPOSIT',
        'currency_code' => 'RON',
        'starts_at' => now('UTC')->toDateString(),
        'principal_minor' => $principal,
        'maturity_at' => now('UTC')->addMonths(6)->toDateString(),
        'annual_rate' => '6.0000',
        'rate_type' => 'FIXED',
        'interest_method' => 'SIMPLE',
        'payment_frequency' => 'AT_MATURITY',
        'funding_account_id' => $generalId,
    ])->assertCreated()->json('id');

    return is_string($id) ? $id : '';
}

function recordInGeneral(TestCase $case, User $user, string $subjectId, string $generalId, int $amount, string $bookedAt): void
{
    $case->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions", [
        'account_id' => $generalId,
        'amount_minor' => $amount,
        'currency_code' => 'RON',
        'booked_at' => $bookedAt,
        'description' => 'Dividende',
    ])->assertCreated();
}

it('counts a deposit funded from General as money already had, not as General going negative', function (): void {
    [$user, $subjectId, $generalId] = startingFromNothing();
    openDeposit($this, $user, $subjectId, $generalId, 15_000_000);

    $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/overview")->assertOk()
        ->assertJsonPath('totals.holdings.deposits.amounts.RON', 15_000_000)
        ->assertJsonPath('totals.holdings.general.amounts.RON', 0);
});

it('moves recorded money into a deposit instead of counting it twice', function (): void {
    [$user, $subjectId, $generalId] = startingFromNothing();

    // 100 000 lei recorded arriving, then a 150 000 lei deposit: 150 000 in total, not 250 000.
    recordInGeneral($this, $user, $subjectId, $generalId, 10_000_000, now('UTC')->subDay()->toDateString());
    openDeposit($this, $user, $subjectId, $generalId, 15_000_000);

    $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/overview")->assertOk()
        ->assertJsonPath('totals.holdings.general.amounts.RON', 0)
        ->assertJsonPath('totals.holdings.deposits.amounts.RON', 15_000_000);
});

it('counts money recorded after the deposit in full', function (): void {
    [$user, $subjectId, $generalId] = startingFromNothing();

    openDeposit($this, $user, $subjectId, $generalId, 15_000_000);
    recordInGeneral($this, $user, $subjectId, $generalId, 2_000_000, now('UTC')->addDay()->toDateString());

    $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/overview")->assertOk()
        ->assertJsonPath('totals.holdings.general.amounts.RON', 2_000_000)
        // Spendable today: General and cash, never the deposit.
        ->assertJsonPath('totals.holdings.available.amounts.RON', 2_000_000);
});

it('groups running deposits by the bank they are at, largest first', function (): void {
    [$user, $subjectId, $generalId] = startingFromNothing();
    $bt = BankFactory::new()->createOne(['name' => 'Banca Transilvania', 'bic' => 'BTRLRO22XXX']);
    $cec = BankFactory::new()->createOne(['name' => 'CEC Bank', 'bic' => 'CECEROBUXXX']);

    openDeposit($this, $user, $subjectId, $generalId, 5_000_000, $bt->id);
    openDeposit($this, $user, $subjectId, $generalId, 7_000_000, $bt->id);
    openDeposit($this, $user, $subjectId, $generalId, 3_000_000, $cec->id);

    $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/overview")->assertOk()
        ->assertJsonCount(2, 'banks')
        ->assertJsonPath('banks.0.name', 'Banca Transilvania')
        ->assertJsonPath('banks.0.code', 'BTRL')
        ->assertJsonPath('banks.0.deposit_count', 2)
        ->assertJsonPath('banks.0.principal.RON', 12_000_000)
        ->assertJsonPath('banks.1.name', 'CEC Bank');
});

it('lists neither a deposit’s funding nor a cash holding among transactions', function (): void {
    [$user, $subjectId, $generalId] = startingFromNothing();

    openDeposit($this, $user, $subjectId, $generalId, 15_000_000);
    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'display_name' => 'Seif',
        'kind' => 'CASH',
        'currency_code' => 'EUR',
        'holding_amount_minor' => 5_000_000,
    ])->assertCreated();
    recordInGeneral($this, $user, $subjectId, $generalId, 1_000, now('UTC')->toDateString());

    // Only the one movement a person decides anything about.
    $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/transactions")->assertOk()
        ->assertJsonCount(1, 'transactions')
        ->assertJsonPath('transactions.0.description', 'Dividende');
});

it('lists deposits first, then cash, then other accounts by name, and General last', function (): void {
    [$user, $subjectId, $generalId] = startingFromNothing();

    foreach (['Zeta broker', 'Alpha broker'] as $name) {
        $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
            'display_name' => $name,
            'kind' => 'OTHER',
            'currency_code' => 'RON',
        ])->assertCreated();
    }

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'display_name' => 'Seif',
        'kind' => 'CASH',
        'currency_code' => 'EUR',
        'holding_amount_minor' => 5_000_000,
    ])->assertCreated();
    openDeposit($this, $user, $subjectId, $generalId, 15_000_000);

    /** @var list<array{kind: string, display_name: string}> $accounts */
    $accounts = $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/accounts")->assertOk()->json('accounts');

    // By decrypted name — the order used to follow the ciphertext, which is no order at all.
    expect(array_column($accounts, 'kind'))->toBe(['TERM_DEPOSIT', 'CASH', 'OTHER', 'OTHER', 'GENERAL'])
        ->and([$accounts[2]['display_name'], $accounts[3]['display_name']])->toBe(['Alpha broker', 'Zeta broker']);
});

it('counts money lent as owed until it is paid back, and never as money had', function (): void {
    [$user, $subjectId, $generalId] = startingFromNothing();

    $record = function (int $amount, string $category) use ($user, $subjectId, $generalId): void {
        $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions", [
            'account_id' => $generalId,
            'amount_minor' => $amount,
            'currency_code' => 'RON',
            'booked_at' => now('UTC')->toDateString(),
            'description' => 'Împrumut',
            'category_code' => $category,
        ])->assertCreated();
    };

    $record(-5_000_000, 'LOAN');
    $record(2_000_000, 'LOAN_REPAYMENT');
    // Borrowed, not lent: money this taxpayer owes, not money owed to them.
    $record(1_000_000, 'LOAN');

    $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/overview")->assertOk()
        ->assertJsonPath('totals.owed_to_me.amounts.RON', 3_000_000)
        ->assertJsonPath('totals.holdings.general.amounts.RON', 0);
});
