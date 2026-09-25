<?php

declare(strict_types=1);

namespace Kern\Platform\Crypto\BlindIndex\Contracts;

/**
 * Source of the single global, versioned blind-index HMAC key (docs/adr/0002-envelope-encryption.md
 * "Two distinct key classes" — this key is deliberately not per-`TaxSubject`, because
 * login-by-email and duplicate-CNP detection are inherently cross-tenant lookups).
 */
interface BlindIndexKeyProvider
{
    /**
     * @return string raw key bytes for the given version. Implementations must reject an
     *                 unknown version rather than substituting the current one.
     */
    public function keyFor(int $version): string;

    public function currentVersion(): int;
}
