<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Wallet\DepositLifecycleTest;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\TaxRomania\Database\Factories\BankFactory;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\Wallet\Actions\CloseMaturedDeposits;
use Kern\TaxRomania\Wallet\Actions\PostMaturedDepositInterest;
use Kern\TaxRomania\Wallet\Enums\AccountKind;
use Kern\TaxRomania\Wallet\Enums\RecordSource;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;
use Tests\Fakes\InMemoryKeyManagementService;
use Tests\Fakes\InMemorySubjectDekRepository;
use Tests\TestCase;

/**
 * A term deposit from opening to closing, and what it writes on the accounts around it.
 *
 * Every test here is a flow that used to be wrong: an edit that doubled the interest, a maturity
 * that could already have passed, a funding transaction that ignored the deposit it belonged to,
 * money that vanished at maturity, and a deposit's own rows that anyone could edit out from under it.
 *
 * Dates are relative to today throughout. A deposit must mature after today, so a fixed calendar
 * date would make this file pass until that day and fail from then on.
 */
beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();

    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemorySubjectDekRepository()),
    );
});

/** Today plus an offset, at the start of the day — the one clock every date in this file reads. */
function day(int $months = 0, int $days = 0): CarbonImmutable
{
    return CarbonImmutable::now('UTC')->startOfDay()->addMonths($months)->addDays($days);
}

/** @return array{0: User, 1: string} */
function taxpayer(): array
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

function currentAccount(TestCase $case, User $user, string $subjectId): string
{
    $id = $case->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'display_name' => 'Cont curent',
        'kind' => 'OTHER',
        'currency_code' => 'RON',
    ])->assertCreated()->json('id');

    return is_string($id) ? $id : '';
}

/**
 * A six-month deposit opened today, unless told otherwise.
 *
 * @param  array<string, mixed>  $overrides
 */
function deposit(TestCase $case, User $user, string $subjectId, array $overrides = []): string
{
    $id = $case->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'bank_id' => BankFactory::new()->createOne()->id,
        'kind' => 'TERM_DEPOSIT',
        'currency_code' => 'RON',
        'starts_at' => day()->toDateString(),
        'principal_minor' => 5000000,
        'maturity_at' => day(6)->toDateString(),
        'annual_rate' => '6.0000',
        'rate_type' => 'FIXED',
        'interest_method' => 'SIMPLE',
        'payment_frequency' => 'AT_MATURITY',
        ...$overrides,
    ])->assertCreated()->json('id');

    return is_string($id) ? $id : '';
}

it('replaces the interest schedule when the term changes, instead of adding a second one', function (): void {
    [$user, $subjectId] = taxpayer();
    $depositId = deposit($this, $user, $subjectId, ['maturity_at' => day(12)->toDateString()]);

    $yearLong = (int) DB::table('deposit_interest_events')->where('account_id', $depositId)->sum('gross_interest_minor');

    // Shortened to six months. Periods are matched by their dates, so the new maturity produced a
    // period that matched nothing — and the old one stayed, summed alongside it.
    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/wallet/accounts/{$depositId}", ['maturity_at' => day(6)->toDateString()])
        ->assertOk();

    $events = DB::table('deposit_interest_events')->where('account_id', $depositId)->get();

    expect($events)->toHaveCount(1)
        ->and((int) DB::table('deposit_interest_events')->where('account_id', $depositId)->sum('gross_interest_minor'))->toBeLessThan($yearLong)
        ->and($events->first()?->accrual_to)->toBe(day(6)->toDateString());
});

it('refuses a deposit whose maturity has already passed', function (): void {
    [$user, $subjectId] = taxpayer();

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
            'bank_id' => BankFactory::new()->createOne()->id,
            'kind' => 'TERM_DEPOSIT',
            'currency_code' => 'RON',
            'starts_at' => day(-12)->toDateString(),
            'principal_minor' => 5000000,
            'maturity_at' => day(-1)->toDateString(),
            'annual_rate' => '6.0000',
            'rate_type' => 'FIXED',
            'interest_method' => 'SIMPLE',
            'payment_frequency' => 'AT_MATURITY',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['maturity_at']);
});

