<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Subjects\RawDatabasePlaintextTest;

use App\Domain\Identity\Models\User;
use App\Domain\Subjects\Actions\RegisterPfSubject;
use App\Domain\Subjects\DataTransferObjects\RegisterPfSubjectInput;
use App\Support\Crypto\BlindIndex\BlindIndexHasher;
use App\Support\Crypto\BlindIndex\ConfigBlindIndexKeyProvider;
use App\Support\KeyManagement\Contracts\KeyManagementService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fakes\InMemoryKeyManagementService;

/**
 * Milestone 1's definition of done (`docs/handoff/002-laravel-scaffold.md`'s M1 DoD, `BLUEPRINT.md`
 * §4.1/§4.2): a raw dump of these tables must not contain any of the plaintext values that went
 * in, for every field `BLUEPRINT.md` §4.3 lists as mandatory to encrypt.
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

it('never stores a user\'s plaintext email anywhere in the raw users row', function (): void {
    $plaintextEmail = 'ion.popescu.recognisable@example.test';

    $user = User::createWithEmail($plaintextEmail);

    /** @var object{email_ciphertext: string}|null $raw */
    $raw = DB::table('users')->where('id', $user->id)->first();
    expect($raw)->not->toBeNull();
    assert($raw !== null);

    expect($raw->email_ciphertext)->not->toContain($plaintextEmail)
        ->and((string) base64_decode($raw->email_ciphertext, true))->not->toContain($plaintextEmail);
});

it('never stores a PF subject\'s plaintext name, CNP, or address anywhere in the raw tables', function (): void {
    $plaintextCnp = '1900101221144';
    $plaintextFirstName = 'Ioana-Recognisable';
    $plaintextLastName = 'Popescu-Recognisable';
    $plaintextAddress = 'Str. Recognisable nr. 42, Sector 1, București';
    $plaintextDisplayName = 'Ioana-Recognisable Popescu-Recognisable';

    $registerer = User::factory()->create();

    $subject = app(RegisterPfSubject::class)(new RegisterPfSubjectInput(
        registeringUserId: $registerer->id,
        registeringUserRoles: ['ADMIN'],
        displayName: $plaintextDisplayName,
        firstName: $plaintextFirstName,
        lastName: $plaintextLastName,
        cnp: $plaintextCnp,
        birthDate: '1990-01-01',
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
     * @var object{
     *     first_name_ciphertext: string, last_name_ciphertext: string,
     *     address_ciphertext: string,
     * }|null $profileRow
     */
    $profileRow = DB::table('pf_profiles')->where('tax_subject_id', $subject->id)->first();
    expect($subjectRow)->not->toBeNull()
        ->and($profileRow)->not->toBeNull();
    assert($subjectRow !== null && $profileRow !== null);

    // ADR 0011: the CNP lives only on tax_subjects — pf_profiles has no cnp_ciphertext column
    // at all any more, so there is nothing to assert about it there. The schema itself is the
    // "never duplicated anywhere else" guarantee, not just a value comparison.
    expect(Schema::hasColumn('pf_profiles', 'cnp_ciphertext'))->toBeFalse()
        ->and(Schema::hasColumn('pf_profiles', 'cnp_blind_index'))->toBeFalse();

    $rawColumns = [
        $subjectRow->display_name_ciphertext,
        $subjectRow->fiscal_identifier_ciphertext,
        $profileRow->first_name_ciphertext,
        $profileRow->last_name_ciphertext,
        $profileRow->address_ciphertext,
    ];

    $plaintexts = [
        $plaintextDisplayName,
        $plaintextCnp,
        $plaintextFirstName,
        $plaintextLastName,
        $plaintextAddress,
    ];

    foreach ($rawColumns as $column) {
        foreach ($plaintexts as $plaintext) {
            expect($column)->not->toContain($plaintext)
                ->and((string) base64_decode($column, true))->not->toContain($plaintext);
        }
    }

    // The DEK itself is never plaintext either — it's KMS-wrapped opaque ciphertext, never the
    // raw 32-byte key material `App\Support\KeyManagement\SubjectDekService` generated.
    expect($subjectRow->encrypted_dek)->not->toBeNull()
        ->and(strlen((string) $subjectRow->encrypted_dek))->not->toBe(32);
});
