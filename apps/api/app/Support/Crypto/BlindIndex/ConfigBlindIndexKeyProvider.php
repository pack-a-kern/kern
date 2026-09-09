<?php

declare(strict_types=1);

namespace App\Support\Crypto\BlindIndex;

use App\Support\Crypto\BlindIndex\Contracts\BlindIndexKeyProvider;
use InvalidArgumentException;

/**
 * Reads the versioned global blind-index key(s) from `config/crypto.php`
 * (`CRYPTO_BLIND_INDEX_KEYS`, a JSON object of `{"<version>": "<base64 32-byte key>"}`) and
 * `CRYPTO_BLIND_INDEX_CURRENT_VERSION`. Deliberately not the KMS: this key is HMAC key material
 * for equality lookups, not a DEK, and per ADR 0002 is provisioned/rotated independently of any
 * `TaxSubject`'s encryption keys.
 */
final class ConfigBlindIndexKeyProvider implements BlindIndexKeyProvider
{
    private const int KEY_BYTES = 32;

    /** @var array<int, string> version => raw key bytes. */
    private readonly array $keys;

    private readonly int $currentVersion;

    public function __construct(string $keysJson, int $currentVersion)
    {
        $this->keys = self::parseKeys($keysJson);
        $this->currentVersion = $currentVersion;

        if (! isset($this->keys[$currentVersion])) {
            throw new InvalidArgumentException(sprintf(
                'CRYPTO_BLIND_INDEX_CURRENT_VERSION=%d has no matching entry in CRYPTO_BLIND_INDEX_KEYS.',
                $currentVersion,
            ));
        }
    }

    public function keyFor(int $version): string
    {
        if (! isset($this->keys[$version])) {
            throw new InvalidArgumentException(sprintf('No blind-index key registered for version %d.', $version));
        }

        return $this->keys[$version];
    }

    public function currentVersion(): int
    {
        return $this->currentVersion;
    }

    /**
     * @return array<int, string>
     */
    private static function parseKeys(string $keysJson): array
    {
        /**  */
        $decoded = json_decode($keysJson, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded) || $decoded === []) {
            throw new InvalidArgumentException('CRYPTO_BLIND_INDEX_KEYS must be a non-empty JSON object.');
        }

        $keys = [];

        foreach ($decoded as $version => $base64Key) {
            $versionInt = filter_var($version, FILTER_VALIDATE_INT);

            if ($versionInt === false || ! is_string($base64Key)) {
                throw new InvalidArgumentException('CRYPTO_BLIND_INDEX_KEYS must map integer versions to base64 strings.');
            }

            $key = base64_decode($base64Key, true);

            if ($key === false || strlen($key) !== self::KEY_BYTES) {
                throw new InvalidArgumentException(sprintf(
                    'CRYPTO_BLIND_INDEX_KEYS version %d must decode to exactly %d bytes.',
                    $versionInt,
                    self::KEY_BYTES,
                ));
            }

            $keys[$versionInt] = $key;
        }

        return $keys;
    }
}
