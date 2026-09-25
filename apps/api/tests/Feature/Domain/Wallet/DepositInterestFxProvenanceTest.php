<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Wallet\DepositInterestFxProvenanceTest;

use Illuminate\Support\Facades\Artisan;
use Kern\Legislation\Database\Factories\OfficialSourceFactory;
use Kern\Legislation\Database\Factories\SourceDocumentVersionFactory;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Tenancy\TenantContext;
use Kern\TaxRomania\Fx\Models\FxRate;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\Wallet\Actions\GenerateDepositInterestSchedule;
use Kern\TaxRomania\Wallet\Actions\OpenFinancialAccount;
use Kern\TaxRomania\Wallet\DataTransferObjects\DepositRatePeriodInput;
use Kern\TaxRomania\Wallet\DataTransferObjects\DepositTermsInput;
use Kern\TaxRomania\Wallet\DataTransferObjects\OpenFinancialAccountInput;
use Kern\TaxRomania\Wallet\Enums\AccountKind;
use Kern\TaxRomania\Wallet\Enums\DayCountConvention;
use Kern\TaxRomania\Wallet\Enums\InterestMethod;
use Kern\TaxRomania\Wallet\Enums\PaymentFrequency;
use Kern\TaxRomania\Wallet\Enums\RateType;
use Kern\TaxRomania\Wallet\Services\Fx\DepositInterestFxAnnotator;

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

    $context = app(TenantContext::class);
    $context->authorizeAndActivate($user->id, $subject->id);
    app()->instance(TenantContext::class, $context);

    $fxSource = OfficialSourceFactory::new()->createOne(['code' => 'BNR']);
    $sourceVersion = SourceDocumentVersionFactory::new()->createOne([
        'content_sha256' => str_repeat('b', 64),
        'raw_object_path' => 'sources/bnr/eur-2027-01-01',
    ]);

    FxRate::query()->create([
        'official_source_id' => $fxSource->id,
        'rate_date' => '2027-01-01',
        'currency_code' => 'EUR',
        'multiplier' => 1,
        'rate_decimal' => '4.976400',
        'source_document_version_id' => $sourceVersion->id,
    ]);

    // A deposit is an account with terms — the account owns the currency and opening date, the
    // terms own the principal, maturity and rate.
    $deposit = app(OpenFinancialAccount::class)(new OpenFinancialAccountInput(
        taxSubjectId: $subject->id,
        kind: AccountKind::TERM_DEPOSIT,
        displayName: 'Depozit',
        currencyCode: 'EUR',
        depositTerms: new DepositTermsInput(
            startsAt: '2026-01-01',
            currencyCode: 'EUR',
            principalMinor: 100_000, // 1000.00 EUR
            maturityAt: '2027-01-01',
            rateType: RateType::FIXED,
            interestMethod: InterestMethod::SIMPLE,
            paymentFrequency: PaymentFrequency::AT_MATURITY,
            dayCountConvention: DayCountConvention::ACTUAL_365,
            ratePeriods: [new DepositRatePeriodInput('2026-01-01', null, '10')],
        ),
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

    $context = app(TenantContext::class);
    $context->authorizeAndActivate($user->id, $subject->id);
    app()->instance(TenantContext::class, $context);

    // A deposit is an account with terms — the account owns the currency and opening date, the
    // terms own the principal, maturity and rate.
    $deposit = app(OpenFinancialAccount::class)(new OpenFinancialAccountInput(
        taxSubjectId: $subject->id,
        kind: AccountKind::TERM_DEPOSIT,
        displayName: 'Depozit',
        currencyCode: 'RON',
        depositTerms: new DepositTermsInput(
            startsAt: '2026-01-01',
            currencyCode: 'RON',
            principalMinor: 100_000,
            maturityAt: '2027-01-01',
            rateType: RateType::FIXED,
            interestMethod: InterestMethod::SIMPLE,
            paymentFrequency: PaymentFrequency::AT_MATURITY,
            dayCountConvention: DayCountConvention::ACTUAL_365,
            ratePeriods: [new DepositRatePeriodInput('2026-01-01', null, '10')],
        ),
    ));

    $events = app(GenerateDepositInterestSchedule::class)($deposit);

    expect($events[0]->fx_rate_decimal)->toBeNull()
        ->and($events[0]->ron_equivalent_minor)->toBeNull();
});
