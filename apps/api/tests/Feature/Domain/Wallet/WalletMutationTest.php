<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Wallet\WalletMutationTest;

use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use App\Domain\Wallet\Models\DepositTerms;
use App\Domain\Wallet\Models\FinancialAccount;
use App\Domain\Wallet\Models\FinancialTransaction;
use Database\Factories\BankFactory;
use Illuminate\Support\Facades\DB;
use Kern\Platform\Identity\Models\User;
use Tests\TestCase;

/**
 * Editing and deleting wallet records — and the audit entry every change leaves behind.
 *
 * Until this existed a person could add an account and a transaction and then never correct either,
 * which made a typo permanent. The rules that matter are about what must *not* be possible:
 * deleting an account that still explains a figure, changing a currency after amounts were recorded
 * in it, and changing anything at all without a trace.
 */
/** @return array{0: User, 1: string} */
function mutationSubject(): array
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

    return [$user, $subject->id];
}

/**
 * Opens a plain current account and returns its id.
 *
 * Takes the test case rather than calling Pest's `test()` helper, which is only bound inside a test
 * closure — in a plain function it resolves to nothing.
 */
function openCurrentAccount(TestCase $case, User $user, string $subjectId, string $name = 'Cont curent'): string
{
    // `OTHER`, not `GENERAL`: the general source is created with the taxpayer and cannot be opened
    // a second time or deleted, so a test that needs a disposable one asks for a stream.
    $response = $case->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'display_name' => $name,
        'kind' => 'OTHER',
        'currency_code' => 'RON',
    ])->assertCreated();

    return idFrom($response->json('id'));
}

/** A json-decoded id as a string. `TestResponse::json()` is `mixed`, and a bare cast on that is an error. */
function idFrom(mixed $value): string
{
    return is_string($value) ? $value : '';
}

it('edits only the fields the request actually sent', function (): void {
    [$user, $subjectId] = mutationSubject();
    $accountId = openCurrentAccount($this, $user, $subjectId);

    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/wallet/accounts/{$accountId}", ['currency_code' => 'EUR'])
        ->assertOk();

    $account = FinancialAccount::query()->findOrFail($accountId);

    // A PATCH naming one field must not blank the others — the trap in every partial-update
    // endpoint that treats absent as empty.
    expect($account->display_name_ciphertext)->toBe('Cont curent')
        ->and($account->currency_code)->toBe('EUR');
});

it("refuses to change a source's kind, because it decides what the source is", function (): void {
    [$user, $subjectId] = mutationSubject();
    $accountId = openCurrentAccount($this, $user, $subjectId);

    // Not merely ignored — rejected, so nobody believes it worked. `kind` decides whether
    // transactions may be recorded against the source at all, so changing it would reinterpret what
    // is already stored.
    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/wallet/accounts/{$accountId}", ['kind' => 'CASH'])
        ->assertStatus(422);

    // The currency is not identity, though: it pre-fills the transaction form and nothing that
    // calculates reads it, so it can be corrected.
    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/wallet/accounts/{$accountId}", ['currency_code' => 'EUR'])
        ->assertOk();
});

it('refuses to delete an account that still has transactions', function (): void {
    [$user, $subjectId] = mutationSubject();
    $accountId = openCurrentAccount($this, $user, $subjectId);

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions", [
        'account_id' => $accountId,
        'amount_minor' => -4550,
        'currency_code' => 'RON',
        'booked_at' => '2026-02-03',
        'description' => 'Cumpărături',
    ])->assertCreated();

    // 409, not 422: the request is well formed, the state of the world forbids it. Cascading would
    // silently delete a transaction a past year's income was computed from.
    $this->actingAs($user)
        ->deleteJson("/api/v1/subjects/{$subjectId}/wallet/accounts/{$accountId}")
        ->assertStatus(409)
        ->assertJsonPath('transactions', 1);

    expect(FinancialAccount::query()->whereKey($accountId)->exists())->toBeTrue();
});

it('deletes an account nothing points at', function (): void {
    [$user, $subjectId] = mutationSubject();
    $accountId = openCurrentAccount($this, $user, $subjectId);

    $this->actingAs($user)
        ->deleteJson("/api/v1/subjects/{$subjectId}/wallet/accounts/{$accountId}")
        ->assertStatus(204);

    expect(FinancialAccount::query()->whereKey($accountId)->exists())->toBeFalse();
});

it('never edits or deletes another subject account', function (): void {
    [$userA, $subjectA] = mutationSubject();
    [$userB, $subjectB] = mutationSubject();

    $accountB = openCurrentAccount($this, $userB, $subjectB);

    $this->actingAs($userA)
        ->patchJson("/api/v1/subjects/{$subjectA}/wallet/accounts/{$accountB}", ['institution_name' => 'X'])
        ->assertNotFound();

    $this->actingAs($userA)
        ->deleteJson("/api/v1/subjects/{$subjectA}/wallet/accounts/{$accountB}")
        ->assertNotFound();
});

