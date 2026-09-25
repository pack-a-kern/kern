<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Rental\CrossTenantIsolationTest;

use App\Domain\Rental\Enums\PropertyType;
use App\Domain\Rental\Enums\RentalPaymentFrequency;
use App\Domain\Rental\Enums\TenantType;
use App\Domain\Rental\Models\Property;
use App\Domain\Rental\Models\RentalAccrual;
use App\Domain\Rental\Models\RentalContract;
use App\Domain\Rental\Models\RentalContractVersion;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Tenancy\TenantContext;

/**
 * `docs/AGENTS.md` §21.4 / `BLUEPRINT.md` §5.3: a user authorized for one `TaxSubject` must never
 * reach another subject's row, through any of the five tables this module owns — every one uses
 * `Kern\Platform\Tenancy\ScopedToTenant` — the same test shape
 * `tests/Feature/Domain/Wallet/CrossTenantIsolationTest.php` establishes first.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
});

/**
 * @return array{0: User, 1: TaxSubject}
 */
function rentalOwnerAndSubject(): array
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

    return [$user, $subject];
}

function activateRentalSubject(User $user, TaxSubject $subject): void
{
    $context = app(TenantContext::class);
    $context->authorizeAndActivate($user->id, $subject->id);
    app()->instance(TenantContext::class, $context);
}

/**
 * @return array<string, callable(string): Model>
 */
function rentalRowFactories(): array
{
    return [
        Property::class => static function (string $subjectId): Property {
            return Property::create([
                'tax_subject_id' => $subjectId,
                'type' => PropertyType::APARTMENT,
                'address_ciphertext' => 'Cross-tenant test property',
                'ownership_percent_decimal' => '100.00',
            ]);
        },
        RentalContract::class => static function (string $subjectId): RentalContract {
            $property = Property::create([
                'tax_subject_id' => $subjectId,
                'type' => PropertyType::APARTMENT,
                'address_ciphertext' => 'Contract owner property',
                'ownership_percent_decimal' => '100.00',
            ]);

            return RentalContract::create([
                'tax_subject_id' => $subjectId,
                'property_id' => $property->id,
                'tenant_type' => TenantType::PF,
                'tenant_identity_ciphertext' => 'Cross-tenant test tenant',
                'start_date' => '2026-01-01',
                'currency_code' => 'RON',
            ]);
        },
        RentalContractVersion::class => static function (string $subjectId): RentalContractVersion {
            $property = Property::create([
                'tax_subject_id' => $subjectId,
                'type' => PropertyType::APARTMENT,
                'address_ciphertext' => 'Version owner property',
                'ownership_percent_decimal' => '100.00',
            ]);
            $contract = RentalContract::create([
                'tax_subject_id' => $subjectId,
                'property_id' => $property->id,
                'tenant_type' => TenantType::PF,
                'tenant_identity_ciphertext' => 'Version owner tenant',
                'start_date' => '2026-01-01',
                'currency_code' => 'RON',
            ]);

            return RentalContractVersion::create([
                'tax_subject_id' => $subjectId,
                'rental_contract_id' => $contract->id,
                'valid_from' => '2026-01-01',
                'valid_to' => null,
                'rent_minor' => 100_000,
                'payment_frequency' => RentalPaymentFrequency::MONTHLY,
            ]);
        },
        RentalAccrual::class => static function (string $subjectId): RentalAccrual {
            $property = Property::create([
                'tax_subject_id' => $subjectId,
                'type' => PropertyType::APARTMENT,
                'address_ciphertext' => 'Accrual owner property',
                'ownership_percent_decimal' => '100.00',
            ]);
            $contract = RentalContract::create([
                'tax_subject_id' => $subjectId,
                'property_id' => $property->id,
                'tenant_type' => TenantType::PF,
                'tenant_identity_ciphertext' => 'Accrual owner tenant',
                'start_date' => '2026-01-01',
                'currency_code' => 'RON',
            ]);
            $version = RentalContractVersion::create([
                'tax_subject_id' => $subjectId,
                'rental_contract_id' => $contract->id,
                'valid_from' => '2026-01-01',
                'valid_to' => null,
                'rent_minor' => 100_000,
                'payment_frequency' => RentalPaymentFrequency::MONTHLY,
            ]);

            return RentalAccrual::create([
                'tax_subject_id' => $subjectId,
                'rental_contract_id' => $contract->id,
                'rental_contract_version_id' => $version->id,
                'period_start' => '2026-01-01',
                'period_end' => '2026-02-01',
                'due_date' => '2026-01-01',
                'gross_minor' => 100_000,
            ]);
        },
    ];
}

foreach (rentalRowFactories() as $modelClass => $makeRow) {
    it("never lets one subject see another subject's {$modelClass} row", function () use ($modelClass, $makeRow): void {
        [$userA, $subjectA] = rentalOwnerAndSubject();
        [$userB, $subjectB] = rentalOwnerAndSubject();

        activateRentalSubject($userA, $subjectA);
        $rowA = $makeRow($subjectA->id);

        activateRentalSubject($userB, $subjectB);
        $makeRow($subjectB->id);

        /** @var array<int, Model> $visible */
        $visible = $modelClass::query()->get()->all();

        expect($visible)->toHaveCount(1);

        $visibleSubjectId = $visible[0]->getAttribute('tax_subject_id');
        assert(is_string($visibleSubjectId));
        expect($visibleSubjectId)->toBe($subjectB->id);

        /** @var Model|null $shouldBeInvisible */
        $shouldBeInvisible = $modelClass::find($rowA->getKey());
        expect($shouldBeInvisible)->toBeNull();
    });
}
