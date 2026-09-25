<?php

declare(strict_types=1);

// Namespaced so this file's helper function (below) cannot collide with an identically named
// helper in another test file — PHP still resolves the unqualified it()/expect() calls Pest
// provides globally, since function lookup falls back to the global namespace.

namespace Kern\Platform\Tests\Unit\Settings\SystemEncryptionScopeIsolationTest;

use Kern\Platform\Crypto\AssociatedData;
use Kern\Platform\Crypto\EnvelopeCipher;
use Kern\Platform\Crypto\Exceptions\EnvelopeException;
use Kern\Platform\Settings\Support\SystemEncryptionScope;

/**
 * `docs/adr/0013-system-settings-scope-and-admin-gate.md` §1's central guarantee, proven directly
 * against the real `Kern\Platform\Crypto\EnvelopeCipher` — no fakes, no database, no container: a
 * system-scoped ciphertext must never be decryptable as a subject-scoped one, and a
 * subject-scoped ciphertext must never be decryptable as a system-scoped one. Pure unit test, per
 * `docs/CODE-QUALITY.md` §5 ("no database, no HTTP, no clock" for this kind of guarantee).
 */
function randomDek(): string
{
    return random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
}

it('is not a UUID, so it can never collide with a real tax_subject_id or user id', function (): void {
    expect(SystemEncryptionScope::ID)->toBe('system')
        ->and(preg_match('/^[0-9a-f-]{36}$/i', SystemEncryptionScope::ID))->toBe(0);
});

it('cannot decrypt a system-scoped ciphertext using a subject-scoped key and AAD', function (): void {
    $systemDek = randomDek();
    $subjectDek = randomDek();
    $subjectId = '01930000-0000-7000-8000-000000000001';

    $sealedAsSystem = EnvelopeCipher::seal(
        'sk-super-secret-api-key',
        $systemDek,
        AssociatedData::for(SystemEncryptionScope::ID, 'settings', 'value', 'ai.api_key'),
    );

    expect(fn () => EnvelopeCipher::open(
        $sealedAsSystem,
        $subjectDek,
        AssociatedData::for($subjectId, 'settings', 'value', 'ai.api_key'),
    ))->toThrow(EnvelopeException::class);
});

it('cannot decrypt a subject-scoped ciphertext using the system key and AAD', function (): void {
    $systemDek = randomDek();
    $subjectDek = randomDek();
    $subjectId = '01930000-0000-7000-8000-000000000002';

    $sealedAsSubject = EnvelopeCipher::seal(
        '1234567890123',
        $subjectDek,
        AssociatedData::for($subjectId, 'pf_profiles', 'cnp', 'a3f1c2d4-0000-7000-8000-000000000003'),
    );

    expect(fn () => EnvelopeCipher::open(
        $sealedAsSubject,
        $systemDek,
        AssociatedData::for(SystemEncryptionScope::ID, 'pf_profiles', 'cnp', 'a3f1c2d4-0000-7000-8000-000000000003'),
    ))->toThrow(EnvelopeException::class);
});

it('rejects the same key with only the AAD subject segment swapped from system to a real subject id', function (): void {
    // Isolates the AAD-binding guarantee itself, independent of the key ever differing: even if
    // an attacker somehow held the exact same key bytes, treating a system-scoped ciphertext as
    // if it belonged to a tenant (or vice versa) must still fail, because the AAD authenticates
    // the leading "who does this belong to" segment.
    $sharedKey = randomDek();

    $sealedAsSystem = EnvelopeCipher::seal(
        'sk-super-secret-api-key',
        $sharedKey,
        AssociatedData::for(SystemEncryptionScope::ID, 'settings', 'value', 'ai.api_key'),
    );

    expect(fn () => EnvelopeCipher::open(
        $sealedAsSystem,
        $sharedKey,
        AssociatedData::for('01930000-0000-7000-8000-000000000004', 'settings', 'value', 'ai.api_key'),
    ))->toThrow(EnvelopeException::class);

    // And the reverse direction of the same AAD-only swap.
    $sealedAsSubject = EnvelopeCipher::seal(
        'sk-super-secret-api-key',
        $sharedKey,
        AssociatedData::for('01930000-0000-7000-8000-000000000004', 'settings', 'value', 'ai.api_key'),
    );

    expect(fn () => EnvelopeCipher::open(
        $sealedAsSubject,
        $sharedKey,
        AssociatedData::for(SystemEncryptionScope::ID, 'settings', 'value', 'ai.api_key'),
    ))->toThrow(EnvelopeException::class);
});

it('round-trips correctly within its own scope, both system and subject', function (): void {
    $systemDek = randomDek();
    $subjectDek = randomDek();
    $subjectId = '01930000-0000-7000-8000-000000000005';

    $sealedAsSystem = EnvelopeCipher::seal(
        'sk-super-secret-api-key',
        $systemDek,
        AssociatedData::for(SystemEncryptionScope::ID, 'settings', 'value', 'ai.api_key'),
    );
    expect(EnvelopeCipher::open(
        $sealedAsSystem,
        $systemDek,
        AssociatedData::for(SystemEncryptionScope::ID, 'settings', 'value', 'ai.api_key'),
    ))->toBe('sk-super-secret-api-key');

    $sealedAsSubject = EnvelopeCipher::seal(
        '1234567890123',
        $subjectDek,
        AssociatedData::for($subjectId, 'pf_profiles', 'cnp', 'a3f1c2d4-0000-7000-8000-000000000003'),
    );
    expect(EnvelopeCipher::open(
        $sealedAsSubject,
        $subjectDek,
        AssociatedData::for($subjectId, 'pf_profiles', 'cnp', 'a3f1c2d4-0000-7000-8000-000000000003'),
    ))->toBe('1234567890123');
});
