<?php

declare(strict_types=1);

namespace Kern\Platform\ObjectStore\ValueObjects;

use JsonException;
use Kern\Platform\ObjectStore\Exceptions\ObjectIntegrityException;

/**
 * The integrity record for one stored object (BLUEPRINT.md §4.9): the SHA-256 of the original
 * plaintext, computed before encryption, plus the framing facts ({@see $chunkSize}/
 * {@see $chunkCount}) the reader needs to detect truncation, reordering, or duplication of
 * chunks. Stored encrypted alongside the content object — see
 * `Kern\Platform\ObjectStore\ObjectStoreAssociatedData::forManifest()` — never trusted from an
 * unauthenticated source.
 */
final readonly class ObjectManifest
{
    public function __construct(
        public string $sha256,
        public int $sizeBytes,
        public int $chunkSize,
        public int $chunkCount,
        public int $formatVersion,
    ) {
    }

    public function toJson(): string
    {
        return json_encode([
            'sha256' => $this->sha256,
            'size_bytes' => $this->sizeBytes,
            'chunk_size' => $this->chunkSize,
            'chunk_count' => $this->chunkCount,
            'format_version' => $this->formatVersion,
        ], JSON_THROW_ON_ERROR);
    }

    public static function fromJson(string $json): self
    {
        try {
            /**  */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ObjectIntegrityException::malformedManifest();
        }

        if (
            ! is_array($decoded)
            || ! is_string($decoded['sha256'] ?? null)
            || ! is_int($decoded['size_bytes'] ?? null)
            || ! is_int($decoded['chunk_size'] ?? null)
            || ! is_int($decoded['chunk_count'] ?? null)
            || ! is_int($decoded['format_version'] ?? null)
        ) {
            throw ObjectIntegrityException::malformedManifest();
        }

        return new self(
            $decoded['sha256'],
            $decoded['size_bytes'],
            $decoded['chunk_size'],
            $decoded['chunk_count'],
            $decoded['format_version'],
        );
    }
}
