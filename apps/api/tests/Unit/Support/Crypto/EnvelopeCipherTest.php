<?php

declare(strict_types=1);

// Namespaced so this file's helper functions (below) cannot collide with an identically named
// helper in another test file — PHP still resolves the unqualified it()/expect()/describe()
// calls Pest provides globally, since function lookup falls back to the global namespace.

namespace Tests\Unit\Support\Crypto\EnvelopeCipherTest;

use Kern\Platform\Crypto\AssociatedData;
use Kern\Platform\Crypto\EnvelopeCipher;
use Kern\Platform\Crypto\Exceptions\EnvelopeException;

function makeKey(): string
{
    return random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
}

function makeAad(
    string $subjectId = 'subject-a',
    string $table = 'pf_profiles',
    string $field = 'cnp',
    string $recordUuid = 'record-a',
): AssociatedData {
    return AssociatedData::for($subjectId, $table, $field, $recordUuid);
}

it('round-trips plaintext through seal and open', function (): void {
    $key = makeKey();
    $aad = makeAad();

    $envelope = EnvelopeCipher::seal('1234567890123', $key, $aad);

    expect(EnvelopeCipher::open($envelope, $key, $aad))->toBe('1234567890123');
});

it('produces a different envelope on every call (random nonce)', function (): void {
    $key = makeKey();
    $aad = makeAad();

    $first = EnvelopeCipher::seal('same plaintext', $key, $aad);
    $second = EnvelopeCipher::seal('same plaintext', $key, $aad);

    expect($first)->not->toBe($second);
});

it('never contains the plaintext as a byte substring', function (): void {
    $key = makeKey();
    $plaintext = 'a-very-recognisable-cnp-1234567890123';

    $envelope = EnvelopeCipher::seal($plaintext, $key, makeAad());

    expect(base64_decode($envelope, true))->not->toContain($plaintext);
});

it('embeds the key version in the envelope header, readable without decrypting', function (): void {
    $envelope = EnvelopeCipher::seal('value', makeKey(), makeAad(), keyVersion: 7);

    expect(EnvelopeCipher::keyVersionOf($envelope))->toBe(7);
});

it('rejects decryption under a different key', function (): void {
    $aad = makeAad();
    $envelope = EnvelopeCipher::seal('secret', makeKey(), $aad);

    EnvelopeCipher::open($envelope, makeKey(), $aad);
})->throws(EnvelopeException::class);

it('rejects a malformed base64 envelope', function (): void {
    EnvelopeCipher::open('not valid base64!!!', makeKey(), makeAad());
})->throws(EnvelopeException::class);

it('rejects a truncated envelope', function (): void {
    EnvelopeCipher::open(base64_encode('too short'), makeKey(), makeAad());
})->throws(EnvelopeException::class);

it('rejects a key of the wrong length', function (): void {
    EnvelopeCipher::seal('value', 'not-32-bytes', makeAad());
})->throws(EnvelopeException::class);

describe('AAD binding — moving ciphertext must fail to decrypt', function (): void {
    it('fails when the record uuid changes (moved to a different row)', function (): void {
        $key = makeKey();
        $envelope = EnvelopeCipher::seal('value', $key, makeAad(recordUuid: 'record-a'));

        EnvelopeCipher::open($envelope, $key, makeAad(recordUuid: 'record-b'));
    })->throws(EnvelopeException::class);

    it('fails when the field name changes (moved to a different column)', function (): void {
        $key = makeKey();
        $envelope = EnvelopeCipher::seal('value', $key, makeAad(field: 'cnp'));

        EnvelopeCipher::open($envelope, $key, makeAad(field: 'iban'));
    })->throws(EnvelopeException::class);

    it('fails when the table changes (moved to a different table)', function (): void {
        $key = makeKey();
        $envelope = EnvelopeCipher::seal('value', $key, makeAad(table: 'pf_profiles'));

        EnvelopeCipher::open($envelope, $key, makeAad(table: 'rental_contracts'));
    })->throws(EnvelopeException::class);

    it('fails when the subject id changes (moved to a different tax subject)', function (): void {
        $key = makeKey();
        $envelope = EnvelopeCipher::seal('value', $key, makeAad(subjectId: 'subject-a'));

        EnvelopeCipher::open($envelope, $key, makeAad(subjectId: 'subject-b'));
    })->throws(EnvelopeException::class);
});
