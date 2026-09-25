<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Rental\CoOwnershipAccrualShareTest;

use App\Domain\Identity\Models\User;
use App\Domain\Rental\Actions\CreateRentalContract;
use App\Domain\Rental\Actions\GenerateRentalAccruals;
use App\Domain\Rental\Actions\RegisterProperty;
use App\Domain\Rental\DataTransferObjects\CreateRentalContractInput;
use App\Domain\Rental\DataTransferObjects\RegisterPropertyInput;
use App\Domain\Rental\Enums\PropertyType;
use App\Domain\Rental\Enums\RentalPaymentFrequency;
use App\Domain\Rental\Enums\TenantType;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use DateTimeImmutable;
use Illuminate\Support\Facades\Artisan;
use Kern\Platform\Tenancy\TenantContext;

/**
 * `BLUEPRINT.md` §33: co-ownership is a first-class design constraint — a property may be only
 * partly owned, and only that share is the owner's income. Every `rental_accruals.gross_minor`
 * this module generates must reflect the share, never the full contractual rent.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
});

function activatedCoOwnershipSubject(): TaxSubject
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

it('generates accruals for only a 50% co-owner\'s share of the full contractual rent', function (): void {
    $subject = activatedCoOwnershipSubject();

    $property = app(RegisterProperty::class)(new RegisterPropertyInput(
        taxSubjectId: $subject->id,
        type: PropertyType::APARTMENT,
        address: 'Co-owned test property',
        ownershipPercentDecimal: '50.00',
    ));

    $contract = app(CreateRentalContract::class)(new CreateRentalContractInput(
        taxSubjectId: $subject->id,
        propertyId: $property->id,
        tenantType: TenantType::PF,
        tenantIdentity: 'Co-ownership tenant',
        startDate: '2026-01-01',
        currencyCode: 'RON',
        rentMinor: 200_000, // full contractual rent: 2000.00 RON
        paymentFrequency: RentalPaymentFrequency::MONTHLY,
    ));

    $accruals = app(GenerateRentalAccruals::class)($contract, new DateTimeImmutable('2026-03-01'));

    expect($accruals)->toHaveCount(2);

    foreach ($accruals as $accrual) {
        // Half of the full rent, never the full 200_000.
        expect($accrual->gross_minor)->toBe(100_000);
    }
});

it('rounds a non-round ownership share correctly for a single owner among several', function (): void {
    $subject = activatedCoOwnershipSubject();

    $property = app(RegisterProperty::class)(new RegisterPropertyInput(
        taxSubjectId: $subject->id,
        type: PropertyType::APARTMENT,
        address: 'Three-way co-owned test property',
        ownershipPercentDecimal: '33.33',
    ));

    $contract = app(CreateRentalContract::class)(new CreateRentalContractInput(
        taxSubjectId: $subject->id,
        propertyId: $property->id,
        tenantType: TenantType::PF,
        tenantIdentity: 'Three-way co-ownership tenant',
        startDate: '2026-01-01',
        currencyCode: 'RON',
        rentMinor: 100_000, // full contractual rent: 1000.00 RON
        paymentFrequency: RentalPaymentFrequency::MONTHLY,
    ));

    $accruals = app(GenerateRentalAccruals::class)($contract, new DateTimeImmutable('2026-02-01'));

    // 100000 * 33.33 / 100 = 33330.00 exactly — this subject's one-third-ish share, never the
    // full 100_000 and never one of the other two owners' shares.
    expect($accruals)->toHaveCount(1)
        ->and($accruals[0]->gross_minor)->toBe(33_330);
});
