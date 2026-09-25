<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Spv\RawDatabasePlaintextTest;

use App\Domain\Spv\Actions\RegisterSpvConnection;
use App\Domain\Spv\DataTransferObjects\RegisterSpvConnectionInput;
use App\Domain\Spv\Enums\SpvAuthMethod;
use App\Domain\Spv\Models\SpvMessage;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kern\Platform\Identity\Enums\MfaMethodType;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Models\UserMfaMethod;

/**
 * `BLUEPRINT.md` §4.1/§4.2/§6.11 — no `spv_connections` column can ever hold a plaintext SPV
 * password (there is no such column at all — asserted directly against the schema, not just "the
 * value we happen to look for isn't there"), and every field `BLUEPRINT.md` §4.3 requires encrypted
 * (SPV username, inbox message title/external id) is genuinely unreadable in a raw dump.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
});

it('has no password column anywhere on spv_connections', function (): void {
    $columns = Schema::getColumnListing('spv_connections');

    foreach ($columns as $column) {
        expect(mb_strtolower($column))->not->toContain('password');
    }
});

it('never stores a plaintext SPV username in the raw spv_connections row', function (): void {
    $user = User::factory()->create();
    $subject = TaxSubject::factory()->create();
    SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::OWNER,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);
    enrollTotpFor($user);

    $plaintextUsername = 'recognisable.owner@example.test';

    $connection = app(RegisterSpvConnection::class)($user, new RegisterSpvConnectionInput(
        taxSubjectId: $subject->id,
        authMethod: SpvAuthMethod::USER_PASSWORD_OTP,
        username: $plaintextUsername,
    ));

    /** @var object{username_ciphertext: string}|null $raw */
    $raw = DB::table('spv_connections')->where('id', $connection->id)->first();
    expect($raw)->not->toBeNull();
    assert($raw !== null);

    expect($raw->username_ciphertext)->not->toContain($plaintextUsername)
        ->and((string) base64_decode($raw->username_ciphertext, true))->not->toContain($plaintextUsername);
});

it('never stores a plaintext ANAF message title or external id in the raw spv_messages row', function (): void {
    $subject = TaxSubject::factory()->create();

    $plaintextTitle = 'Recognisable decision title for subject';
    $plaintextExternalId = 'anaf-external-id-recognisable-42';

    $message = SpvMessage::create([
        'tax_subject_id' => $subject->id,
        'external_id_ciphertext' => $plaintextExternalId,
        'external_id_blind_index' => 'blind-index-fixture',
        'title_ciphertext' => $plaintextTitle,
        'received_at' => now('UTC'),
    ]);

    /** @var object{title_ciphertext: string, external_id_ciphertext: string}|null $raw */
    $raw = DB::table('spv_messages')->where('id', $message->id)->first();
    expect($raw)->not->toBeNull();
    assert($raw !== null);

    foreach (['title_ciphertext' => $plaintextTitle, 'external_id_ciphertext' => $plaintextExternalId] as $column => $plaintext) {
        expect($raw->{$column})->not->toContain($plaintext)
            ->and((string) base64_decode($raw->{$column}, true))->not->toContain($plaintext);
    }
});

function enrollTotpFor(User $user): void
{
    UserMfaMethod::create([
        'user_id' => $user->id,
        'type' => MfaMethodType::TOTP,
        'secret_ciphertext' => 'JBSWY3DPEHPK3PXP',
        'confirmed_at' => now('UTC'),
    ]);
}
