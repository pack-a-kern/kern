<?php

declare(strict_types=1);

namespace Tests\Unit\Documents\ObjectStore\ObjectManifestTest;

use Kern\Documents\ObjectStore\Exceptions\ObjectIntegrityException;
use Kern\Documents\ObjectStore\ValueObjects\ObjectManifest;

it('round-trips through JSON', function (): void {
    $manifest = new ObjectManifest(
        sha256: hash('sha256', 'some content'),
        sizeBytes: 12,
        chunkSize: 4 * 1024 * 1024,
        chunkCount: 1,
        formatVersion: 1,
    );

    $decoded = ObjectManifest::fromJson($manifest->toJson());

    expect($decoded->sha256)->toBe($manifest->sha256)
        ->and($decoded->sizeBytes)->toBe($manifest->sizeBytes)
        ->and($decoded->chunkSize)->toBe($manifest->chunkSize)
        ->and($decoded->chunkCount)->toBe($manifest->chunkCount)
        ->and($decoded->formatVersion)->toBe($manifest->formatVersion);
});

it('rejects invalid JSON', function (): void {
    ObjectManifest::fromJson('{not json');
})->throws(ObjectIntegrityException::class);

it('rejects JSON missing a required field', function (): void {
    ObjectManifest::fromJson((string) json_encode(['sha256' => str_repeat('a', 64), 'size_bytes' => 1]));
})->throws(ObjectIntegrityException::class);

it('rejects a field of the wrong type', function (): void {
    ObjectManifest::fromJson((string) json_encode([
        'sha256' => str_repeat('a', 64),
        'size_bytes' => '12', // string, not int
        'chunk_size' => 4194304,
        'chunk_count' => 1,
        'format_version' => 1,
    ]));
})->throws(ObjectIntegrityException::class);

it('rejects a JSON array instead of an object', function (): void {
    ObjectManifest::fromJson('[1,2,3]');
})->throws(ObjectIntegrityException::class);
