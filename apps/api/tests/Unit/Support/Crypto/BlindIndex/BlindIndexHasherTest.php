<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Crypto\BlindIndex\BlindIndexHasherTest;

use App\Support\Crypto\BlindIndex\BlindIndexHasher;
use App\Support\Crypto\BlindIndex\ConfigBlindIndexKeyProvider;
use App\Support\Crypto\BlindIndex\Normalizer;
use InvalidArgumentException;

function keysJson(int ...$versions): string
{
    $keys = [];

    foreach ($versions as $version) {
        $keys[(string) $version] = base64_encode(random_bytes(32));
    }

    return json_encode($keys, JSON_THROW_ON_ERROR);
}

it('is deterministic for the same normalized value and key version', function (): void {
    $provider = new ConfigBlindIndexKeyProvider(keysJson(1), currentVersion: 1);
    $hasher = new BlindIndexHasher($provider);

    $value = Normalizer::cnp('1 900101 234567');

    expect($hasher->compute($value))->toBe($hasher->compute($value));
});

it('produces a different index for a different normalized value', function (): void {
    $provider = new ConfigBlindIndexKeyProvider(keysJson(1), currentVersion: 1);
    $hasher = new BlindIndexHasher($provider);

    expect($hasher->compute('1900101234567'))->not->toBe($hasher->compute('1900101234568'));
});

it('is not a bare hash — two different keys produce two different indexes for the same value', function (): void {
    $providerA = new ConfigBlindIndexKeyProvider(keysJson(1), currentVersion: 1);
    $providerB = new ConfigBlindIndexKeyProvider(keysJson(1), currentVersion: 1);

    $indexA = (new BlindIndexHasher($providerA))->compute('1900101234567');
    $indexB = (new BlindIndexHasher($providerB))->compute('1900101234567');

    expect($indexA)->not->toBe($indexB)
        ->and($indexA)->not->toBe(hash('sha256', '1900101234567'));
});

it('computes under a specific historical version for rotation re-indexing', function (): void {
    $provider = new ConfigBlindIndexKeyProvider(keysJson(1, 2), currentVersion: 2);
    $hasher = new BlindIndexHasher($provider);

    $underV1 = $hasher->computeWithVersion('value', 1);
    $underV2 = $hasher->computeWithVersion('value', 2);
    $currentDefault = $hasher->compute('value');

    expect($underV1)->not->toBe($underV2)
        ->and($currentDefault)->toBe($underV2);
});

it('rejects a current version with no matching key', function (): void {
    new ConfigBlindIndexKeyProvider(keysJson(1), currentVersion: 2);
})->throws(InvalidArgumentException::class);

it('rejects a key that does not decode to 32 bytes', function (): void {
    new ConfigBlindIndexKeyProvider(json_encode(['1' => base64_encode('too-short')], JSON_THROW_ON_ERROR), currentVersion: 1);
})->throws(InvalidArgumentException::class);