it('keeps the funding transaction in step with the deposit, and removes it with the deposit', function (): void {
    [$user, $subjectId] = taxpayer();
    $fundingId = currentAccount($this, $user, $subjectId);
    $depositId = deposit($this, $user, $subjectId, ['funding_account_id' => $fundingId]);

    $funding = static fn () => DB::table('financial_transactions')->where('account_id', $fundingId)->get();

    expect($funding())->toHaveCount(1)
        ->and($funding()->first()?->amount_minor)->toBe(-5000000)
        ->and($funding()->first()?->source)->toBe(RecordSource::SYSTEM->value);

    // A new principal is a different amount out — the same row, not a second one beside the first.
    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/wallet/accounts/{$depositId}", ['principal_minor' => 6000000])
        ->assertOk();

    expect($funding())->toHaveCount(1)
        ->and($funding()->first()?->amount_minor)->toBe(-6000000)
        ->and(DB::table('transaction_splits')->where('transaction_id', $funding()->first()?->id)->value('amount_minor'))->toBe(-6000000);

    // No funding account, no movement.
    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/wallet/accounts/{$depositId}", ['funding_account_id' => null])
        ->assertOk();

    expect($funding())->toHaveCount(0);

    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/wallet/accounts/{$depositId}", ['funding_account_id' => $fundingId])
        ->assertOk();

    // Deleting the deposit takes the money it moved with it; left behind, the funding account
    // would show the principal gone for good.
    $this->actingAs($user)
        ->deleteJson("/api/v1/subjects/{$subjectId}/wallet/accounts/{$depositId}")
        ->assertNoContent();

    expect($funding())->toHaveCount(0);
});

it('closes a matured deposit and returns its principal and interest to where they came from', function (): void {
    [$user, $subjectId] = taxpayer();
    $fundingId = currentAccount($this, $user, $subjectId);
    $depositId = deposit($this, $user, $subjectId, ['funding_account_id' => $fundingId]);

    $dayAfterMaturity = day(6, 1);
    app(PostMaturedDepositInterest::class)($dayAfterMaturity);
    app(CloseMaturedDeposits::class)($dayAfterMaturity);
    // Idempotent: a closed deposit is never settled twice.
    app(CloseMaturedDeposits::class)($dayAfterMaturity);

    $interest = (int) DB::table('deposit_interest_events')->where('account_id', $depositId)->sum('net_interest_minor');
    $terms = DB::table('deposit_terms')->where('account_id', $depositId)->first();
    $fundingRows = DB::table('financial_transactions')->where('account_id', $fundingId)->orderBy('amount_minor')->get();

    expect($interest)->toBeGreaterThan(0)
        ->and($terms?->closed_at)->toBe(day(6)->toDateString())
        // Out when it opened, back with the interest when it matured — nothing vanishes.
        ->and($fundingRows->pluck('amount_minor')->all())->toBe([-5000000, 5000000 + $interest])
        ->and($fundingRows->last()?->booked_at)->toBe(day(6)->toDateString())
        // The deposit is emptied: its interest moved on with the principal.
        ->and((int) DB::table('financial_transactions')->where('account_id', $depositId)->sum('amount_minor'))->toBe(0);

    // Recognised once as income when it accrued, and only moved afterwards.
    $categories = DB::table('transaction_splits')
        ->join('financial_transactions', 'financial_transactions.id', '=', 'transaction_splits.transaction_id')
        ->where('financial_transactions.account_id', $depositId)
        ->orderBy('transaction_splits.amount_minor', 'desc')
        ->pluck('transaction_splits.category_code')
        ->all();

    expect($categories)->toBe(['INTEREST', 'TRANSFER']);

    // A settled deposit is a record, not a running product.
    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/wallet/accounts/{$depositId}", ['bank_id' => BankFactory::new()->createOne()->id])
        ->assertStatus(409)
        ->assertJsonPath('reason', 'DEPOSIT_CLOSED');
});

