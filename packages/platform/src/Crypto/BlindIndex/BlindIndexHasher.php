<?php

declare(strict_types=1);

namespace Kern\Platform\Crypto\BlindIndex;

use Kern\Platform\Crypto\BlindIndex\Contracts\BlindIndexKeyProvider;

/**
 * `HMAC-SHA-256(index_key, normalized_value)` (BLUEPRINT.md §4.4). Never a bare
 * `hash('sha256', $value)` — a keyless hash of a low-entropy value (a CNP has ~10^12 possible
 * values, an email far fewer in practice) is trivially reversible by dictionary/rainbow-table
 * attack; HMAC with a secret key is not.
 *
 * Callers must normalise the value first (`Normalizer`) — this class does not normalise on its
 * own, so two equal-after-normalisation values that were not actually normalised the same way
 * will silently hash to different indexes. That is a caller bug, not something this class can
 * detect.
 */
final readonly class BlindIndexHasher
{
    public function __construct(private BlindIndexKeyProvider $keys)
    {
    }

    /**
     * @return string lowercase hex-encoded HMAC-SHA-256, computed under the current key version.
     */
    public function compute(string $normalizedValue): string
    {
        return hash_hmac('sha256', $normalizedValue, $this->keys->keyFor($this->keys->currentVersion()));
    }

    /**
     * Compute under a specific historical key version — used only while re-indexing during a
     * blind-index key rotation (ADR 0002: "a real batch job, not a config flag flip"), to
     * recognise rows still indexed under the outgoing version.
     */
    public function computeWithVersion(string $normalizedValue, int $keyVersion): string
    {
        return hash_hmac('sha256', $normalizedValue, $this->keys->keyFor($keyVersion));
    }
}
