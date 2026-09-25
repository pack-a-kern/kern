<?php

declare(strict_types=1);

namespace Tests\Fakes;

use Kern\Platform\KeyManagement\Contracts\KeyManagementService;
use Kern\Platform\KeyManagement\Exceptions\KeyManagementException;
use Kern\Platform\KeyManagement\ValueObjects\WrappedDataKey;

/**
 * In-memory stand-in for OpenBao's transit engine, for tests that need real wrap/unwrap/rotate
 * semantics without a live KMS. Mirrors the real behaviour that matters:
 *
 *  - encryption always uses the *current* (latest) key version;
 *  - decryption succeeds for a ciphertext produced under any version this instance has ever had
 *    (rotation does not invalidate old ciphertexts — ADR 0002 "Key rotation is scoped");
 *  - the wrapped ciphertext is opaque and self-describes its version, exactly like OpenBao's own
 *    `vault:v<N>:...` format, so `WrappedDataKey::$kekVersion` is derived the same way in both
 *    the real and fake implementations.
 *
 * Lives under tests/, not in `kern/platform` — a test double, not a production driver (ADR 0002
 * has no no-op/fake KMS driver in production; docs/CODE-QUALITY.md forbids code that exists only
 * "for later" in app/).
 */
final class InMemoryKeyManagementService implements KeyManagementService
{
    private const string PREFIX = 'fake';

    /** @var array<int, string> version => raw 32-byte key. */
    private array $keys;

    private int $currentVersion = 1;

    public function __construct()
    {
        $this->keys = [1 => random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)];
    }

    public function wrapDataKey(string $plaintextDek): WrappedDataKey
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintextDek, $nonce, $this->keys[$this->currentVersion]);

        $ciphertextString = sprintf(
            '%s:v%d:%s',
            self::PREFIX,
            $this->currentVersion,
            base64_encode($nonce . $ciphertext),
        );

        return new WrappedDataKey($ciphertextString, $this->currentVersion);
    }

    public function unwrapDataKey(WrappedDataKey $wrapped): string
    {
        if (preg_match('/^fake:v(?<version>\d+):(?<payload>.+)$/', $wrapped->ciphertext, $matches) !== 1) {
            throw KeyManagementException::malformedCiphertext('decrypt');
        }

        $version = (int) $matches['version'];

        if (! isset($this->keys[$version])) {
            throw KeyManagementException::requestFailed('decrypt', 400);
        }

        $raw = base64_decode($matches['payload'], true);

        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw KeyManagementException::malformedCiphertext('decrypt');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->keys[$version]);

        if ($plaintext === false) {
            throw KeyManagementException::requestFailed('decrypt', 400);
        }

        return $plaintext;
    }

    public function currentKekVersion(): int
    {
        return $this->currentVersion;
    }

    public function rotateKek(): int
    {
        $this->currentVersion++;
        $this->keys[$this->currentVersion] = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

        return $this->currentVersion;
    }
}
