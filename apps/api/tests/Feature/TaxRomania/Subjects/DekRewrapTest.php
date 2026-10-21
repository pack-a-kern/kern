<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Subjects\DekRewrapTest;

use Illuminate\Support\Facades\DB;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\Contracts\KeyManagementService;
use Kern\Platform\KeyManagement\DekRewrapService;
use Kern\Platform\KeyManagement\ValueObjects\WrappedDataKey;
use Kern\Platform\Settings\Infrastructure\SystemDekRepository;
use Kern\Platform\Settings\Support\SystemEncryptionScope;
use Kern\TaxRomania\Subjects\Enums\SubjectStatus;
use Kern\TaxRomania\Subjects\Enums\SubjectType;
use Kern\TaxRomania\Subjects\Infrastructure\EloquentSubjectDekRepository;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use RuntimeException;

/**
 * The half of a KEK rotation that used to be missing.
 *
 * Rotating the KEK is non-disruptive precisely because the KMS retains old versions — which is also
 * why it retires nothing on its own: every DEK wrapped before the rotation stays wrapped under the
 * version it was wrapped with. These tests hold the two properties that make finishing it safe: a
 * re-wrapped DEK still opens everything it opened before, and the write can only ever move a row
 * onto a *newer* version of a key it already had.
 */

/** @return object{encrypted_dek: string|null, dek_kek_version: int|null} */
function dek(string $table, string $id): object
{
    /** @var object{encrypted_dek: string|null, dek_kek_version: int|null}|null $row */
    $row = DB::table($table)->where('id', $id)->first(['encrypted_dek', 'dek_kek_version']);

    if ($row === null) {
        throw new RuntimeException("No row in {$table} for {$id}.");
    }

    return $row;
}

it('carries every stored data key onto the new KEK version, and the fields still decrypt', function (): void {
    $user = User::createWithEmail('ion.popescu@example.test');

    // Saved before the encrypted attribute is assigned, the same order `User::createWithEmail()`
    // uses: the cast encrypts on assignment and needs a row to attach the wrapped DEK to.
    $subject = new TaxSubject();
    $subject->type = SubjectType::PF;
    $subject->country_code = 'RO';
    $subject->status = SubjectStatus::ACTIVE;
    $subject->save();

    $subject->display_name_ciphertext = 'Ion Popescu';
    $subject->save();

    expect(dek('users', (string) $user->id)->dek_kek_version)->toBe(1)
        ->and(dek('tax_subjects', (string) $subject->id)->dek_kek_version)->toBe(1);

    app(KeyManagementService::class)->rotateKek();

    $moved = app(DekRewrapService::class)->rewrapAll(200);

    expect($moved)->toBe(2)
        ->and(dek('users', (string) $user->id)->dek_kek_version)->toBe(2)
        ->and(dek('tax_subjects', (string) $subject->id)->dek_kek_version)->toBe(2)
        // The wrapper is new; the key inside it is the same one, which is the whole point — no field
        // is re-encrypted, so a value written before the rotation reads back unchanged after it.
        ->and((string) dek('users', (string) $user->id)->encrypted_dek)->toStartWith('fake:v2:')
        ->and(User::query()->whereKey($user->id)->firstOrFail()->email_ciphertext)->toBe('ion.popescu@example.test')
        ->and(TaxSubject::query()->whereKey($subject->id)->firstOrFail()->display_name_ciphertext)->toBe('Ion Popescu');
});

it('does nothing on a second run, so an interrupted rotation is finished by repeating it', function (): void {
    User::createWithEmail('maria@example.test');

    app(KeyManagementService::class)->rotateKek();

    expect(app(DekRewrapService::class)->rewrapAll(200))->toBe(1)
        ->and(app(DekRewrapService::class)->rewrapAll(200))->toBe(0);
});

it('reports what is still behind, per table and version', function (): void {
    User::createWithEmail('contabil@example.test');
    app(KeyManagementService::class)->rotateKek();

    $counts = app(DekRewrapService::class)->versionCounts();

    expect($counts)->toHaveKeys(['users', 'tax_subjects', 'system_encryption_keys'])
        ->and($counts['users'])->toBe([1 => 1]);

    app(DekRewrapService::class)->rewrapAll(200);

    expect(app(DekRewrapService::class)->versionCounts()['users'])->toBe([2 => 1]);
});

it('will not write a row that has no data key of its own', function (): void {
    // A bare subject: nothing encrypted has been set, so `SubjectDekService` has never been asked for
    // a key and the column is null. Re-wrapping must not be the thing that fills it — only `save()`
    // creates a DEK, and it creates a freshly generated one.
    $subject = new TaxSubject();
    $subject->type = SubjectType::PF;
    $subject->country_code = 'RO';
    $subject->status = SubjectStatus::ACTIVE;
    $subject->save();

    expect(dek('tax_subjects', (string) $subject->id)->encrypted_dek)->toBeNull();

    $moved = (new EloquentSubjectDekRepository())->rewrap(
        (string) $subject->id,
        new WrappedDataKey('fake:v2:not-a-real-key', 2),
    );

    expect($moved)->toBeFalse()
        ->and(dek('tax_subjects', (string) $subject->id)->encrypted_dek)->toBeNull();
});

it('never moves a data key backwards onto an older version', function (): void {
    $user = User::createWithEmail('audit@example.test');
    $kms = app(KeyManagementService::class);
    $kms->rotateKek();
    app(DekRewrapService::class)->rewrapAll(200);

    $current = dek('users', (string) $user->id);
    expect($current->dek_kek_version)->toBe(2);

    // A slower concurrent run, still holding version 1, must not be able to undo this.
    $moved = (new EloquentSubjectDekRepository())->rewrap(
        (string) $user->id,
        new WrappedDataKey('fake:v1:stale', 1),
    );

    expect($moved)->toBeFalse()
        ->and(dek('users', (string) $user->id)->encrypted_dek)->toBe($current->encrypted_dek);
});

it('carries the system-wide data key too, the one that belongs to no taxpayer', function (): void {
    $kms = app(KeyManagementService::class);
    $repository = new SystemDekRepository();

    $repository->save(SystemEncryptionScope::ID, $kms->wrapDataKey(random_bytes(32)));

    $before = $repository->find(SystemEncryptionScope::ID);
    expect($before?->kekVersion)->toBe(1);

    $kms->rotateKek();
    app(DekRewrapService::class)->rewrapAll(200);

    $after = $repository->find(SystemEncryptionScope::ID);

    expect($after?->kekVersion)->toBe(2)
        // Same key material, new wrapper: what the system's secrets are sealed with has not changed.
        ->and($kms->unwrapDataKey($after ?? throw new RuntimeException('No system DEK.')))
        ->toBe($kms->unwrapDataKey($before ?? throw new RuntimeException('No system DEK.')));
});
