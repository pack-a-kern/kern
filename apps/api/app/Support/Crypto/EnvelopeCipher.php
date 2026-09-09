<?php

declare(strict_types=1);

namespace App\Support\Crypto;

use App\Support\Crypto\Exceptions\EnvelopeException;

/**
 * Application-level field encryption: XChaCha20-Poly1305 (libsodium), per
 * docs/adr/0002-envelope-encryption.md — chosen over AES-256-GCM because it ships enabled in
 * the target PHP image with no custom build and its 192-bit nonce removes the nonce-reuse
 * foot-gun GCM has at high volume (BLUEPRINT.md §4.2).
 *
 * A stateless, pure, final class — there is exactly one field-encryption algorithm this project
 * uses (ADR 0002 does not make it swappable), so this is a set of static functions rather than
 * an interface with one implementation (docs/CODE-QUALITY.md §1: no abstraction for a single
 * implementation).
 *
 * Wire format (before base64 encoding, all integers big-endian):
 *
 *     byte 0      format version (currently 1)
 *     byte 1      algorithm id (currently 1 = XChaCha20-Poly1305-IETF)
 *     bytes 2-5   key version (uint32) — the DEK generation that encrypted this value, NOT the
 *                 KMS/KEK version (`WrappedDataKey::$kekVersion`). A subject's DEK does not
 *                 change when its wrapping KEK rotates; this field only matters once a future
 *                 milestone adds DEK-level rotation. Every value sealed today carries 1.
 *     bytes 6-29  nonce (24 bytes — crypto_aead_xchacha20poly1305_ietf's nonce length)
 *     remainder   ciphertext with the 16-byte Poly1305 tag appended (libsodium's combined mode)
 *
 * The whole byte string is base64-encoded so it round-trips through a text database column.
 */
final class EnvelopeCipher
{
    private const int FORMAT_VERSION = 1;

    private const int ALGORITHM_XCHACHA20POLY1305_IETF = 1;

    private const int HEADER_BYTES = 6;

    private const int NONCE_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

    private const int KEY_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES;

    private function __construct()
    {
    }

    /**
     * @param string $key raw 32-byte plaintext DEK.
     */
    public static function seal(
        string $plaintext,
        string $key,
        AssociatedData $aad,
        int $keyVersion = 1,
    ): string {
        self::assertKeyLength($key);

        $nonce = random_bytes(self::NONCE_BYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $aad->bytes,
            $nonce,
            $key,
        );

        $header = pack('CCN', self::FORMAT_VERSION, self::ALGORITHM_XCHACHA20POLY1305_IETF, $keyVersion);

        return base64_encode($header . $nonce . $ciphertext);
    }

    /**
     * @param string $key raw 32-byte plaintext DEK.
     *
     * @throws EnvelopeException if the envelope is malformed or authentication fails — including
     *                            the AAD-binding case: a ciphertext moved to a different
     *                            row/column/table/subject fails here, indistinguishably from a
     *                            wrong key or corrupted data (see
     *                            `EnvelopeException::authenticationFailed()`).
     */
    public static function open(
        string $envelope,
        string $key,
        AssociatedData $aad,
    ): string {
        self::assertKeyLength($key);

        $raw = base64_decode($envelope, true);

        if ($raw === false || strlen($raw) < self::HEADER_BYTES + self::NONCE_BYTES) {
            throw EnvelopeException::malformedEnvelope();
        }

        /** @var array{version:int, algorithm:int, keyVersion:int}|false $header */
        $header = unpack('Cversion/Calgorithm/NkeyVersion', substr($raw, 0, self::HEADER_BYTES));

        if ($header === false) {
            throw EnvelopeException::malformedEnvelope();
        }

        if ($header['version'] !== self::FORMAT_VERSION) {
            throw EnvelopeException::unsupportedFormatVersion($header['version']);
        }

        if ($header['algorithm'] !== self::ALGORITHM_XCHACHA20POLY1305_IETF) {
            throw EnvelopeException::unsupportedAlgorithm($header['algorithm']);
        }

        $nonce = substr($raw, self::HEADER_BYTES, self::NONCE_BYTES);
        $ciphertext = substr($raw, self::HEADER_BYTES + self::NONCE_BYTES);

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            $aad->bytes,
            $nonce,
            $key,
        );

        if ($plaintext === false) {
            throw EnvelopeException::authenticationFailed();
        }

        return $plaintext;
    }

    /**
     * The key version embedded in an already-sealed envelope, without decrypting it. Used by
     * callers that need to know which DEK generation to fetch before attempting `open()`.
     */
    public static function keyVersionOf(string $envelope): int
    {
        $raw = base64_decode($envelope, true);

        if ($raw === false || strlen($raw) < self::HEADER_BYTES) {
            throw EnvelopeException::malformedEnvelope();
        }

        /** @var array{keyVersion:int}|false $header */
        $header = unpack('Cversion/Calgorithm/NkeyVersion', substr($raw, 0, self::HEADER_BYTES));

        if ($header === false) {
            throw EnvelopeException::malformedEnvelope();
        }

        return $header['keyVersion'];
    }

    private static function assertKeyLength(string $key): void
    {
        if (strlen($key) !== self::KEY_BYTES) {
            throw EnvelopeException::invalidKeyLength(self::KEY_BYTES);
        }
    }
}