it('edits and deletes a transaction, refusing a zero amount', function (): void {
    [$user, $subjectId] = mutationSubject();
    $accountId = openCurrentAccount($this, $user, $subjectId);

    $created = $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions", [
        'account_id' => $accountId,
        'amount_minor' => -4550,
        'currency_code' => 'RON',
        'booked_at' => '2026-02-03',
        'description' => 'Cumpărături',
    ])->assertCreated();

    $transactionId = idFrom($created->json('id'));
    expect($transactionId)->not->toBe('');

    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/wallet/transactions/{$transactionId}", ['amount_minor' => -5000])
        ->assertOk();

    expect(FinancialTransaction::query()->findOrFail($transactionId)->amount_minor)->toBe(-5000);

    // Zero is never a movement of money — refused on edit as it is on create.
    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/wallet/transactions/{$transactionId}", ['amount_minor' => 0])
        ->assertStatus(422);

    $this->actingAs($user)
        ->deleteJson("/api/v1/subjects/{$subjectId}/wallet/transactions/{$transactionId}")
        ->assertStatus(204);

    expect(FinancialTransaction::query()->whereKey($transactionId)->exists())->toBeFalse();
});

it('writes an audit entry for every wallet change', function (): void {
    [$user, $subjectId] = mutationSubject();
    $accountId = openCurrentAccount($this, $user, $subjectId);

    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/wallet/accounts/{$accountId}", ['institution_name' => 'ING'])
        ->assertOk();

    $this->actingAs($user)
        ->deleteJson("/api/v1/subjects/{$subjectId}/wallet/accounts/{$accountId}")
        ->assertStatus(204);

    /** @var list<string> $types */
    $types = DB::table('audit_events')
        ->where('subject_id', $subjectId)
        ->orderBy('sequence')
        ->pluck('event_type')
        ->all();

    // An edit nobody recorded is indistinguishable from an edit that never happened.
    expect($types)->toContain('WalletAccountOpened')
        ->toContain('WalletAccountUpdated')
        ->toContain('WalletAccountDeleted');

    // The deletion entry outlives the row, so a figure that no longer adds up is still explicable.
    expect(DB::table('audit_events')->where('resource_id', $accountId)->count())->toBeGreaterThanOrEqual(3);
});

it('recomputes a deposit schedule when its terms change, leaving settled periods alone', function (): void {
    [$user, $subjectId] = mutationSubject();

    $created = $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'bank_id' => BankFactory::new()->createOne()->id,
        'kind' => 'TERM_DEPOSIT',
        'currency_code' => 'RON',
        // A year from today: a deposit must mature after today, and a fixed date would make this
        // test pass only until that day arrives.
        'starts_at' => now('UTC')->toDateString(),
        'maturity_at' => now('UTC')->addYear()->toDateString(),
        'principal_minor' => 100_000_00,
        'annual_rate' => '6.00',
        'rate_type' => 'FIXED',
        'interest_method' => 'SIMPLE',
        'payment_frequency' => 'AT_MATURITY',
    ])->assertCreated();

    $accountId = idFrom($created->json('id'));
    expect($accountId)->not->toBe('');

    $before = (int) DB::table('deposit_interest_events')->where('account_id', $accountId)->sum('gross_interest_minor');

    // Doubling the principal must double the interest — the stored schedule was computed from the
    // old figure and would otherwise stay wrong for the life of the deposit.
    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/wallet/accounts/{$accountId}", ['principal_minor' => 200_000_00])
        ->assertOk();

    $after = (int) DB::table('deposit_interest_events')->where('account_id', $accountId)->sum('gross_interest_minor');

    expect(DepositTerms::query()->findOrFail($accountId)->principal_minor)->toBe(200_000_00)
        ->and($after)->toBeGreaterThan($before);
});

it('reports a balance per currency, from the transactions alone', function (): void {
    [$user, $subjectId] = mutationSubject();
    $accountId = openCurrentAccount($this, $user, $subjectId);

    foreach ([['RON', -4550], ['RON', 100_000], ['EUR', 20_000]] as [$currency, $amount]) {
        $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions", [
            'account_id' => $accountId,
            'amount_minor' => $amount,
            'currency_code' => $currency,
            'booked_at' => '2026-02-03',
            'description' => 'Mișcare',
        ])->assertCreated();
    }

    // The regression this pins: every `amount_minor` used to be summed regardless of currency and
    // labelled with the account's, so this source would have reported 115.450 RON — a number that
    // is not any quantity of anything.
    $response = $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/accounts")->assertOk();

    /** @var list<array{currency_code: string, amount_minor: int}> $balances */
    $balances = $response->json('accounts.0.balances');

    expect($balances)->toHaveCount(2)
        ->and(array_column($balances, 'amount_minor', 'currency_code'))
        ->toBe(['EUR' => 20_000, 'RON' => 95_450]);
});

it('reports nothing for a source that has never been used', function (): void {
    [$user, $subjectId] = mutationSubject();
    openCurrentAccount($this, $user, $subjectId);

    // An empty list, not a zero. A source with no transactions has an *unknown* balance, and zero
    // would be a claim about its contents that nothing supports.
    $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/accounts")->assertOk()
        ->assertJsonPath('accounts.0.balances', []);
});