it('refuses to edit, classify or delete a deposit’s own transactions', function (): void {
    [$user, $subjectId] = taxpayer();
    $fundingId = currentAccount($this, $user, $subjectId);
    deposit($this, $user, $subjectId, ['funding_account_id' => $fundingId]);

    $transactionId = DB::table('financial_transactions')->where('account_id', $fundingId)->value('id');
    assert(is_string($transactionId));
    $base = "/api/v1/subjects/{$subjectId}/wallet/transactions/{$transactionId}";

    // Each would leave the deposit saying one thing and its ledger another.
    $this->actingAs($user)->patchJson($base, ['amount_minor' => -1])
        ->assertStatus(409)->assertJsonPath('reason', 'SYSTEM_TRANSACTION');
    $this->actingAs($user)->putJson("{$base}/splits", ['splits' => []])
        ->assertStatus(409)->assertJsonPath('reason', 'SYSTEM_TRANSACTION');
    $this->actingAs($user)->deleteJson($base)
        ->assertStatus(409)->assertJsonPath('reason', 'SYSTEM_TRANSACTION');

    expect(DB::table('financial_transactions')->where('id', $transactionId)->value('amount_minor'))->toBe(-5000000);
});

it('names the source of every row, the general one included', function (): void {
    [$user, $subjectId] = taxpayer();

    // The general source has no stored name — the screen supplies its label — so without the kind
    // every row recorded in it showed an empty account column.
    $general = FinancialAccount::create([
        'tax_subject_id' => $subjectId,
        'kind' => AccountKind::GENERAL,
        'display_name_ciphertext' => null,
        'currency_code' => 'RON',
        'source' => RecordSource::MANUAL,
    ]);

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions", [
        'account_id' => $general->id,
        'amount_minor' => -1340,
        'currency_code' => 'RON',
        'booked_at' => day()->toDateString(),
        'description' => 'Kaufland',
    ])->assertCreated();

    $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/transactions")
        ->assertOk()
        ->assertJsonPath('transactions.0.account_kind', 'GENERAL');
});

it('lets a transaction’s currency be corrected', function (): void {
    [$user, $subjectId] = taxpayer();
    $accountId = currentAccount($this, $user, $subjectId);

    $id = $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions", [
        'account_id' => $accountId,
        'amount_minor' => 27944800,
        'currency_code' => 'RON',
        'booked_at' => day()->toDateString(),
        'description' => 'Dividende',
    ])->assertCreated()->json('id');
    assert(is_string($id));

    // Typed as RON, paid in USD: the same payment with a mistake in it, not a different payment.
    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/wallet/transactions/{$id}", ['currency_code' => 'USD'])
        ->assertOk();

    expect(DB::table('financial_transactions')->where('id', $id)->value('currency_code'))->toBe('USD');
});

it('fixes what posted interest was computed from, and still lets the rate and the maturity move', function (): void {
    [$user, $subjectId] = taxpayer();
    $fundingId = currentAccount($this, $user, $subjectId);
    $depositId = deposit($this, $user, $subjectId, [
        'funding_account_id' => $fundingId,
        'payment_frequency' => 'MONTHLY',
    ]);

    // Past the end of the first month: that month's interest is now a transaction.
    app(PostMaturedDepositInterest::class)(day(1, 5));

    $posted = DB::table('deposit_interest_events')->where('account_id', $depositId)->whereNotNull('transaction_id')->first();
    expect($posted)->not->toBeNull();

    $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/accounts")
        ->assertOk()
        ->assertJsonFragment(['interest_posted' => true]);

    // That transaction is explained by this principal. Changing it now would leave the interest
    // already received computed from a principal the deposit no longer has.
    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/wallet/accounts/{$depositId}", ['principal_minor' => 6000000])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'DEPOSIT_TERMS_LOCKED');

    // The rate and the maturity only reach periods not yet posted.
    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/wallet/accounts/{$depositId}", [
            'annual_rate' => '7.0000',
            'maturity_at' => day(9)->toDateString(),
        ])
        ->assertOk();

    // And the period already paid is exactly what it was.
    expect(DB::table('deposit_interest_events')->where('id', $posted?->id)->value('gross_interest_minor'))
        ->toBe($posted?->gross_interest_minor);
});
