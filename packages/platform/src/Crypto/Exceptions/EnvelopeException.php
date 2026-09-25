<?php

declare(strict_types=1);

namespace Kern\Platform\Crypto\Exceptions;

use RuntimeException;

/**
 * Never constructed with plaintext, key material, or raw ciphertext bytes in the message —
 * BLUEPRINT.md §4.10 (no secrets in exception messages) and §23 (no PII in logs, which catch an
 * uncaught exception's message). A field's AAD descriptor (table/field/record id) is safe to
 * include: those are structural identifiers, never the field's own value.
 */
final class EnvelopeException extends RuntimeException
{
    public static function invalidKeyLength(int $expectedBytes): self
    {
        return new self(sprintf('Envelope key must be exactly %d bytes.', $expectedBytes));
    }

    public static function malformedEnvelope(): self
    {
        return new self('Ciphertext envelope is malformed (bad base64, header, or length).');
    }

    public static function unsupportedFormatVersion(int $version): self
    {
        return new self(sprintf('Ciphertext envelope format version %d is not supported.', $version));
    }

    public static function unsupportedAlgorithm(int $algorithmId): self
    {
        return new self(sprintf('Ciphertext envelope algorithm id %d is not supported.', $algorithmId));
    }

    /**
     * Thrown when the AEAD tag fails to verify — wrong key, wrong AAD (including the AAD-binding
     * cases this module exists to defend: ciphertext moved to another row/column/table/subject),
     * or corrupted ciphertext. Deliberately does not distinguish which, to avoid giving an
     * attacker an oracle for which part of the binding was wrong.
     */
    public static function authenticationFailed(): self
    {
        return new self('Ciphertext envelope failed authentication (wrong key, wrong AAD binding, or corrupted data).');
    }
}
