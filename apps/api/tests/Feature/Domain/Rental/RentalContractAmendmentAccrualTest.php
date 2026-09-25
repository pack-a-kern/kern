<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Rental\RentalContractAmendmentAccrualTest;

use App\Domain\Identity\Models\User;
use App\Domain\Rental\Actions\AmendRentalContract;
use App\Domain\Rental\Actions\CreateRentalContract;
use App\Domain\Rental\Actions\GenerateRentalAccruals;
use App\Domain\Rental\Actions\GetRentalTermsInForce;
use App\Domain\Rental\Actions\RegisterProperty;
use App\Domain\Rental\DataTransferObjects\AmendRentalContractInput;
use App\Domain\Rental\DataTransferObjects\CreateRentalContractInput;
use App\Domain\Rental\DataTransferObjects\RegisterPropertyInput;
use App\Domain\Rental\Enums\PropertyType;
use App\Domain\Rental\Enums\RentalPaymentFrequency;
use App\Domain\Rental\Enums\TenantType;
use App\Domain\Rental\Events\RentalAccrualRecorded;
use App\Domain\Rental\Exceptions\NoContractVersionInForceException;
use App\Domain\Rental\Models\RentalAccrual;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use App\Support\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

/**
 * The hard requirement this milestone states explicitly: a contract amended mid-period must
 * produce correct accruals on both sides of the change, and a query for "the terms in force on
 * date X" must never mix versions.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
});

function activatedAmendmentSubject(): TaxSubject
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

it('generates correct accruals on both sides of a mid-period amendment', function (): void {
    Event::fake([RentalAccrualRecorded::class]);

    $subject = activatedAmendmentSubject();

    $property = app(RegisterProperty::class)(new RegisterPropertyInput(
        taxSubjectId: $subject->id,
        type: PropertyType::APARTMENT,
        address: 'Sole-owner test property',
        ownershipPercentDecimal: '100.00',
    ));

    $contract = app(CreateRentalContract::class)(new CreateRentalContractInput(
        taxSubjectId: $subject->id,
        propertyId: $property->id,
        tenantType: TenantType::PF,
        tenantIdentity: 'Original tenant',
        startDate: '2026-01-01',
        currencyCode: 'RON',
        rentMinor: 100_000, // 1000.00 RON
        paymentFrequency: RentalPaymentFrequency::MONTHLY,
    ));

    $newVersion = app(AmendRentalContract::class)(new AmendRentalContractInput(
        rentalContractId: $contract->id,
        taxSubjectId: $subject->id,
        effectiveFrom: '2026-02-15',
        rentMinor: 150_000, // 1500.00 RON
        paymentFrequency: RentalPaymentFrequency::MONTHLY,
    ));

    $accruals = app(GenerateRentalAccruals::class)($contract, new DateTimeImmutable('2026-04-01'));

    expect($accruals)->toHaveCount(4);

    $byStart = [];
    foreach ($accruals as $accrual) {
        $byStart[$accrual->period_start->format('Y-m-d')] = $accrual;
    }

    // Before the amendment: two periods, both at the OLD rent (100000), both tied to the old version.
    expect($byStart['2026-01-01']->period_end->format('Y-m-d'))->toBe('2026-02-01')
        ->and($byStart['2026-01-01']->gross_minor)->toBe(100_000)
        ->and($byStart['2026-01-01']->rental_contract_version_id)->not->toBe($newVersion->id);

    expect($byStart['2026-02-01']->period_end->format('Y-m-d'))->toBe('2026-02-15')
        ->and($byStart['2026-02-01']->gross_minor)->toBe(100_000)
        ->and($byStart['2026-02-01']->rental_contract_version_id)->not->toBe($newVersion->id);

    // After the amendment: two periods, both at the NEW rent (150000), both tied to the new version.
    expect($byStart['2026-02-15']->period_end->format('Y-m-d'))->toBe('2026-03-15')
        ->and($byStart['2026-02-15']->gross_minor)->toBe(150_000)
        ->and($byStart['2026-02-15']->rental_contract_version_id)->toBe($newVersion->id);

    expect($byStart['2026-03-15']->period_end->format('Y-m-d'))->toBe('2026-04-01')
        ->and($byStart['2026-03-15']->gross_minor)->toBe(150_000)
        ->and($byStart['2026-03-15']->rental_contract_version_id)->toBe($newVersion->id);

    Event::assertDispatched(RentalAccrualRecorded::class, 4);
});

it('is idempotent across repeated regeneration and leaves nothing duplicated', function (): void {
    $subject = activatedAmendmentSubject();

    $property = app(RegisterProperty::class)(new RegisterPropertyInput(
        taxSubjectId: $subject->id,
        type: PropertyType::APARTMENT,
        address: 'Idempotency test property',
        ownershipPercentDecimal: '100.00',
    ));

    $contract = app(CreateRentalContract::class)(new CreateRentalContractInput(
        taxSubjectId: $subject->id,
        propertyId: $property->id,
        tenantType: TenantType::PF,
        tenantIdentity: 'Idempotency tenant',
        startDate: '2026-01-01',
        currencyCode: 'RON',
        rentMinor: 100_000,
        paymentFrequency: RentalPaymentFrequency::MONTHLY,
    ));

    app(AmendRentalContract::class)(new AmendRentalContractInput(
        rentalContractId: $contract->id,
        taxSubjectId: $subject->id,
        effectiveFrom: '2026-02-15',
        rentMinor: 150_000,
        paymentFrequency: RentalPaymentFrequency::MONTHLY,
    ));

    $generator = app(GenerateRentalAccruals::class);
    $first = $generator($contract, new DateTimeImmutable('2026-04-01'));
    $second = $generator($contract, new DateTimeImmutable('2026-04-01'));

    expect(RentalAccrual::query()->count())->toBe(4)
        ->and(collect($second)->pluck('id')->sort()->values()->all())
        ->toBe(collect($first)->pluck('id')->sort()->values()->all());
});

it('resolves exactly one version in force for any date, never a blend of two', function (): void {
    $subject = activatedAmendmentSubject();

    $property = app(RegisterProperty::class)(new RegisterPropertyInput(
        taxSubjectId: $subject->id,
        type: PropertyType::APARTMENT,
        address: 'Terms-in-force test property',
        ownershipPercentDecimal: '100.00',
    ));

    $contract = app(CreateRentalContract::class)(new CreateRentalContractInput(
        taxSubjectId: $subject->id,
        propertyId: $property->id,
        tenantType: TenantType::PF,
        tenantIdentity: 'Terms-in-force tenant',
        startDate: '2026-01-01',
        currencyCode: 'RON',
        rentMinor: 100_000,
        paymentFrequency: RentalPaymentFrequency::MONTHLY,
    ));

    $newVersion = app(AmendRentalContract::class)(new AmendRentalContractInput(
        rentalContractId: $contract->id,
        taxSubjectId: $subject->id,
        effectiveFrom: '2026-02-15',
        rentMinor: 150_000,
        paymentFrequency: RentalPaymentFrequency::MONTHLY,
    ));

    $resolver = app(GetRentalTermsInForce::class);

    expect($resolver($contract, new DateTimeImmutable('2026-01-15'))->rent_minor)->toBe(100_000)
        ->and($resolver($contract, new DateTimeImmutable('2026-02-14'))->rent_minor)->toBe(100_000)
        ->and($resolver($contract, new DateTimeImmutable('2026-02-15'))->id)->toBe($newVersion->id)
        ->and($resolver($contract, new DateTimeImmutable('2026-06-01'))->rent_minor)->toBe(150_000);

    expect(fn () => $resolver($contract, new DateTimeImmutable('2025-12-31')))
        ->toThrow(NoContractVersionInForceException::class);
});
