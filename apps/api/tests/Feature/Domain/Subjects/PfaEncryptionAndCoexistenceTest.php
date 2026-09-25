<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Subjects\PfaEncryptionAndCoexistenceTest;

use App\Domain\Identity\Models\User;
use App\Domain\Subjects\Actions\RegisterPfaSubject;
use App\Domain\Subjects\Actions\RegisterPfSubject;
use App\Domain\Subjects\DataTransferObjects\RegisterPfaSubjectInput;
use App\Domain\Subjects\DataTransferObjects\RegisterPfSubjectInput;
use App\Domain\Subjects\Enums\FiscalIdentifierKind;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\PfaProfile;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use App\Domain\Wallet\Actions\OpenFinancialAccount;
use App\Domain\Wallet\DataTransferObjects\OpenFinancialAccountInput;
use App\Domain\Wallet\Enums\AccountKind;
use App\Domain\Wallet\Models\FinancialAccount;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kern\Audit\Services\ChainVerifier;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\Platform\Crypto\BlindIndex\ConfigBlindIndexKeyProvider;
use Kern\Platform\KeyManagement\Contracts\KeyManagementService;
use Kern\Platform\Tenancy\TenantContext;
use Tests\Fakes\InMemoryKeyManagementService;

/**
 * `BLUEPRINT.md` §38.B's actual claim — "PFA reuses Identity/Wallet/Documents/Fx/Spv/security
 * infrastructure unchanged" — asserted here as **executable tests**, not narrated in a handoff
 * note. Two concerns:
 *
 * 1. The Milestone 1 "raw dump contains no plaintext" guarantee
 *    (`tests/Feature/Domain/Subjects/RawDatabasePlaintextTest.php`) holds for `pfa_profiles`
 *    exactly as it does for `pf_profiles`, with zero change to `Kern\Platform\Crypto\**`.
 * 2. A single `User` holding both a PF and a PFA `TaxSubject` (`BLUEPRINT.md` §33's literal example)
 *    works with the existing `TenantContext`/`ScopedToTenant` mechanism and an unmodified
 *    `App\Domain\Wallet` action, with no PFA-aware branch anywhere in either.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    app()->instance(KeyManagementService::class, new InMemoryKeyManagementService());

    $provider = new ConfigBlindIndexKeyProvider(
        json_encode(['1' => base64_encode(random_bytes(32))], JSON_THROW_ON_ERROR),
        1,
    );
    app()->instance(BlindIndexHasher::class, new BlindIndexHasher($provider));
});

it('never stores a PFA subject\'s plaintext trade name, registration number, or address anywhere in the raw tables', function (): void {
    $plaintextTradeName = 'Recognisable Consulting PFA';
    $plaintextRegistrationNumber = 'J40/9999/2026-RECOGNISABLE';
    $plaintextAddress = 'Str. Recognisable nr. 7, Sector 2, București';
    $plaintextDisplayName = 'Recognisable Ion PFA';

    $registerer = User::factory()->create();

    $subject = app(RegisterPfaSubject::class)(new RegisterPfaSubjectInput(
        registeringUserId: $registerer->id,
        registeringUserRoles: ['ADMIN'],
        displayName: $plaintextDisplayName,
        tradeName: $plaintextTradeName,
        registrationNumber: $plaintextRegistrationNumber,
        address: $plaintextAddress,
    ));

    /**
     * @var object{
     *     display_name_ciphertext: string, fiscal_identifier_ciphertext: string,
     *     encrypted_dek: string,
     * }|null $subjectRow
     */
    $subjectRow = DB::table('tax_subjects')->where('id', $subject->id)->first();
    /**
     * @var object{trade_name_ciphertext: string, address_ciphertext: string}|null $profileRow
     */
    $profileRow = DB::table('pfa_profiles')->where('tax_subject_id', $subject->id)->first();

    expect($subjectRow)->not->toBeNull()
        ->and($profileRow)->not->toBeNull();
    assert($subjectRow !== null && $profileRow !== null);

    // ADR 0011: pfa_profiles no longer stores the registration number at all — it lives only on
    // tax_subjects.fiscal_identifier_ciphertext, tagged FiscalIdentifierKind::CUI.
    expect(Schema::hasColumn('pfa_profiles', 'registration_number_ciphertext'))->toBeFalse()
        ->and(Schema::hasColumn('pfa_profiles', 'registration_number_blind_index'))->toBeFalse();

    $rawColumns = [
        $subjectRow->display_name_ciphertext,
        $subjectRow->fiscal_identifier_ciphertext,
        $profileRow->trade_name_ciphertext,
        $profileRow->address_ciphertext,
    ];

    $plaintexts = [
        $plaintextDisplayName,
        $plaintextTradeName,
        $plaintextRegistrationNumber,
        $plaintextAddress,
    ];

    foreach ($rawColumns as $column) {
        foreach ($plaintexts as $plaintext) {
            expect($column)->not->toContain($plaintext)
                ->and((string) base64_decode($column, true))->not->toContain($plaintext);
        }
    }

    expect($subjectRow->encrypted_dek)->not->toBeNull()
        ->and(strlen((string) $subjectRow->encrypted_dek))->not->toBe(32);
});

