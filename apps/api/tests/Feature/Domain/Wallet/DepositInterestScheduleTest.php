<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Wallet\DepositInterestScheduleTest;

use App\Domain\Identity\Models\User;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use App\Domain\Wallet\Actions\GenerateDepositInterestSchedule;
use App\Domain\Wallet\Actions\OpenFinancialAccount;
use App\Domain\Wallet\Actions\RecordDepositInterestWithholding;
use App\Domain\Wallet\DataTransferObjects\DepositRatePeriodInput;
use App\Domain\Wallet\DataTransferObjects\DepositTermsInput;
use App\Domain\Wallet\DataTransferObjects\OpenFinancialAccountInput;
use App\Domain\Wallet\Enums\AccountKind;
use App\Domain\Wallet\Enums\DayCountConvention;
use App\Domain\Wallet\Enums\InterestMethod;
use App\Domain\Wallet\Enums\PaymentFrequency;
use App\Domain\Wallet\Enums\RateType;
use App\Domain\Wallet\Events\DepositInterestEventRecorded;
use App\Domain\Wallet\Exceptions\InvalidWithholdingAmountException;
use App\Domain\Wallet\Models\DepositInterestEvent;
use App\Domain\Wallet\Models\FinancialAccount;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
});

function activatedSubject(): TaxSubject
{
    $subject = TaxSubject::factory()->create();
    $user = User::factory()->create();

    SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::OWNER,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    $context = app(TenantContext::class);
    $context->authorizeAndActivate($user->id, $subject->id);
    app()->instance(TenantContext::class, $context);

    return $subject;
}

function monthlyDeposit(TaxSubject $subject): FinancialAccount
{
    // A deposit is an account with terms — one call, one row per real-world thing.
    return app(OpenFinancialAccount::class)(new OpenFinancialAccountInput(
        taxSubjectId: $subject->id,
        kind: AccountKind::TERM_DEPOSIT,
        displayName: 'Depozit 3 luni',
        currencyCode: 'RON',
        depositTerms: new DepositTermsInput(
            startsAt: '2026-01-01',
            currencyCode: 'RON',
            principalMinor: 100_000,
            maturityAt: '2026-04-01',
            rateType: RateType::FIXED,
            interestMethod: InterestMethod::SIMPLE,
            paymentFrequency: PaymentFrequency::MONTHLY,
            dayCountConvention: DayCountConvention::ACTUAL_365,
            ratePeriods: [new DepositRatePeriodInput('2026-01-01', null, '12')],
        ),
    ));
}

it('persists one deposit_interest_events row per payment period with the hand-computed gross amounts', function (): void {
    Event::fake();
    $subject = activatedSubject();

    // No second call: opening the deposit is what generates the schedule. Everything the
    // calculation needs is fixed at that moment, so there is nothing left for anyone to trigger.
    monthlyDeposit($subject);

    /** @var list<DepositInterestEvent> $events */
    $events = DepositInterestEvent::query()->orderBy('accrual_from')->get()->all();

    expect($events)->toHaveCount(3)
        ->and(array_map(static fn (DepositInterestEvent $e) => $e->gross_interest_minor, $events))->toBe([1019, 921, 1019])
        ->and(array_map(static fn (DepositInterestEvent $e) => $e->net_interest_minor, $events))->toBe([1019, 921, 1019])
        ->and($events[0]->calculation_version)->toBe(GenerateDepositInterestSchedule::CALCULATION_VERSION)
        ->and($events[0]->fx_rate_decimal)->toBeNull();

    Event::assertDispatchedTimes(DepositInterestEventRecorded::class, 3);
});

it('is idempotent: regenerating the same deposit does not duplicate rows', function (): void {
    $subject = activatedSubject();
    $deposit = monthlyDeposit($subject);

    app(GenerateDepositInterestSchedule::class)($deposit);
    app(GenerateDepositInterestSchedule::class)($deposit);

    expect(DepositInterestEvent::query()->count())->toBe(3);
});

it('never recalculates a period that has already been marked paid', function (): void {
    $subject = activatedSubject();
    $deposit = monthlyDeposit($subject);

    $events = app(GenerateDepositInterestSchedule::class)($deposit);
    $firstEvent = $events[0];

    // Simulate the first period having already been paid out, then corrupt its stored gross
    // figure with a sentinel value that the real calculation would never produce.
    $firstEvent->paid_at = Carbon::parse('2026-02-01');
    $firstEvent->gross_interest_minor = 999_999;
    $firstEvent->net_interest_minor = 999_999;
    $firstEvent->save();

    app(GenerateDepositInterestSchedule::class)($deposit);

    $reloaded = DepositInterestEvent::query()->orderBy('accrual_from')->get()->values()->all();

    expect($reloaded[0]->gross_interest_minor)->toBe(999_999)
        // The other two, unpaid periods are still recalculated normally.
        ->and($reloaded[1]->gross_interest_minor)->toBe(921)
        ->and($reloaded[2]->gross_interest_minor)->toBe(1019);
});

it('records withholding and recomputes net interest', function (): void {
    $subject = activatedSubject();
    $deposit = monthlyDeposit($subject);
    $events = app(GenerateDepositInterestSchedule::class)($deposit);

    $updated = app(RecordDepositInterestWithholding::class)($events[0], 102);

    expect($updated->withholding_minor)->toBe(102)
        ->and($updated->net_interest_minor)->toBe(1019 - 102);
});

it('refuses a withholding amount greater than the gross interest', function (): void {
    $subject = activatedSubject();
    $deposit = monthlyDeposit($subject);
    $events = app(GenerateDepositInterestSchedule::class)($deposit);

    app(RecordDepositInterestWithholding::class)($events[0], 999_999);
})->throws(InvalidWithholdingAmountException::class);
