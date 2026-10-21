<?php

declare(strict_types=1);

namespace Kern\Platform\KeyManagement;

use Kern\Platform\KeyManagement\Contracts\KeyManagementService;
use Kern\Platform\KeyManagement\Contracts\SubjectDekRepository;
use SodiumException;

/**
 * Per-`TaxSubject` Data Encryption Key lifecycle (docs/adr/0002-envelope-encryption.md).
 *
 * On first use for a subject, generates a random 256-bit DEK, wraps it via
 * {@see KeyManagementService}, and persists only the wrapped form through
 * {@see SubjectDekRepository} — the plaintext DEK is never written to any store this class
 * controls. On every subsequent use, the wrapped DEK is fetched and unwrapped.
 *
 * The unwrapped plaintext DEK is cached **only** in this instance's own memory, for its own
 * lifetime. This class must be bound as a scoped (per-request) container instance — never a
 * process-lifetime singleton — so the cache cannot outlive one HTTP request or job execution.
 * See docs/handoff/005-crypto-core.md for the exact container-binding requirement, since this
 * module intentionally does not register that binding itself (no `tax_subjects` table exists
 * yet to bind a concrete `SubjectDekRepository` against).
 */
final class SubjectDekService
{
    /** @var array<string, string> subjectId => raw 32-byte plaintext DEK, this instance only. */
    private array $cache = [];

    public function __construct(
        private readonly KeyManagementService $kms,
        private readonly SubjectDekRepository $repository,
    ) {
    }

    /**
     * @param string $subjectId the `TaxSubject` UUIDv7/ULID.
     *
     * @return string raw 32-byte plaintext DEK. Callers must not log, cache beyond the current
     *                 request, or otherwise persist this value.
     *
     * @throws SodiumException if the platform cannot produce secure random bytes.
     */
    public function plaintextDekFor(string $subjectId): string
    {
        if (isset($this->cache[$subjectId])) {
            return $this->cache[$subjectId];
        }

        $wrapped = $this->repository->find($subjectId);

        if ($wrapped === null) {
            $plaintextDek = self::generateDek();
            $this->repository->save($subjectId, $this->kms->wrapDataKey($plaintextDek));

            return $this->cache[$subjectId] = $plaintextDek;
        }

        return $this->cache[$subjectId] = $this->kms->unwrapDataKey($wrapped);
    }

    private static function generateDek(): string
    {
        return random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    }
}