it('decrypts a PFA profile\'s fields back to the original plaintext on read', function (): void {
    $registerer = User::factory()->create();

    $subject = app(RegisterPfaSubject::class)(new RegisterPfaSubjectInput(
        registeringUserId: $registerer->id,
        registeringUserRoles: ['ADMIN'],
        displayName: 'Ana Ionescu PFA',
        tradeName: 'Ionescu Ana Consulting',
        registrationNumber: 'J40/4321/2026',
        caenCode: '7022',
        countyCode: 'CJ',
        locality: 'Cluj-Napoca',
        address: 'Str. Memorandumului nr. 10',
    ));

    $profile = PfaProfile::query()->find($subject->id);

    expect($profile)->not->toBeNull()
        ->and($profile?->trade_name_ciphertext)->toBe('Ionescu Ana Consulting')
        ->and($profile?->caen_code)->toBe('7022')
        ->and($profile?->county_code)->toBe('CJ')
        ->and($profile?->locality_ciphertext)->toBe('Cluj-Napoca')
        ->and($profile?->address_ciphertext)->toBe('Str. Memorandumului nr. 10');

    // ADR 0011: the registration number itself decrypts back from the canonical column.
    expect($subject->fresh()?->fiscal_identifier_ciphertext)->toBe('J40/4321/2026')
        ->and($subject->fresh()?->fiscal_identifier_kind)->toBe(FiscalIdentifierKind::CUI);
});

it('lets one user hold an active PF subject and an active PFA subject at the same time, each independently scoped', function (): void {
    $user = User::factory()->create();

    $pf = app(RegisterPfSubject::class)(new RegisterPfSubjectInput(
        registeringUserId: $user->id,
        registeringUserRoles: ['ADMIN'],
        displayName: 'Maria Georgescu',
        firstName: 'Maria',
        lastName: 'Georgescu',
        cnp: '2900101221144',
        birthDate: '1990-01-01',
    ));

    $pfa = app(RegisterPfaSubject::class)(new RegisterPfaSubjectInput(
        registeringUserId: $user->id,
        registeringUserRoles: ['ADMIN'],
        displayName: 'Maria Georgescu PFA',
        tradeName: 'Georgescu Maria Traduceri',
        registrationNumber: 'J40/1111/2026',
    ));

    expect($pf->id)->not->toBe($pfa->id);

    $memberships = SubjectMembership::query()->where('user_id', $user->id)->get();
    expect($memberships)->toHaveCount(2)
        ->and($memberships->pluck('role')->unique()->all())->toBe([MembershipRole::OWNER]);

    // Both audit chains are independently verifiable — no cross-subject entanglement.
    expect(app(ChainVerifier::class)->verify($pf->id)->ok)->toBeTrue()
        ->and(app(ChainVerifier::class)->verify($pfa->id)->ok)->toBeTrue();

    // TenantContext (App\Domain\Subjects\Tenancy — unmodified by this task) authorizes both,
    // one at a time, exactly as it does for two PF subjects.
    $context = app(TenantContext::class);
    $context->authorizeAndActivate($user->id, $pf->id);
    expect($context->currentTenantId())->toBe($pf->id);

    $context->authorizeAndActivate($user->id, $pfa->id);
    expect($context->currentTenantId())->toBe($pfa->id);
});

it('opens a Wallet financial account for a PFA subject through the unmodified Wallet action', function (): void {
    $user = User::factory()->create();

    $pfa = app(RegisterPfaSubject::class)(new RegisterPfaSubjectInput(
        registeringUserId: $user->id,
        registeringUserRoles: ['ADMIN'],
        displayName: 'Alexandru Dumitrescu PFA',
        tradeName: 'Dumitrescu Alexandru IT',
        registrationNumber: 'J40/2222/2026',
    ));

    $account = app(OpenFinancialAccount::class)(new OpenFinancialAccountInput(
        taxSubjectId: $pfa->id,
        kind: AccountKind::GENERAL,
        displayName: 'Cont curent PFA',
        currencyCode: 'RON',
    ));

    expect($account->tax_subject_id)->toBe($pfa->id);

    // FinancialAccount::query() is scoped by ScopedToTenant (App\Domain\Wallet, unmodified
    // by this task) — reading it back needs the same TenantContext activation any other module's
    // caller would already have performed.
    app(TenantContext::class)->authorizeAndActivate($user->id, $pfa->id);

    $found = FinancialAccount::query()->where('tax_subject_id', $pfa->id)->first();
    expect($found)->not->toBeNull()
        ->and($found?->id)->toBe($account->id)
        ->and($found?->display_name_ciphertext)->toBe('Cont curent PFA');
});
