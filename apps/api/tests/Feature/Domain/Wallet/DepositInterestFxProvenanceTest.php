<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Wallet\DepositInterestFxProvenanceTest;

use App\Domain\Fx\Models\FxRate;
use App\Domain\Identity\Models\User;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use App\Domain\Subjects\Tenancy\TenantContext;
use App\Domain\Wallet\Actions\GenerateDepositInterestSchedule;
use App\Domain\Wallet\Actions\OpenDeposit;
use App\Domain\Wallet\DataTransferObjects\DepositRatePeriodInput;
use App\Domain\Wallet\DataTransferObjects\OpenDepositInput;
use App\Domain\Wallet\Enums\DayCountConvention;
use App\Domain\Wallet\Enums\InterestMethod;
use App\Domain\Wallet\Enums\PaymentFrequency;
use App\Domain\Wallet\Enums\RateType;
use App\Domain\Wallet\Services\Fx\DepositInterestFxAnnotator;
use Database\Factories\FxSourceFactory;
use Database\Factories\SourceDocumentVersionFactory;
use Illuminate\Support\Facades\Artisan;

/**
 * `BLUEPRINT.md` §7.5 / this milestone's stated definition of done: a foreign-currency deposit's
 * interest event carries a complete FX provenance chain — amount, currency, rate, date, source,
 * method, and result — all on the same row.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
});

it('attaches a full FX provenance chain to a non-RON deposit\'s interest event', function (): void {
    $subject = TaxSubject::factory()->create();
    $user = User::factory()->create();

    SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::OWNER,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    $context = new TenantContext();
    $context->authorizeAndActivate($user->id, $subject->id);
    app()->instance(TenantContext::class, $context);

    $fxSource = FxSourceFactory::new()->createOne(['code' => 'BNR']);
    $sourceVersion = SourceDocumentVersionFactory::new()->createOne([
        'content_sha256' => str_repeat('b', 64),
        'raw_object_path' => 'sources/bnr/eur-2027-01-01',
    ]);

    FxRate::query()->create([
        'fx_source_id' => $fxSource->id,
        'rate_date' => '2027-01-01',
        'currency_code' => 'EUR',
        'multiplier' => 1,
        'rate_decimal' => '4.976400',
        'source_document_version_id' => $sourceVersion->id,
    ]);

    $deposit = app(OpenDeposit::class)(new OpenDepositInput(
        taxSubjectId: $subject->id,
        principalMinor: 100_000, // 1000.00 EUR
        currencyCode: 'EUR',
        openedAt: '2026-01-01',
        maturityAt: '2027-01-01',
        rateType: RateType::FIXED,
        interestMethod: InterestMethod::SIMPLE,
        paymentFrequency: PaymentFrequency::AT_MATURITY,
        dayCountConvention: DayCountConvention::ACTUAL_365,
        ratePeriods: [new DepositRatePeriodInput('2026-01-01', null, '10')],
    ));

    $events = app(GenerateDepositInterestSchedule::class)($deposit);

    expect($events)->toHaveCount(1);
    $event = $events[0];
    $fxRateDate = $event->fx_rate_date;
    expect($fxRateDate)->not->toBeNull();
    assert($fxRateDate !== null);

    // Exactly one non-leap year at 10% p.a. on 1000.00 EUR = exactly 100.00 EUR = 10 000 minor units.
    expect($event->gross_interest_minor)->toBe(10_000)
        ->and($event->currency_code)->toBe('EUR')
        ->and($event->fx_source_code)->toBe('BNR')
        ->and($event->fx_method)->toBe(DepositInterestFxAnnotator::METHOD_SPOT_ON_ACCRUAL_END)
        ->and($fxRateDate->format('Y-m-d'))->toBe('2027-01-01')
        ->and($event->fx_rate_decimal)->toBe('4.9764000000')
        ->and($event->fx_content_sha256)->toBe(str_repeat('b', 64))
        ->and($event->fx_source_document_version_id)->toBe($sourceVersion->id)
        // 10 000 minor units * 4.9764 = 49 764.0000 exactly — no rounding ambiguity.
        ->and($event->ron_equivalent_minor)->toBe(49_764);
});

it('never attaches FX provenance to a RON deposit\'s interest event', function (): void {
    $subject = TaxSubject::factory()->create();
    $user = User::factory()->create();

    SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::OWNER,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    $context = new TenantContext();
    $context->authorizeAndActivate($user->id, $subject->id);
    app()->instance(TenantContext::class, $context);

    $deposit = app(OpenDeposit::class)(new OpenDepositInput(
        taxSubjectId: $subject->id,
        principalMinor: 100_000,
        currencyCode: 'RON',
        openedAt: '2026-01-01',
        maturityAt: '2027-01-01',
        rateType: RateType::FIXED,
        interestMethod: InterestMethod::SIMPLE,
        paymentFrequency: PaymentFrequency::AT_MATURITY,
        dayCountConvention: DayCountConvention::ACTUAL_365,
        ratePeriods: [new DepositRatePeriodInput('2026-01-01', null, '10')],
    ));

    $events = app(GenerateDepositInterestSchedule::class)($deposit);

    expect($events[0]->fx_rate_decimal)->toBeNull()
        ->and($events[0]->ron_equivalent_minor)->toBeNull();
});
