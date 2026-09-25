<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Subjects\SubjectDekLifecycleTest;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\Platform\Crypto\BlindIndex\ConfigBlindIndexKeyProvider;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\Contracts\KeyManagementService;
use Kern\TaxRomania\Subjects\Enums\SubjectStatus;
use Kern\TaxRomania\Subjects\Enums\SubjectType;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use RuntimeException;
use Tests\Fakes\InMemoryKeyManagementService;

/**
 * `Kern\Platform\KeyManagement\SubjectDekService`'s "generate on first use, never plaintext"
 * contract, proven against this module's own tables (`tax_subjects`/`users`) through
 * `Kern\TaxRomania\Subjects\Infrastructure\EloquentSubjectDekRepository` — the concrete
 * implementation this task provides for `docs/handoff/005-crypto-core.md`'s
 * `SubjectDekRepository` contract.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    app()->instance(KeyManagementService::class, new InMemoryKeyManagementService());

    $blindIndexProvider = new ConfigBlindIndexKeyProvider(
        json_encode(['1' => base64_encode(random_bytes(32))], JSON_THROW_ON_ERROR),
        1,
    );
    app()->instance(BlindIndexHasher::class, new BlindIndexHasher($blindIndexProvider));
});

/**
 * @return object{encrypted_dek: string|null, dek_kek_version: int|null}
 */
function dekRow(string $table, string $id): object
{
    /** @var object{encrypted_dek: string|null, dek_kek_version: int|null}|null $row */
    $row = DB::table($table)->where('id', $id)->first();

    if ($row === null) {
        throw new RuntimeException("No row found in {$table} with id {$id}.");
    }

    return $row;
}

it('has no DEK immediately after a bare TaxSubject insert, before any encrypted field is set', function (): void {
    $subject = new TaxSubject();
    $subject->type = SubjectType::PF;
    $subject->country_code = 'RO';
    $subject->status = SubjectStatus::ACTIVE;
    $subject->save();

    $raw = dekRow('tax_subjects', (string) $subject->id);

    expect($raw->encrypted_dek)->toBeNull()
        ->and($raw->dek_kek_version)->toBeNull();
});

it('creates a wrapped DEK the moment the first encrypted field is written, and reuses it after', function (): void {
    $subject = new TaxSubject();
    $subject->type = SubjectType::PF;
    $subject->country_code = 'RO';
    $subject->status = SubjectStatus::ACTIVE;
    $subject->save();

    $subject->display_name_ciphertext = 'First Write';
    $subject->save();

    $afterFirstWrite = dekRow('tax_subjects', (string) $subject->id);
    expect($afterFirstWrite->encrypted_dek)->not->toBeNull()
        ->and($afterFirstWrite->dek_kek_version)->not->toBeNull();

    $subject->fiscal_identifier_ciphertext = '1900101221144';
    $subject->save();

    $afterSecondWrite = dekRow('tax_subjects', (string) $subject->id);

    // Same DEK reused, not regenerated — one wrapped ciphertext for the subject's whole lifetime.
    expect($afterSecondWrite->encrypted_dek)->toBe($afterFirstWrite->encrypted_dek);
});

it('gives a User its own independent DEK, distinct from any TaxSubject\'s', function (): void {
    $user = User::createWithEmail('independent-dek@example.test');

    $subject = new TaxSubject();
    $subject->type = SubjectType::PF;
    $subject->country_code = 'RO';
    $subject->status = SubjectStatus::ACTIVE;
    $subject->save();
    $subject->display_name_ciphertext = 'Some Subject';
    $subject->save();

    $userRow = dekRow('users', (string) $user->id);
    $subjectRow = dekRow('tax_subjects', (string) $subject->id);

    expect($userRow->encrypted_dek)->not->toBeNull()
        ->and($subjectRow->encrypted_dek)->not->toBeNull()
        ->and($userRow->encrypted_dek)->not->toBe($subjectRow->encrypted_dek);
});
