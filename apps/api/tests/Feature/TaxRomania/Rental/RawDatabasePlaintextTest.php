<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Rental\RawDatabasePlaintextTest;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Tenancy\TenantContext;
use Kern\TaxRomania\Rental\Actions\AmendRentalContract;
use Kern\TaxRomania\Rental\Actions\CreateRentalContract;
use Kern\TaxRomania\Rental\Actions\RegisterProperty;
use Kern\TaxRomania\Rental\DataTransferObjects\AmendRentalContractInput;
use Kern\TaxRomania\Rental\DataTransferObjects\CreateRentalContractInput;
use Kern\TaxRomania\Rental\DataTransferObjects\RegisterPropertyInput;
use Kern\TaxRomania\Rental\Enums\PropertyType;
use Kern\TaxRomania\Rental\Enums\RentalPaymentFrequency;
use Kern\TaxRomania\Rental\Enums\TenantType;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/**
 * `BLUEPRINT.md` §4.3 lists a full address and identifiable counterparties as mandatory to encrypt.
 * A raw dump of `properties`/`rental_contracts`/`rental_contract_versions` must not contain any of
 * the plaintext values that went in — the same style of proof
 * `tests/Feature/TaxRomania/Wallet/RawDatabasePlaintextTest.php` establishes for Wallet.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

function activatedRentalSubject(): TaxSubject
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

it('never stores a property\'s plaintext address or cadastral reference anywhere in the raw row', function (): void {
    $subject = activatedRentalSubject();

    $plaintextAddress = 'Recognisable Street 42, Bucharest';
    $plaintextCadastral = 'CAD-RECOGNISABLE-99887766';

    $property = app(RegisterProperty::class)(new RegisterPropertyInput(
        taxSubjectId: $subject->id,
        type: PropertyType::APARTMENT,
        address: $plaintextAddress,
        ownershipPercentDecimal: '100.00',
        cadastralReference: $plaintextCadastral,
    ));

    /** @var object{address_ciphertext: string, cadastral_ref_ciphertext: string}|null $raw */
    $raw = DB::table('properties')->where('id', $property->id)->first();
    expect($raw)->not->toBeNull();
    assert($raw !== null);

    foreach ([$raw->address_ciphertext, $raw->cadastral_ref_ciphertext] as $column) {
        expect($column)->not->toContain($plaintextAddress)
            ->and($column)->not->toContain($plaintextCadastral)
            ->and((string) base64_decode($column, true))->not->toContain($plaintextAddress)
            ->and((string) base64_decode($column, true))->not->toContain($plaintextCadastral);
    }
});

it('never stores a contract\'s plaintext tenant identity or an amendment\'s plaintext terms anywhere in the raw row', function (): void {
    $subject = activatedRentalSubject();

    $property = app(RegisterProperty::class)(new RegisterPropertyInput(
        taxSubjectId: $subject->id,
        type: PropertyType::APARTMENT,
        address: 'Non-sensitive test address for this test',
        ownershipPercentDecimal: '100.00',
    ));

    $plaintextTenant = 'Recognisable Tenant SRL, CUI 99999999';

    $contract = app(CreateRentalContract::class)(new CreateRentalContractInput(
        taxSubjectId: $subject->id,
        propertyId: $property->id,
        tenantType: TenantType::PJ,
        tenantIdentity: $plaintextTenant,
        startDate: '2026-01-01',
        currencyCode: 'RON',
        rentMinor: 200_000,
        paymentFrequency: RentalPaymentFrequency::MONTHLY,
    ));

    /** @var object{tenant_identity_ciphertext: string}|null $rawContract */
    $rawContract = DB::table('rental_contracts')->where('id', $contract->id)->first();
    expect($rawContract)->not->toBeNull();
    assert($rawContract !== null);

    expect($rawContract->tenant_identity_ciphertext)->not->toContain($plaintextTenant)
        ->and((string) base64_decode($rawContract->tenant_identity_ciphertext, true))->not->toContain($plaintextTenant);

    $plaintextTerms = 'Recognisable amendment note: tenant added a parking space clause.';

    $newVersion = app(AmendRentalContract::class)(new AmendRentalContractInput(
        rentalContractId: $contract->id,
        taxSubjectId: $subject->id,
        effectiveFrom: '2026-06-01',
        rentMinor: 250_000,
        paymentFrequency: RentalPaymentFrequency::MONTHLY,
        terms: $plaintextTerms,
    ));

    /** @var object{terms_ciphertext: string}|null $rawVersion */
    $rawVersion = DB::table('rental_contract_versions')->where('id', $newVersion->id)->first();
    expect($rawVersion)->not->toBeNull();
    assert($rawVersion !== null);

    expect($rawVersion->terms_ciphertext)->not->toContain($plaintextTerms)
        ->and((string) base64_decode($rawVersion->terms_ciphertext, true))->not->toContain($plaintextTerms);
});
