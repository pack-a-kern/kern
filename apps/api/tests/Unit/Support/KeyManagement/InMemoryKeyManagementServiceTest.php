<?php

declare(strict_types=1);

namespace Tests\Unit\Support\KeyManagement\InMemoryKeyManagementServiceTest;

use Kern\Platform\KeyManagement\Exceptions\KeyManagementException;
use Kern\Platform\KeyManagement\ValueObjects\WrappedDataKey;
use Tests\Fakes\InMemoryKeyManagementService;

it('round-trips a wrapped DEK', function (): void {
    $kms = new InMemoryKeyManagementService();
    $dek = random_bytes(32);

    $wrapped = $kms->wrapDataKey($dek);

    expect($kms->unwrapDataKey($wrapped))->toBe($dek);
});

it('wraps under the current KEK version', function (): void {
    $kms = new InMemoryKeyManagementService();

    $wrapped = $kms->wrapDataKey(random_bytes(32));

    expect($wrapped->kekVersion)->toBe(1)
        ->and($kms->currentKekVersion())->toBe(1);
});

it('rotating the KEK: old wrapped DEKs still unwrap, new wraps carry the new version', function (): void {
    $kms = new InMemoryKeyManagementService();
    $dek = random_bytes(32);

    $wrappedBeforeRotation = $kms->wrapDataKey($dek);
    expect($wrappedBeforeRotation->kekVersion)->toBe(1);

    $newVersion = $kms->rotateKek();
    expect($newVersion)->toBe(2)
        ->and($kms->currentKekVersion())->toBe(2);

    // Old row: still decrypts under the version it was wrapped with, after rotation.
    expect($kms->unwrapDataKey($wrappedBeforeRotation))->toBe($dek);

    // New row: freshly wrapped DEK now carries the new version.
    $wrappedAfterRotation = $kms->wrapDataKey(random_bytes(32));
    expect($wrappedAfterRotation->kekVersion)->toBe(2);
});

it('rejects an unrecognised ciphertext format', function (): void {
    $kms = new InMemoryKeyManagementService();

    $wrapped = $kms->wrapDataKey(random_bytes(32));
    $tampered = new WrappedDataKey('not-a-recognised-format', $wrapped->kekVersion);

    $kms->unwrapDataKey($tampered);
})->throws(KeyManagementException::class);