it('declares a cash holding as one transaction and refuses any other', function (): void {
    [$user, $subjectId] = mutationSubject();

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'display_name' => 'Numerar acasă',
        'kind' => 'CASH',
        'currency_code' => 'RON',
        'holding_amount_minor' => 500_000,
    ])->assertCreated();

    /** @var list<array{id: string, kind: string, balances: list<array{amount_minor: int}>}> $accounts */
    $accounts = $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/accounts")->json('accounts');
    $cash = array_values(array_filter($accounts, static fn (array $row): bool => $row['kind'] === 'CASH'))[0];

    // The declaration *is* the transaction, so the source reports its value with nothing typed.
    expect($cash['balances'])->toBe([['currency_code' => 'RON', 'amount_minor' => 500_000]]);

    // And nothing may add to it: a cash holding that also contains hand-typed movements is no
    // longer a statement of what is held.
    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions", [
        'account_id' => $cash['id'],
        'amount_minor' => 1_000,
        'currency_code' => 'RON',
        'booked_at' => '2026-02-03',
        'description' => 'Nu ar trebui',
    ])->assertStatus(422);

    // Editing the source edits that one row rather than adding a second.
    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/wallet/accounts/{$cash['id']}", ['holding_amount_minor' => 750_000])
        ->assertOk();

    /** @var list<array{kind: string, balances: list<array{amount_minor: int}>}> $after */
    $after = $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/accounts")->json('accounts');
    $updated = array_values(array_filter($after, static fn (array $row): bool => $row['kind'] === 'CASH'))[0];

    expect($updated['balances'])->toBe([['currency_code' => 'RON', 'amount_minor' => 750_000]]);
});

it('refuses a transaction recorded against a term deposit', function (): void {
    [$user, $subjectId] = mutationSubject();

    $created = $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'bank_id' => BankFactory::new()->createOne()->id,
        'kind' => 'TERM_DEPOSIT',
        'currency_code' => 'RON',
        // A year from today: a deposit must mature after today, and a fixed date would make this
        // test pass only until that day arrives.
        'starts_at' => now('UTC')->toDateString(),
        'maturity_at' => now('UTC')->addYear()->toDateString(),
        'principal_minor' => 100_000_00,
        'annual_rate' => '6.00',
        'rate_type' => 'FIXED',
        'interest_method' => 'SIMPLE',
        'payment_frequency' => 'AT_MATURITY',
    ])->assertCreated();

    /** @var string $depositId */
    $depositId = $created->json('id');

    // The bug this pins: the form offered every source, so a deposit could be given an arbitrary
    // credit — and a deposit that says it earns 4% while also holding a typed 5.000 RON is not
    // reporting anything.
    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions", [
        'account_id' => $depositId,
        'amount_minor' => 500_000,
        'currency_code' => 'RON',
        'booked_at' => '2026-02-03',
        'description' => 'Nu ar trebui',
    ])->assertStatus(422);
});

it('takes a deposit’s principal out of the account that funded it', function (): void {
    $startsAt = now('UTC')->toDateString();
    [$user, $subjectId] = mutationSubject();
    $funding = openCurrentAccount($this, $user, $subjectId);

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'bank_id' => BankFactory::new()->createOne()->id,
        'kind' => 'TERM_DEPOSIT',
        'currency_code' => 'RON',
        'starts_at' => $startsAt,
        'principal_minor' => 5000000,
        'maturity_at' => now('UTC')->addYear()->toDateString(),
        'annual_rate' => '6.5000',
        'rate_type' => 'FIXED',
        'interest_method' => 'SIMPLE',
        'payment_frequency' => 'AT_MATURITY',
        'funding_account_id' => $funding,
    ])->assertCreated();

    $movement = DB::table('financial_transactions')->where('account_id', $funding)->sole();

    // Opening a deposit does not make anybody richer: the principal is declared on the deposit and
    // added to net worth, so without this the same money sat in two places at once.
    expect($movement->amount_minor)->toBe(-5000000)
        // Dated to the day it opened, not today — a deposit entered late still took its money out
        // when it started.
        ->and($movement->booked_at)->toBe($startsAt)
        ->and($movement->classification_status)->toBe('CLASSIFIED');

    expect(DB::table('transaction_splits')->where('transaction_id', $movement->id)->sole()->category_code)
        ->toBe('TRANSFER');
});

it('records no movement for a deposit funded from an account nobody tracks', function (): void {
    $startsAt = now('UTC')->toDateString();
    [$user, $subjectId] = mutationSubject();

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'bank_id' => BankFactory::new()->createOne()->id,
        'kind' => 'TERM_DEPOSIT',
        'currency_code' => 'RON',
        'starts_at' => $startsAt,
        'principal_minor' => 5000000,
        'maturity_at' => now('UTC')->addYear()->toDateString(),
        'annual_rate' => '6.5000',
        'rate_type' => 'FIXED',
        'interest_method' => 'SIMPLE',
        'payment_frequency' => 'AT_MATURITY',
    ])->assertCreated();

    // The one case where the total legitimately jumps: there is no movement to record, because the
    // money came out of somewhere this application has never seen.
    expect(DB::table('financial_transactions')->count())->toBe(0);
});
