<?php

declare(strict_types=1);

namespace Kern\Platform\Crypto\BlindIndex;

use Kern\Platform\KeyManagement\Contracts\KeyManagementService;

/**
 * `HMAC-SHA-256(index_key, normalized_value)` (BLUEPRINT.md §4.4). Never a bare
 * `hash('sha256', $value)` — a keyless hash of a low-entropy value (a CNP has some 10^10 possible
 * values, an email far fewer in practice) is trivially reversible by dictionary attack; HMAC with a
 * secret key is not.
 *
 * The key is held by the KMS and computed with there (docs/adr/0020-blind-index-key-in-the-kms.md).
 * It used to sit in the environment file, so a copy of the database and of that file was enough to
 * enumerate every CNP offline — the key was secret, but it was secret in the same place as everything
 * else. This class is still the only way a blind index is made, so that move changed one class.
 *
 * Callers must normalise the value first (`Normalizer`) — this class does not normalise on its
 * own, so two equal-after-normalisation values that were not actually normalised the same way
 * will silently hash to different indexes. That is a caller bug, not something this class can
 * detect.
 */
final readonly class BlindIndexHasher
{
    public function __construct(private KeyManagementService $kms)
    {
    }

    /**
     * @return string lowercase hex-encoded HMAC-SHA-256, computed under the current key version.
     */
    public function compute(string $normalizedValue): string
    {
        return $this->computeMany([$normalizedValue])[0];
    }

    /**
     * Many at once, in one request to the KMS — for a caller indexing a whole batch, such as an import.
     *
     * @param list<string> $normalizedValues
     *
     * @return list<string> in the same order.
     */
    public function computeMany(array $normalizedValues): array
    {
        return $this->kms->blindIndexes($normalizedValues);
    }

    /**
     * Like {@see computeMany()}, for a list where some entries have nothing to index: a null stays a
     * null in its place, and everything else is computed in the same single request. One place for
     * the "compute the present ones, put them back in order" step, which every batch caller needs.
     *
     * @param list<?string> $normalizedValues
     *
     * @return list<?string> in the same order.
     */
    public function computeEach(array $normalizedValues): array
    {
        $indexes = $this->computeMany(array_values(array_filter($normalizedValues, is_string(...))));
        $next = 0;

        return array_map(static function (?string $value) use ($indexes, &$next): ?string {
            return $value === null ? null : $indexes[$next++];
        }, $normalizedValues);
    }

    /** The key version every index computed now is under. */
    public function currentVersion(): int
    {
        return $this->kms->currentBlindIndexKeyVersion();
    }
}
