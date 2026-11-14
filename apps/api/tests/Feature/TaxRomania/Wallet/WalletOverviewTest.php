<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Wallet\WalletOverviewTest;

use Illuminate\Support\Facades\Cache;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\Testing\InMemoryDekRepository;
use Kern\Platform\Testing\InMemoryKeyManagementService;
use Kern\TaxRomania\Banks\Logos\BankLogos;
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
        'term_months' => 6,
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

/** Money leaving General as the taxpayer's own transfer, to an account this installation does not hold. */
function transferOut(TestCase $case, User $user, string $subjectId, string $generalId, int $amount, string $bookedAt): void
{
    $case->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions", [
        'account_id' => $generalId,
        'amount_minor' => -$amount,
        'currency_code' => 'RON',
        'booked_at' => $bookedAt,
        'description' => 'Transfer',
        'category_code' => 'TRANSFER',
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
        // Each bank's share of every running deposit, divided by the server, as a decimal.
        ->assertJsonPath('banks.0.share', '0.8000')
        ->assertJsonPath('banks.1.name', 'CEC Bank')
        ->assertJsonPath('banks.1.share', '0.2000');
});

it('gives each bank its logo’s address, where this installation has one', function (): void {
    [$user, $subjectId, $generalId] = startingFromNothing();
    $bt = BankFactory::new()->createOne(['name' => 'Banca Transilvania', 'bic' => 'BTRLRO22XXX']);
    $cec = BankFactory::new()->createOne(['name' => 'CEC Bank', 'bic' => 'CECEROBUXXX']);
    openDeposit($this, $user, $subjectId, $generalId, 7_000_000, $bt->id);
    openDeposit($this, $user, $subjectId, $generalId, 3_000_000, $cec->id);

    $folder = sys_get_temp_dir() . '/kern-overview-logos-' . bin2hex(random_bytes(6));
    mkdir($folder . '/RO', 0o755, true);
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"/>';
    file_put_contents($folder . '/RO/BTRLRO22.svg', $svg);
    config(['banks.logos.path' => $folder]);
    Cache::forget(BankLogos::CACHE_KEY);

    try {
        $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/overview")->assertOk()
            ->assertJsonPath('banks.0.logo_url', "/api/v1/banks/{$bt->id}/logo?v=" . substr(sha1($svg), 0, 12))
            ->assertJsonPath('banks.1.logo_url', null);
    } finally {
        unlink($folder . '/RO/BTRLRO22.svg');
        rmdir($folder . '/RO');
        rmdir($folder);
    }
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
        // Lent out of money never recorded arriving: General is what was recorded, below zero.
        ->assertJsonPath('totals.holdings.general.amounts.RON', -2_000_000);
});

it('keeps money transferred to an account it does not hold', function (): void {
    [$user, $subjectId, $generalId] = startingFromNothing();
    recordInGeneral($this, $user, $subjectId, $generalId, 30_000_000, '2026-01-10');
    transferOut($this, $user, $subjectId, $generalId, 20_000_000, '2026-01-20');

    // The money left General and landed nowhere this records, which is not the same as being spent.
    // Counting only the end that is written down lost it: "what you have" fell by twenty million
    // that the taxpayer still has, in an account at another bank.
    $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/overview")->assertOk()
        ->assertJsonPath('totals.holdings.general.amounts.RON', 10_000_000)
        ->assertJsonPath('totals.holdings.internal.amounts.RON', 20_000_000)
        // Reachable, unlike a deposit: it is in an account, just not one described here.
        ->assertJsonPath('totals.holdings.available.amounts.RON', 30_000_000)
        ->assertJsonPath('totals.net_worth.amount_minor', 30_000_000)
        // On the dashboard the money held elsewhere is General's: one part, all of the whole.
        ->assertJsonPath('totals.breakdown.1.group', 'general')
        ->assertJsonPath('totals.breakdown.1.amounts.RON', 30_000_000)
        ->assertJsonPath('totals.breakdown.1.share', '1.0000')
        ->assertJsonPath('totals.breakdown.0.group', 'deposits')
        ->assertJsonPath('totals.breakdown.0.share', '0.0000');
});

it('does not count a deposit’s own funding as money held elsewhere', function (): void {
    [$user, $subjectId, $generalId] = startingFromNothing();
    recordInGeneral($this, $user, $subjectId, $generalId, 30_000_000, '2026-01-10');
    openDeposit($this, $user, $subjectId, $generalId, 20_000_000);

    // A funding transfer and a transfer to another bank are written identically — same category,
    // same direction — and only the link the deposit keeps tells them apart. Without it this would
    // hold the same twenty million twice: once as the deposit, once as money moved away.
    $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/overview")->assertOk()
        ->assertJsonPath('totals.holdings.deposits.amounts.RON', 20_000_000)
        ->assertJsonPath('totals.holdings.internal.amounts', [])
        ->assertJsonPath('totals.net_worth.amount_minor', 30_000_000);
});

it('leaves an account below zero when its records start after money was already there', function (): void {
    [$user, $subjectId, $generalId] = startingFromNothing();
    // A statement that spends more than it brings in: nothing proves what paid for the difference,
    // so it is not guessed — the account says where its records start instead.
    recordInGeneral($this, $user, $subjectId, $generalId, -10_000_000, '2026-01-10');
    recordInGeneral($this, $user, $subjectId, $generalId, 3_000_000, '2026-01-20');

    $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/overview")->assertOk()
        ->assertJsonPath('totals.holdings.general.amounts.RON', -7_000_000)
        ->assertJsonPath('totals.net_worth.amount_minor', -7_000_000)
        ->assertJsonPath('totals.records_short_from', '2026-01-10');
});

it('comes right by itself once the earlier records are imported', function (): void {
    [$user, $subjectId, $generalId] = startingFromNothing();
    recordInGeneral($this, $user, $subjectId, $generalId, -10_000_000, '2026-01-10');
    // The year before, imported afterwards.
    recordInGeneral($this, $user, $subjectId, $generalId, 25_000_000, '2025-06-01');

    $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/overview")->assertOk()
        ->assertJsonPath('totals.holdings.general.amounts.RON', 15_000_000)
        ->assertJsonPath('totals.records_short_from', null);
});

it('lifts an account by what funded a deposit, never by other spending the same day', function (): void {
    [$user, $subjectId, $generalId] = startingFromNothing();
    openDeposit($this, $user, $subjectId, $generalId, 15_000_000);
    recordInGeneral($this, $user, $subjectId, $generalId, -1_000_000, now('UTC')->toDateString());

    $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/overview")->assertOk()
        ->assertJsonPath('totals.holdings.general.amounts.RON', -1_000_000)
        ->assertJsonPath('totals.holdings.deposits.amounts.RON', 15_000_000)
        ->assertJsonPath('totals.net_worth.amount_minor', 14_000_000);
});
