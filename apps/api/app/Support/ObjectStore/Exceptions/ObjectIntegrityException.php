<?php

declare(strict_types=1);

namespace App\Support\ObjectStore\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Never constructed with plaintext, key material, ciphertext bytes, or a content hash in the
 * message — BLUEPRINT.md §4.10/§23. The object key (two UUIDs/ULIDs and a fixed kind name) and
 * structural counters (chunk index, expected/actual counts) are safe: they identify *which*
 * object and *where* verification failed, never its content.
 */
final class ObjectIntegrityException extends RuntimeException
{
    public static function malformedManifest(): self
    {
        return new self('Object manifest is malformed (not valid JSON or missing required fields).');
    }

    public static function manifestAuthenticationFailed(string $objectKey): self
    {
        return new self(sprintf(
            'Manifest for object "%s" failed authentication (wrong key, wrong AAD binding, or tampered).',
            $objectKey,
        ));
    }

    public static function malformedContainer(string $objectKey): self
    {
        return new self(sprintf('Object "%s" container is malformed (bad header or truncated frame).', $objectKey));
    }

    public static function unsupportedFormatVersion(string $objectKey, int $version): self
    {
        return new self(sprintf('Object "%s" container format version %d is not supported.', $objectKey, $version));
    }

    public static function chunkAuthenticationFailed(string $objectKey, int $chunkIndex, ?Throwable $previous = null): self
    {
        return new self(sprintf(
            'Object "%s" chunk %d failed authentication (wrong key, wrong AAD binding, reordered/duplicated chunk, or corrupted data).',
            $objectKey,
            $chunkIndex,
        ), previous: $previous);
    }

    public static function chunkCountMismatch(string $objectKey, int $expected, int $actual): self
    {
        return new self(sprintf(
            'Object "%s" chunk count mismatch: manifest expects %d, container had %d — truncated or extended.',
            $objectKey,
            $expected,
            $actual,
        ));
    }

    public static function sizeMismatch(string $objectKey, int $expectedBytes, int $actualBytes): self
    {
        return new self(sprintf(
            'Object "%s" decrypted size mismatch: manifest expects %d bytes, got %d.',
            $objectKey,
            $expectedBytes,
            $actualBytes,
        ));
    }

    public static function hashMismatch(string $objectKey): self
    {
        return new self(sprintf(
            'Object "%s" decrypted content does not match its manifest SHA-256.',
            $objectKey,
        ));
    }
}
