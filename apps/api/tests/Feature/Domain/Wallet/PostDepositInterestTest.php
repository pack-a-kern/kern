<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use App\Domain\Wallet\Actions\OpenFinancialAccount;
use App\Domain\Wallet\Actions\PostMaturedDepositInterest;
use App\Domain\Wallet\DataTransferObjects\DepositRatePeriodInput;
use App\Domain\Wallet\DataTransferObjects\DepositTermsInput;
use App\Domain\Wallet\DataTransferObjects\OpenFinancialAccountInput;
use App\Domain\Wallet\Enums\AccountKind;
use App\Domain\Wallet\Enums\DayCountConvention;
use App\Domain\Wallet\Enums\InterestMethod;
use App\Domain\Wallet\Enums\PaymentFrequency;
use App\Domain\Wallet\Enums\RateType;
use App\Domain\Wallet\Enums\RecordSource;
use App\Domain\Wallet\Models\DepositInterestEvent;
use App\Domain\Wallet\Models\FinancialTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Kern\Platform\Tenancy\TenantContext;
use Kern\Platform\Tenancy\TenantScope;

beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();
});

/** A monthly-paying deposit whose first periods are already in the past. */
function maturedDeposit(): string
{
    Event::fake();

    $subject = TaxSubject::factory()->create();
    $user = User::factory()->create();

    SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::OWNER,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    // Opening the deposit needs a bound context: its display name is encrypted under the subject's
    // own key. Posting the interest deliberately does not — see below.
    $context = app(TenantContext::class);
    $context->authorizeAndActivate($user->id, $subject->id);
    app()->instance(TenantContext::class, $context);

    $account = app(OpenFinancialAccount::class)(new OpenFinancialAccountInput(
        taxSubjectId: (string) $subject->getKey(),
        kind: AccountKind::TERM_DEPOSIT,
        displayName: 'Depozit 12 luni',
        currencyCode: 'RON',
        depositTerms: new DepositTermsInput(
            principalMinor: 100_000_00,
            startsAt: '2026-01-01',
            currencyCode: 'RON',
            maturityAt: '2027-01-01',
            rateType: RateType::FIXED,
            interestMethod: InterestMethod::SIMPLE,
            paymentFrequency: PaymentFrequency::MONTHLY,
            dayCountConvention: DayCountConvention::ACTUAL_365,
            ratePeriods: [new DepositRatePeriodInput('2026-01-01', null, '6')],
        ),
    ));

    // Unbound again on the way out. The nightly task runs from the scheduler with no request and
    // no subject behind it, so every test below exercises that path rather than a convenient one —
    // which is also why the assertions read through `unscoped()` rather than a bound context.
    app()->instance(TenantContext::class, app(TenantContext::class));

    /** @var string $accountId */
    $accountId = $account->getKey();

    return $accountId;
}

/**
 * Reads a subject-scoped model with no tenant bound, which is the state these tests run in.
 *
 * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
 * @return \Illuminate\Database\Eloquent\Builder<covariant \Illuminate\Database\Eloquent\Model>
 */
function unscoped(string $model): \Illuminate\Database\Eloquent\Builder
{
    return $model::query()->withoutGlobalScope(TenantScope::class);
}

it('posts only the periods that have actually closed, never a forecast', function (): void {
    $accountId = maturedDeposit();

    // Three monthly periods have ended by this date; the rest of the year has not happened, and
    // posting a transaction for money that has not arrived would be an invented movement.
    $posted = app(PostMaturedDepositInterest::class)(CarbonImmutable::parse('2026-04-15'));

    expect($posted)->toBe(3)
        ->and(unscoped(FinancialTransaction::class)->where('account_id', $accountId)->count())->toBe(3);

    $unpaid = unscoped(DepositInterestEvent::class)
        ->where('account_id', $accountId)
        ->whereNull('transaction_id')
        ->count();

    expect($unpaid)->toBeGreaterThan(0);
});

it('posts the net amount, on the day the period closed, marked as produced by the system', function (): void {
    $accountId = maturedDeposit();

    app(PostMaturedDepositInterest::class)(CarbonImmutable::parse('2026-04-15'));

    /** @var DepositInterestEvent $event */
    $event = unscoped(DepositInterestEvent::class)
        ->where('account_id', $accountId)
        ->whereNotNull('transaction_id')
        ->orderBy('accrual_to')
        ->firstOrFail();

    /** @var FinancialTransaction $transaction */
    $transaction = unscoped(FinancialTransaction::class)->findOrFail((string) $event->transaction_id);

    expect($transaction->amount_minor)->toBe($event->net_interest_minor)
        ->and($transaction->currency_code)->toBe($event->currency_code)
        // The day the period closed, not the day the task ran: a late run must not move income
        // into a different fiscal year.
        ->and($transaction->booked_at->toDateString())->toBe($event->accrual_to->toDateString())
        // Nobody typed it, and the row should say so.
        ->and($transaction->source)->toBe(RecordSource::SYSTEM)
        ->and($event->paid_at?->toDateString())->toBe($event->accrual_to->toDateString());
});

it('cannot pay the same interest twice', function (): void {
    $accountId = maturedDeposit();

    $first = app(PostMaturedDepositInterest::class)(CarbonImmutable::parse('2026-04-15'));
    $second = app(PostMaturedDepositInterest::class)(CarbonImmutable::parse('2026-04-15'));

    // A missed day is caught up by the next run; a run that happens twice is not.
    expect($first)->toBe(3)
        ->and($second)->toBe(0)
        ->and(unscoped(FinancialTransaction::class)->where('account_id', $accountId)->count())->toBe(3);
});
