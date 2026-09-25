<?php

declare(strict_types=1);

namespace Kern\Platform\KeyManagement\Contracts;

use Kern\Platform\KeyManagement\Exceptions\KeyManagementException;
use Kern\Platform\KeyManagement\ValueObjects\WrappedDataKey;

/**
 * Wraps and unwraps per-`TaxSubject` Data Encryption Keys against a Key Encryption Key held
 * outside the application (OpenBao's transit engine — docs/adr/0002-envelope-encryption.md).
 *
 * A plaintext DEK passed to `wrapDataKey()` or returned by `unwrapDataKey()` must never be
 * logged, cached beyond the caller's own request-scoped use, serialised into a queue payload,
 * or included in any exception. Implementations must not embed it in the exception message
 * on failure — see `KeyManagementException`.
 */
interface KeyManagementService
{
    /**
     * Wrap a freshly generated, raw 256-bit plaintext DEK under the current KEK version.
     *
     * @param string $plaintextDek raw 32-byte key material — never base64/hex encoded first.
     *
     * @throws KeyManagementException on any transport, auth, or KMS-side failure.
     */
    public function wrapDataKey(string $plaintextDek): WrappedDataKey;

    /**
     * Unwrap a previously wrapped DEK. Must succeed for a `WrappedDataKey` produced under any
     * KEK version the KMS still retains — rotating the KEK (`rotateKek()`) must not break
     * decryption of DEKs wrapped before the rotation.
     *
     * @return string raw 32-byte plaintext key material.
     *
     * @throws KeyManagementException on any transport, auth, or KMS-side failure.
     */
    public function unwrapDataKey(WrappedDataKey $wrapped): string;

    /**
     * The KEK version that `wrapDataKey()` would use right now.
     *
     * @throws KeyManagementException on any transport, auth, or KMS-side failure.
     */
    public function currentKekVersion(): int;

    /**
     * Roll the KEK forward to a new version. Existing wrapped DEKs remain valid — the KMS is
     * expected to retain prior key versions for `unwrapDataKey()` (ADR 0002 "Key rotation is
     * scoped"). Retiring an old KEK version entirely is a separate, deliberate operation this
     * interface does not expose.
     *
     * @return int the new current KEK version.
     *
     * @throws KeyManagementException on any transport, auth, or KMS-side failure.
     */
    public function rotateKek(): int;
}
