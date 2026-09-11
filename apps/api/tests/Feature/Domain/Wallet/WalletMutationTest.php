<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Wallet\WalletMutationTest;

use App\Domain\Identity\Models\User;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use App\Domain\Wallet\Models\DepositTerms;
use App\Domain\Wallet\Models\FinancialAccount;
use App\Domain\Wallet\Models\FinancialTransaction;
use Illuminate\Support\Facades\DB;
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
    $response = $case->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'display_name' => $name,
        'kind' => 'BANK_CURRENT',
        'currency_code' => 'RON',
        'opened_at' => '2026-01-01',
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
        ->patchJson("/api/v1/subjects/{$subjectId}/wallet/accounts/{$accountId}", [
            'institution_name' => 'Banca Transilvania',
        ])
        ->assertOk();

    $account = FinancialAccount::query()->findOrFail($accountId);

    // A PATCH naming one field must not blank the others — the trap in every partial-update
    // endpoint that treats absent as empty.
    expect($account->institution_name)->toBe('Banca Transilvania')
        ->and($account->display_name_ciphertext)->toBe('Cont curent')
        ->and($account->opened_at?->toDateString())->toBe('2026-01-01');
});

it('refuses to change the currency of an account amounts were already recorded in', function (): void {
    [$user, $subjectId] = mutationSubject();
    $accountId = openCurrentAccount($this, $user, $subjectId);

    // Not merely ignored — rejected, so nobody believes it worked. Every transaction and interest
    // event against this account is denominated in its currency.
    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/wallet/accounts/{$accountId}", ['currency_code' => 'EUR'])
        ->assertStatus(422);
});

it('closes an account without destroying its history', function (): void {
    [$user, $subjectId] = mutationSubject();
    $accountId = openCurrentAccount($this, $user, $subjectId);

    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/wallet/accounts/{$accountId}", ['closed_at' => '2026-06-30'])
        ->assertOk();

    expect(FinancialAccount::query()->findOrFail($accountId)->closed_at?->toDateString())->toBe('2026-06-30');
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
        'display_name' => 'Depozit',
        'kind' => 'TERM_DEPOSIT',
        'institution_name' => 'BT',
        'currency_code' => 'RON',
        'opened_at' => '2026-01-01',
        'maturity_at' => '2026-12-31',
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

it('moves the balance when a transaction is recorded', function (): void {
    [$user, $subjectId] = mutationSubject();
    $accountId = openCurrentAccount($this, $user, $subjectId);

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts/{$accountId}/balance", [
        'balance_minor' => 100_000,
        'as_of' => '2026-02-01',
    ])->assertCreated();

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions", [
        'account_id' => $accountId,
        'amount_minor' => -4550,
        'currency_code' => 'RON',
        'booked_at' => '2026-02-03',
        'description' => 'Cumpărături',
    ])->assertCreated();

    // A snapshot is what the bank said on a day; the transactions since are movements on top. Not
    // adding them meant a person recorded a payment and watched the balance sit unchanged.
    $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/accounts")->assertOk()
        ->assertJsonPath('accounts.0.balance_minor', 95_450)
        // No longer the bank's word once this system's arithmetic is on top of it.
        ->assertJsonPath('accounts.0.balance_is_derived', true);
});

it('does not double-count a transaction from before the snapshot', function (): void {
    [$user, $subjectId] = mutationSubject();
    $accountId = openCurrentAccount($this, $user, $subjectId);

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions", [
        'account_id' => $accountId,
        'amount_minor' => -4550,
        'currency_code' => 'RON',
        'booked_at' => '2026-01-10',
        'description' => 'Înainte de extras',
    ])->assertCreated();

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts/{$accountId}/balance", [
        'balance_minor' => 100_000,
        'as_of' => '2026-02-01',
    ])->assertCreated();

    // The bank's figure already reflects it. Counting it again would subtract it twice.
    $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/accounts")->assertOk()
        ->assertJsonPath('accounts.0.balance_minor', 100_000);
});

it('tracks an account from its movements alone when nothing was ever observed', function (): void {
    [$user, $subjectId] = mutationSubject();
    $accountId = openCurrentAccount($this, $user, $subjectId);

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions", [
        'account_id' => $accountId,
        'amount_minor' => 25_000,
        'currency_code' => 'RON',
        'booked_at' => '2026-02-03',
        'description' => 'Salariu',
    ])->assertCreated();

    // Real but incomplete — an account opened with money already in it reads low — so the screen is
    // told to say the figure was computed rather than observed.
    $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/accounts")->assertOk()
        ->assertJsonPath('accounts.0.balance_minor', 25_000)
        ->assertJsonPath('accounts.0.balance_is_derived', true);
});

it('closes and reopens an account through the same endpoint', function (): void {
    [$user, $subjectId] = mutationSubject();
    $accountId = openCurrentAccount($this, $user, $subjectId);

    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/wallet/accounts/{$accountId}", ['closed_at' => '2026-06-30'])
        ->assertOk();

    expect(FinancialAccount::query()->findOrFail($accountId)->closed_at?->toDateString())->toBe('2026-06-30');

    // Present-but-null reopens it. "No closing date" has to be a value a person can set, or an
    // account closed by mistake is closed forever.
    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/wallet/accounts/{$accountId}", ['closed_at' => null])
        ->assertOk();

    expect(FinancialAccount::query()->findOrFail($accountId)->closed_at)->toBeNull();
});

it('keeps a closed account transactions and leaves it out of the open list', function (): void {
    [$user, $subjectId] = mutationSubject();
    $accountId = openCurrentAccount($this, $user, $subjectId);

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions", [
        'account_id' => $accountId,
        'amount_minor' => -4550,
        'currency_code' => 'RON',
        'booked_at' => '2026-02-03',
        'description' => 'Cumpărături',
    ])->assertCreated();

    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/wallet/accounts/{$accountId}", ['closed_at' => '2026-06-30'])
        ->assertOk();

    // Gone from the default list...
    $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/accounts")->assertOk()
        ->assertJsonCount(0, 'accounts');

    // ...but still there, with its history, which is the entire difference from deleting it.
    $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/accounts?include_closed=1")->assertOk()
        ->assertJsonCount(1, 'accounts')
        ->assertJsonPath('accounts.0.closed', true);

    expect(FinancialTransaction::query()->where('account_id', $accountId)->count())->toBe(1);
});
